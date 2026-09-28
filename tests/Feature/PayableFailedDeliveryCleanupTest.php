<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderPackage;
use App\Models\PayableEvent;
use App\Models\PayablePeriod;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PayableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayableFailedDeliveryCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_removes_only_failed_delivery_events_without_a_failed_package(): void
    {
        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'platform' => 'Tiktokshop',
            'store_name' => 'Test Store',
            'platform_shop_id' => 'SHOP-1',
        ]);
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Test Supplier',
        ]);
        $period = PayablePeriod::withoutEvents(fn () => PayablePeriod::create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'name' => 'Test Period',
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
        ]));

        $recoveredOrder = $this->createOrder($store, 'RECOVERED-ORDER');
        $failedOrder = $this->createOrder($store, 'FAILED-ORDER');

        OrderPackage::withoutEvents(fn () => OrderPackage::create([
            'order_id' => $recoveredOrder->id,
            'platform' => 'Tiktokshop',
            'package_id' => 'RECOVERED-PACKAGE',
            'normalized_logistics_status' => 'DELIVERED',
        ]));
        OrderPackage::withoutEvents(fn () => OrderPackage::create([
            'order_id' => $failedOrder->id,
            'platform' => 'Tiktokshop',
            'package_id' => 'FAILED-PACKAGE',
            'normalized_logistics_status' => 'DELIVERY_FAILED',
        ]));

        foreach ([$recoveredOrder, $failedOrder] as $order) {
            PayableEvent::withoutEvents(fn () => PayableEvent::create([
                'user_id' => $user->id,
                'supplier_id' => $supplier->id,
                'payable_period_id' => $period->id,
                'store_id' => $store->id,
                'platform' => 'Tiktokshop',
                'source_id' => $order->order_sn,
                'source_type' => 'FAILED_DELIVERY',
                'event_date' => now(),
                'amount' => -10000,
            ]));
        }

        $removed = app(PayableService::class)->cleanupStaleFailedDeliveryEvents($user->id);

        $this->assertSame(1, $removed);
        $this->assertDatabaseMissing('payable_events', [
            'source_id' => $recoveredOrder->order_sn,
            'source_type' => 'FAILED_DELIVERY',
        ]);
        $this->assertDatabaseHas('payable_events', [
            'source_id' => $failedOrder->order_sn,
            'source_type' => 'FAILED_DELIVERY',
        ]);
    }

    private function createOrder(Store $store, string $orderSn): Order
    {
        return Order::withoutEvents(fn () => Order::create([
            'store_id' => $store->id,
            'platform' => 'Tiktokshop',
            'order_sn' => $orderSn,
            'order_status' => 'DELIVERED',
            'order_time' => now(),
        ]));
    }
}
