<?php

namespace Tests\Feature;

use App\Jobs\PrepareBulkShippingLabelsJob;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Store;
use App\Models\User;
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
