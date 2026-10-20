<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PayableEvent;
use App\Models\PayablePayment;
use App\Models\PayablePeriod;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayableAllSuppliersTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_suppliers_view_contains_every_open_unpaid_or_partial_period(): void
    {
        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'platform' => 'Shopee',
            'store_name' => 'Payable Test Store',
            'shopee_shop_id' => 'PAYABLE-ALL-SUPPLIERS',
        ]);
        $supplierA = Supplier::create(['user_id' => $user->id, 'name' => 'Supplier A']);
        $supplierB = Supplier::create(['user_id' => $user->id, 'name' => 'Supplier B']);

        $paidPeriod = $this->createPeriod($user, $supplierA, 'Paid', 'PAID', '2026-09-01');
        $partialPeriod = $this->createPeriod($user, $supplierA, 'Partial', 'PARTIAL', '2026-09-15');
        $unpaidPeriod = $this->createPeriod($user, $supplierB, 'Unpaid', 'UNPAID', '2026-09-29');
        $closedPeriod = $this->createPeriod($user, $supplierB, 'Closed', 'UNPAID', '2026-10-13', true);

        $this->createOrder($store, 'PAID-ORDER');
        $this->createOrder($store, 'PARTIAL-ORDER');
        $this->createOrder($store, 'UNPAID-ORDER');
        $this->createOrder($store, 'CLOSED-ORDER');

        $this->createEvent($user, $supplierA, $paidPeriod, 'PAID-ORDER', 'CREATE_ORDER', 100000);
        $this->createEvent($user, $supplierA, $partialPeriod, 'PARTIAL-ORDER', 'CREATE_ORDER', 120000);
        $this->createEvent($user, $supplierA, $partialPeriod, 'PARTIAL-ORDER', 'RETURN_ORDER', -20000);
        $this->createEvent($user, $supplierB, $unpaidPeriod, 'UNPAID-ORDER', 'CREATE_ORDER', 80000);
        $this->createEvent($user, $supplierB, $closedPeriod, 'CLOSED-ORDER', 'CREATE_ORDER', 90000);

        PayablePayment::withoutEvents(fn () => PayablePayment::create([
            'user_id' => $user->id,
            'supplier_id' => $supplierA->id,
            'payable_period_id' => $partialPeriod->id,
            'payment_date' => '2026-09-20 10:00:00',
            'amount' => 50000,
            'payment_method' => 'Transfer',
        ]));

        $response = $this->actingAs($user)
            ->getJson('/api/payable/periods/latest/details')
            ->assertOk()
            ->assertJsonPath('data.period.name', 'Tagihan Belum Lunas (Semua Supplier)')
            ->assertJsonPath('data.period.total_debt', 200000)
            ->assertJsonPath('data.period.total_reduction', -20000)
            ->assertJsonPath('data.period.net_payable', 180000)
            ->assertJsonPath('data.period.total_paid', 50000)
            ->assertJsonCount(3, 'data.events')
            ->assertJsonCount(1, 'data.payments');

        $sources = collect($response->json('data.events'))->pluck('source_id');

        $this->assertEqualsCanonicalizing(
            ['PARTIAL-ORDER', 'PARTIAL-ORDER', 'UNPAID-ORDER'],
            $sources->all(),
        );
        $this->assertFalse($sources->contains('PAID-ORDER'));
        $this->assertFalse($sources->contains('CLOSED-ORDER'));
    }

    private function createPeriod(
        User $user,
        Supplier $supplier,
        string $name,
        string $status,
        string $start,
        bool $isClosed = false,
    ): PayablePeriod {
        return PayablePeriod::withoutEvents(fn () => PayablePeriod::create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'name' => $name,
            'start_date' => $start.' 00:00:00',
            'end_date' => date('Y-m-d H:i:s', strtotime($start.' +13 days 23 hours 59 minutes 59 seconds')),
            'payment_status' => $status,
            'is_closed' => $isClosed,
            'is_manual' => false,
        ]));
    }

    private function createOrder(Store $store, string $orderSn): void
    {
        Order::withoutEvents(fn () => Order::create([
            'store_id' => $store->id,
            'platform' => 'Shopee',
            'order_sn' => $orderSn,
            'order_status' => 'COMPLETED',
            'order_time' => '2026-09-01 10:00:00',
        ]));
    }

    private function createEvent(
        User $user,
        Supplier $supplier,
        PayablePeriod $period,
        string $sourceId,
        string $sourceType,
        int $amount,
    ): void {
        PayableEvent::withoutEvents(fn () => PayableEvent::create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'payable_period_id' => $period->id,
            'platform' => 'Shopee',
            'source_id' => $sourceId,
            'source_type' => $sourceType,
            'event_date' => $period->start_date,
            'amount' => $amount,
        ]));
    }
}
