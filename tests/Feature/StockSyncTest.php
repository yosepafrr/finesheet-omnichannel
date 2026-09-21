<?php

namespace Tests\Feature;

use App\Events\OrderStockSyncRequested;
use App\Jobs\SyncStockToMarketplaceJob;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\SkuSyncGroup;
use App\Models\SkuSyncMember;
use App\Models\Store;
use App\Models\User;
use App\Models\VariantProduct;
use App\Services\ShopeeService;
use App\Services\StockSyncService;
use App\Services\TiktokService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StockSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_setting_master_stock_queues_members_without_prematurely_updating_local_stock(): void
    {
        Queue::fake();
        [, $variant, $group, $member] = $this->createShopeeSyncMember();

        $queuedCount = app(StockSyncService::class)->setMasterStock($group, 15);

        $this->assertSame(1, $queuedCount);
        $this->assertSame(7, $variant->fresh()->stock);
        $this->assertSame('pending', $member->fresh()->sync_status);

        Queue::assertPushedOn('products', SyncStockToMarketplaceJob::class);
        Queue::assertPushed(SyncStockToMarketplaceJob::class, function ($job) use ($member) {
            return $job->memberId === $member->id && $job->stock === 15;
        });
    }

    public function test_successful_stock_job_updates_local_stock_and_sync_status(): void
    {
        [, $variant, $group, $member] = $this->createShopeeSyncMember();
        $group->update(['master_stock' => 15]);
        $member->update(['sync_status' => 'pending']);

        $shopee = Mockery::mock(ShopeeService::class);
        $shopee->shouldReceive('updateStock')
            ->once()
            ->withArgs(fn ($store, $productId, $modelId, $stock) => $store->id === $member->store_id
                && $productId === '456'
                && $modelId === '789'
                && $stock === 15)
            ->andReturn(['error' => '', 'response' => ['failure_list' => []]]);

        $tiktok = Mockery::mock(TiktokService::class);
        $tiktok->shouldNotReceive('updateInventory');

        (new SyncStockToMarketplaceJob($member->id, 15))->handle($shopee, $tiktok);

        $this->assertSame(15, $variant->fresh()->stock);
        $this->assertSame('synced', $member->fresh()->sync_status);
        $this->assertNotNull($member->fresh()->last_synced_at);
        $this->assertNull($member->fresh()->last_sync_error);
    }

    public function test_failed_stock_job_keeps_local_stock_and_records_marketplace_error(): void
    {
        [, $variant, $group, $member] = $this->createShopeeSyncMember();
        $group->update(['master_stock' => 15]);
        $member->update(['sync_status' => 'pending']);

        $shopee = Mockery::mock(ShopeeService::class);
        $shopee->shouldReceive('updateStock')
            ->once()
            ->andThrow(new RuntimeException('Shopee menolak pembaruan stok: invalid model id'));

        $tiktok = Mockery::mock(TiktokService::class);

        try {
            (new SyncStockToMarketplaceJob($member->id, 15))->handle($shopee, $tiktok);
            $this->fail('The stock job should rethrow marketplace errors.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('invalid model id', $exception->getMessage());
        }

        $this->assertSame(7, $variant->fresh()->stock);
        $this->assertSame('failed', $member->fresh()->sync_status);
        $this->assertStringContainsString('invalid model id', $member->fresh()->last_sync_error);
    }

    public function test_ready_order_deducts_stock_once_after_its_items_exist(): void
    {
        Queue::fake();
        [$product, , $group] = $this->createShopeeSyncMember();
        $store = $product->store;

        $order = Order::withoutEvents(fn () => Order::create([
            'store_id' => $store->id,
            'platform' => 'Shopee',
            'order_sn' => 'ORDER-1',
            'order_status' => 'READY_TO_SHIP',
            'order_time' => now(),
        ]));

        OrderProduct::withoutEvents(fn () => OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->product_id,
            'product_name' => $product->product_name,
            'model_name' => 'Black',
            'quantity_purchased' => 2,
        ]));

        event(new OrderStockSyncRequested($order));
        event(new OrderStockSyncRequested($order->fresh()));

        $this->assertSame(5, $group->fresh()->master_stock);
        $this->assertNotNull($order->fresh()->stock_sync_processed_at);
        Queue::assertPushed(SyncStockToMarketplaceJob::class, 1);
    }

    private function createShopeeSyncMember(): array
    {
        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'platform' => 'Shopee',
            'store_name' => 'Test Store',
            'shopee_shop_id' => '123456',
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'platform' => 'Shopee',
            'product_id' => 456,
            'product_name' => 'Test Product',
            'product_sku' => 'SKU-TEST',
            'stock' => 7,
        ]);
        $variant = VariantProduct::create([
            'product_id' => $product->id,
            'model_id' => 789,
            'model_name' => 'Black',
            'model_sku' => 'SKU-TEST',
            'stock' => 7,
        ]);
        $group = SkuSyncGroup::create([
            'user_id' => $user->id,
            'sku' => 'SKU-TEST',
            'master_stock' => 7,
            'is_active' => true,
        ]);
        $member = SkuSyncMember::create([
            'sku_sync_group_id' => $group->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'variant_product_id' => $variant->id,
            'platform_product_id' => '456',
            'platform_variant_id' => '789',
        ]);

        return [$product, $variant, $group, $member];
    }
}
