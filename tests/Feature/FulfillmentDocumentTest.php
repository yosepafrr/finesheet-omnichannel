<?php

namespace Tests\Feature;

use App\Jobs\PrepareBulkShippingLabelsJob;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderFulfillmentService;
use App\Services\AccessControlService;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FulfillmentDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_label_preparation_is_queued_on_the_fulfillment_queue(): void
    {
        Queue::fake();
        [$user, $orders] = $this->processedOrders(2);

        $response = $this->actingAs($user)->postJson('/api/orders/fulfillment/shipping-label-jobs', [
            'order_ids' => $orders->pluck('id')->all(),
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('progress', 0)
            ->assertJsonStructure(['token', 'status_url', 'download_url']);

        Queue::assertPushedOn('fulfillment', PrepareBulkShippingLabelsJob::class, function ($job) use ($user, $orders) {
            return $job->userId === $user->id
                && $job->orderIds === $orders->pluck('id')->all();
        });
    }

    public function test_processed_orders_can_be_exported_as_a_picking_list_pdf(): void
    {
        [$user, $orders] = $this->processedOrders(2);

        foreach ($orders as $index => $order) {
            OrderProduct::withoutEvents(fn () => OrderProduct::create([
                'order_id' => $order->id,
                'product_id' => 'PRODUCT-'.($index + 1),
                'product_name' => 'Produk Picking '.($index + 1),
                'model_name' => 'Hitam, L',
                'platform_variant_id' => 'VARIANT-'.($index + 1),
                'sku' => 'SKU-'.($index + 1),
                'quantity_purchased' => $index + 1,
                'price' => 100000,
            ]));
        }

        $response = $this->actingAs($user)->post('/api/orders/fulfillment/picking-list', [
            'order_ids' => $orders->pluck('id')->all(),
        ]);

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_picking_list_rejects_orders_that_have_not_been_processed(): void
    {
        [$user, $orders] = $this->processedOrders(1, 'READY_TO_SHIP');

        $this->actingAs($user)->postJson('/api/orders/fulfillment/picking-list', [
            'order_ids' => $orders->pluck('id')->all(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order_ids');
    }

    public function test_fulfillment_capabilities_expose_the_finesheet_print_history(): void
    {
        [, $orders] = $this->processedOrders(1);
        $printedAt = now()->startOfSecond();
        $order = $orders->first();
        $order->forceFill(['shipping_label_printed_at' => $printedAt])->saveQuietly();

        $capabilities = app(OrderFulfillmentService::class)->capabilities($order->fresh());

        $this->assertTrue($capabilities['label_printed']);
        $this->assertSame($printedAt->toIso8601String(), $capabilities['label_printed_at']);
    }

    public function test_manual_print_status_can_be_marked_and_cleared_without_contacting_marketplace(): void
    {
        Http::fake();
        [$user, $orders] = $this->processedOrders(1);
        $order = $orders->first();
        $url = "/api/orders/{$order->id}/shipping-label-status";
        $this->actingAs($user)->patchJson($url, ['printed' => true])->assertOk()
            ->assertJsonPath('fulfillment.label_printed', true);
        $timestamp = $order->fresh()->shipping_label_printed_at->toIso8601String();
        $this->travel(5)->minutes();
        $this->patchJson($url, ['printed' => true])->assertOk()
            ->assertJsonPath('fulfillment.label_printed_at', $timestamp);
        $this->patchJson($url, ['printed' => false])->assertOk()
            ->assertJsonPath('fulfillment.label_printed', false)
            ->assertJsonPath('fulfillment.label_printed_at', null);
        $this->assertNull($order->fresh()->shipping_label_printed_at);
        $this->assertSame('PROCESSED', $order->fresh()->order_status);
        Http::assertNothingSent();
    }

    public function test_manual_print_status_updates_the_unprinted_order_filter(): void
    {
        [$user, $orders] = $this->processedOrders(1);
        $url = "/api/orders/{$orders->first()->id}/shipping-label-status";
        $filter = '/api/orders?statuses=PROCESSED&shipping_process=processed&shipping_label_print_status=unprinted';
        $this->actingAs($user)->patchJson($url, ['printed' => true])->assertOk();
        $this->getJson($filter)->assertOk()->assertJsonCount(0, 'orders');
        $this->patchJson($url, ['printed' => false])->assertOk();
        $this->getJson($filter)->assertOk()->assertJsonCount(1, 'orders');
    }

    public function test_print_operator_can_change_tiktok_print_status(): void
    {
        [$user, $orders] = $this->processedOrders(1);
        $order = $orders->first();
        $order->forceFill(['platform' => 'Tiktokshop', 'order_status' => 'AWAITING_COLLECTION'])->saveQuietly();
        $profile = $user->accessProfiles()->create([
            'name' => 'Fulfillment', 'layout' => 'order_operations',
            'permissions' => ['orders.view', 'orders.print'], 'is_active' => true,
        ]);
        $this->actingAs($user)->withSession([AccessControlService::SESSION_KEY => $profile->id])
            ->patchJson("/api/orders/{$order->id}/shipping-label-status", ['printed' => true])
            ->assertOk()->assertJsonPath('fulfillment.label_printed', true);
    }

    public function test_manual_print_status_requires_ownership_print_permission_and_explicit_boolean(): void
    {
        [$user, $orders] = $this->processedOrders(1);
        $url = "/api/orders/{$orders->first()->id}/shipping-label-status";
        $this->actingAs(User::factory()->create())->patchJson($url, ['printed' => true])->assertNotFound();
        $profile = $user->accessProfiles()->create([
            'name' => 'Read only', 'layout' => 'read_only',
            'permissions' => ['orders.view'], 'is_active' => true,
        ]);
        $this->actingAs($user)->withSession([AccessControlService::SESSION_KEY => $profile->id])
            ->patchJson($url, ['printed' => true])->assertForbidden();
        $this->withSession([AccessControlService::SESSION_KEY => null])->patchJson($url, [])->assertUnprocessable();
        $this->patchJson($url, ['printed' => 'invalid'])->assertUnprocessable();
        $this->assertNull($orders->first()->fresh()->shipping_label_printed_at);
    }

    private function processedOrders(int $count, string $status = 'PROCESSED'): array
    {
        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'platform' => 'Shopee',
            'store_name' => 'Toko Fulfillment Dokumen',
            'shopee_shop_id' => 'FULFILLMENT-DOCUMENTS',
        ]);
        $orders = collect(range(1, $count))->map(fn (int $number) => Order::withoutEvents(fn () => Order::create([
            'store_id' => $store->id,
            'platform' => 'Shopee',
            'order_sn' => 'DOCUMENT-ORDER-'.$status.'-'.$number,
            'order_status' => $status,
            'order_time' => now()->subMinutes($number),
        ])));

        return [$user, $orders];
    }
}
