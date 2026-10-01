<?php

namespace Tests\Feature;

use App\Jobs\SyncPayableHistoryJob;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PayableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use Tests\TestCase;

class PayableSupplierAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_assignment_queues_payable_sync_without_running_it_in_the_request(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Supplier Cepat',
            'period_length_days' => 14,
            'first_period_start' => '2026-09-01 08:00:00',
        ]);
        $store = Store::create([
            'user_id' => $user->id,
            'platform' => 'Shopee',
            'store_name' => 'Toko Pengujian',
            'shopee_shop_id' => 'SHOP-ASSIGNMENT',
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'platform' => 'Shopee',
            'product_id' => 'PRODUCT-ASSIGNMENT',
            'product_name' => 'Produk Pengujian',
            'product_sku' => 'SKU-ASSIGNMENT',
            'stock' => 10,
            'price' => 100000,
        ]);

        $this->mock(PayableService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('syncPayableForUser');
        });

        $this->actingAs($user)
            ->postJson('/api/payable/suppliers/assign-products', [
                'product_ids' => [$product->id],
                'supplier_id' => $supplier->id,
            ])
            ->assertOk()
            ->assertJsonPath('sync_queued', true);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'supplier_id' => $supplier->id,
        ]);
        Bus::assertDispatched(SyncPayableHistoryJob::class);
    }
}
