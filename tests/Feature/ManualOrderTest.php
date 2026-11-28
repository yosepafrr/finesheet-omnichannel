<?php

namespace Tests\Feature;

use App\Models\MasterProduct;
use App\Models\MasterProductBundleComponent;
use App\Models\MasterProductVariant;
use App\Models\Order;
use App\Models\PayableEvent;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\FinancialAccount;
use App\Models\CashflowTransaction;
use App\Services\AccessControlService;
use App\Services\ManualOrderService;
use App\Services\PayableService;
use App\Services\ProductHppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManualOrderTest extends TestCase
{
    use RefreshDatabase;

    private function catalog(?User $user = null, string $sku = 'MANUAL-BLACK-L'): array
    {
        Queue::fake();
        $user ??= User::factory()->create();
        $supplier = Supplier::create(['user_id' => $user->id, 'name' => 'Supplier '.$sku, 'period_length_days' => 14, 'first_period_start' => now()->subMonth()->startOfDay()]);
        $product = MasterProduct::create(['user_id' => $user->id, 'name' => 'Kemeja '.$sku, 'source' => 'manual', 'status' => 'active']);
        $variant = MasterProductVariant::create(['user_id' => $user->id, 'master_product_id' => $product->id,
            'supplier_id' => $supplier->id, 'sku' => $sku, 'variant_name' => 'Hitam L', 'stock' => 10, 'hpp' => 40000, 'is_active' => true]);

        return [$user, $variant, $supplier];
    }

    private function input(MasterProductVariant $variant): array
    {
        return ['request_key' => (string) Str::uuid(), 'order_time' => now()->subMinute()->toDateTimeString(),
            'buyer_name' => 'Pembeli Offline', 'source' => 'WhatsApp', 'payment_status' => 'PAID',
            'discount' => 10000, 'shipping_fee' => 5000,
            'items' => [['variant_id' => $variant->id, 'quantity' => 2, 'price' => 75000]]];
    }

    public function test_creation_allocates_stock_snapshots_hpp_and_creates_supplier_payable_without_marketplace_store(): void
    {
        [$user, $variant, $supplier] = $this->catalog();
        $input = $this->input($variant);
        $response = $this->actingAs($user)->postJson('/api/manual-orders', $input)->assertCreated();
        $id = $response->json('id');
        $this->assertMatchesRegularExpression('/^MAN[0-9]{6}[A-Z0-9]{5}$/', $response->json('order_sn'));
        $this->assertSame(14, strlen($response->json('order_sn')));
        $this->actingAs($user)->postJson('/api/manual-orders', $input)->assertCreated()->assertJsonPath('id', $id);
        $this->assertSame(1, Order::count());
        $this->assertSame(2, $variant->fresh()->on_order);
        $this->assertSame(10, $variant->fresh()->on_hand);
        $this->assertSame(8, $variant->fresh()->stock);
        $this->assertDatabaseHas('payable_events', ['source_id' => $response->json('order_sn'), 'supplier_id' => $supplier->id, 'amount' => 80000, 'source_type' => 'CREATE_ORDER']);
        $this->assertSame(0, $user->stores()->count());
        $this->assertSame(0, Store::count());
        $this->actingAs($user)->getJson('/api/orders?platform=Manual&shipping_process=processed')->assertOk()
            ->assertJsonCount(1, 'orders')->assertJsonPath('orders.0.escrow_amount', 0)
            ->assertJsonPath('orders.0.revenue_amount', 145000)->assertJsonPath('orders.0.profit_amount', 65000)
            ->assertJsonPath('shipping_process_counts.processed', 1);
        $this->actingAs($user)->getJson('/api/payable/periods/latest/details')->assertOk()->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.platform', 'Manual');
        $this->actingAs($user)->getJson('/api/orders/'.$id)->assertOk()->assertJsonPath('order.financial_breakdown.source', 'manual')->assertJsonPath('order.customer_info.name', 'Pembeli Offline');
        $this->actingAs($user)->getJson('/api/profit-tracker?include_analytics=0')->assertOk()->assertJsonPath('total_escrow_amount', 0);
        $variant->update(['hpp' => 99000, 'supplier_id' => null]);
        $order = Order::findOrFail($id);
        app(PayableService::class)->syncPayableForUser($user->id);
        $this->assertEquals(40000, app(ProductHppService::class)->orderItemHpp($order->orderProducts->first()));
        $this->assertEquals(80000, PayableEvent::where('source_id', $order->order_sn)->sum('amount'));
    }

    public function test_shipping_is_idempotent_and_cannot_be_cancelled_after_shipping(): void
    {
        [$user, $variant] = $this->catalog();
        $order = app(ManualOrderService::class)->create($user->id, $this->input($variant));
        foreach (range(1, 2) as $_) {
            $this->actingAs($user)->patchJson('/api/manual-orders/'.$order->id, ['status' => 'SHIPPED', 'payment_status' => 'PAID'])->assertOk();
        }
        $this->assertSame(8, $variant->fresh()->on_hand);
        $this->assertSame(0, $variant->fresh()->on_order);
        $this->actingAs($user)->patchJson('/api/manual-orders/'.$order->id, ['status' => 'CANCELLED', 'payment_status' => 'PAID'])->assertUnprocessable();
    }

    public function test_cancellation_releases_stock_and_removes_unpaid_supplier_debt(): void
    {
        [$user, $variant] = $this->catalog();
        $order = app(ManualOrderService::class)->create($user->id, $this->input($variant));
        foreach (range(1, 2) as $_) {
            $this->actingAs($user)->patchJson('/api/manual-orders/'.$order->id, ['status' => 'CANCELLED', 'payment_status' => 'PAID'])->assertOk();
        }
        $this->assertSame(10, $variant->fresh()->stock);
        $this->assertSame(0, $variant->fresh()->on_order);
        $this->assertSame(0, PayableEvent::where('source_id', $order->order_sn)->count());
    }

    public function test_paid_supplier_history_is_preserved_and_gets_one_cancellation_credit(): void
    {
        [$user, $variant] = $this->catalog();
        $order = app(ManualOrderService::class)->create($user->id, $this->input($variant));
        $original = PayableEvent::where('source_id', $order->order_sn)->firstOrFail();
        $original->period->update(['payment_status' => 'PAID']);
        foreach (range(1, 2) as $_) {
            app(ManualOrderService::class)->update($order, ['status' => 'CANCELLED', 'payment_status' => 'PAID']);
        }
        $this->assertSame(2, PayableEvent::where('source_id', $order->order_sn)->count());
        $this->assertEquals(0, PayableEvent::where('source_id', $order->order_sn)->sum('amount'));
        $credit = PayableEvent::where('source_id', $order->order_sn)->where('source_type', 'MANUAL_CANCEL')->firstOrFail();
        $this->assertNotEquals($original->payable_period_id, $credit->payable_period_id);
    }

    public function test_bundle_allocates_components_and_snapshots_each_supplier(): void
    {
        [$user, $black, $supplier] = $this->catalog();
        [, $navy, $otherSupplier] = $this->catalog($user, 'MANUAL-NAVY-L');
        $bundle = MasterProductVariant::create(['user_id' => $user->id, 'master_product_id' => $black->master_product_id, 'sku' => '3PCS-MIX', 'stock' => 0, 'is_bundle' => true, 'is_active' => true]);
        MasterProductBundleComponent::create(['bundle_variant_id' => $bundle->id, 'component_variant_id' => $black->id, 'quantity' => 2]);
        MasterProductBundleComponent::create(['bundle_variant_id' => $bundle->id, 'component_variant_id' => $navy->id, 'quantity' => 1]);
        $order = app(ManualOrderService::class)->create($user->id, $this->input($bundle));
        $this->assertSame(4, $black->fresh()->on_order);
        $this->assertSame(2, $navy->fresh()->on_order);
        $this->assertDatabaseHas('payable_events', ['source_id' => $order->order_sn, 'supplier_id' => $supplier->id, 'amount' => 160000]);
        $this->assertDatabaseHas('payable_events', ['source_id' => $order->order_sn, 'supplier_id' => $otherSupplier->id, 'amount' => 80000]);
    }

    public function test_foreign_sku_and_insufficient_stock_cannot_leave_partial_orders(): void
    {
        [$user, $variant] = $this->catalog();
        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/api/manual-orders', $this->input($variant))->assertNotFound();
        $input = $this->input($variant);
        $input['items'][0]['quantity'] = 11;
        $this->actingAs($user)->postJson('/api/manual-orders', $input)->assertUnprocessable();
        $this->assertSame(0, Order::count());
        $this->assertSame(0, PayableEvent::count());
        $this->assertSame(10, $variant->fresh()->stock);
    }

    public function test_manual_orders_require_a_separate_permission_and_cannot_be_changed_by_another_user(): void
    {
        [$user, $variant] = $this->catalog();
        $order = app(ManualOrderService::class)->create($user->id, $this->input($variant));
        $other = User::factory()->create();
        $this->actingAs($other)->patchJson('/api/manual-orders/'.$order->id, ['status' => 'CANCELLED', 'payment_status' => 'UNPAID'])->assertNotFound();
        $profile = $user->accessProfiles()->create(['name' => 'Viewer', 'layout' => 'read_only', 'permissions' => ['orders.view'], 'is_active' => true]);
        $this->actingAs($user)->withSession([AccessControlService::SESSION_KEY => $profile->id])->postJson('/api/manual-orders', $this->input($variant))->assertForbidden();
        $this->getJson('/api/manual-orders/options')->assertForbidden();
    }

    public function test_missing_supplier_configuration_rejects_creation_without_allocating_stock(): void
    {
        [$user, $variant, $supplier] = $this->catalog();
        $supplier->update(['first_period_start' => null]);
        $this->actingAs($user)->postJson('/api/manual-orders', $this->input($variant))->assertUnprocessable();
        $this->assertSame(0, Order::count());
        $this->assertSame(0, $variant->fresh()->on_order);
    }

    public function test_history_rebuild_preserves_explicit_supplier_and_period_moves(): void
    {
        [$user, $variant] = $this->catalog();
        [, , $otherSupplier] = $this->catalog($user, 'OTHER-SUPPLIER');
        $order = app(ManualOrderService::class)->create($user->id, $this->input($variant));
        $event = PayableEvent::where('source_id', $order->order_sn)->firstOrFail();
        $period = app(PayableService::class)->getPeriodForDate($order->order_time, $user->id, $otherSupplier);
        $event->update(['supplier_id' => $otherSupplier->id, 'payable_period_id' => $period->id, 'is_manual_moved' => true]);
        app(PayableService::class)->syncPayableForUser($user->id);
        $this->assertSame(1, PayableEvent::where('source_id', $order->order_sn)->count());
        $this->assertEquals($otherSupplier->id, $event->fresh()->supplier_id);
    }

    public function test_revenue_uses_item_prices_and_not_the_removed_override(): void
    {
        [$user, $variant] = $this->catalog();
        $input = $this->input($variant);
        $input['revenue_amount'] = 165000;
        $response = $this->actingAs($user)->postJson('/api/manual-orders', $input)->assertCreated();
        $this->getJson('/api/orders?platform=Manual')->assertOk()
            ->assertJsonPath('orders.0.revenue_amount', 145000)
            ->assertJsonPath('orders.0.profit_amount', 65000)
            ->assertJsonPath('totals.manual_revenue_amount', 145000)
            ->assertJsonPath('totals.escrow_amount', 0);
        $order = Order::findOrFail($response->json('id'));
        $raw = $order->raw_data;
        $raw['revenue_amount'] = 999999;
        $order->forceFill(['raw_data' => $raw])->saveQuietly();
        $this->assertEquals(145000, ManualOrderService::revenueAmount($order));
    }

    private function account(User $user, string $name = 'BCA'): FinancialAccount
    {
        return FinancialAccount::create(['user_id' => $user->id, 'name' => $name, 'category' => 'cash', 'subtype' => 'bank', 'balance' => 1000]);
    }

    public function test_optional_tracking_can_be_created_updated_and_cleared_without_changing_inventory(): void
    {
        [$user, $variant] = $this->catalog();
        $response = $this->actingAs($user)->postJson('/api/manual-orders', [
            ...$this->input($variant), 'shipping_provider' => 'J&T Express', 'tracking_number' => 'JY123456789',
        ])->assertCreated();
        $id = $response->json('id');
        $this->getJson('/api/orders?platform=Manual')->assertOk()->assertJsonPath('orders.0.tracking_number', 'JY123456789')->assertJsonPath('orders.0.shipping_provider', 'J&T Express');
        $this->getJson('/api/orders/'.$id)->assertOk()->assertJsonPath('order.shipping_info.tracking_number', 'JY123456789');
        $this->patchJson('/api/manual-orders/'.$id, ['status' => 'PROCESSED', 'payment_status' => 'PAID', 'tracking_number' => 'SPX987654321', 'shipping_provider' => 'SPX'])->assertOk();
        $this->getJson('/api/orders/'.$id)->assertOk()->assertJsonPath('order.shipping_info.tracking_number', 'SPX987654321')->assertJsonPath('order.shipping_info.provider', 'SPX');
        $this->patchJson('/api/manual-orders/'.$id, ['status' => 'PROCESSED', 'payment_status' => 'PAID', 'tracking_number' => null, 'shipping_provider' => null])->assertOk();
        $this->getJson('/api/orders?platform=Manual')->assertOk()->assertJsonPath('orders.0.tracking_number', null);
        $this->assertSame(2, $variant->fresh()->on_order);
        $this->assertSame(10, $variant->fresh()->on_hand);
        $this->postJson('/api/manual-orders', [...$this->input($variant), 'tracking_number' => str_repeat('A', 101)])->assertUnprocessable();
    }

    public function test_order_number_collision_is_retried_without_duplicate_stock_allocation(): void
    {
        [$user, $variant] = $this->catalog();
        $prefix = 'MAN'.now()->format('ymd');
        Order::withoutEvents(fn () => Order::create(['order_sn' => $prefix.'AAAAA', 'platform' => 'Manual']));
        $calls = 0;
        $input = $this->input($variant);
        Str::createRandomStringsUsing(function ($length) use (&$calls) {
            return str_repeat(++$calls === 1 ? 'A' : 'B', $length);
        });
        try {
            $order = app(ManualOrderService::class)->create($user->id, $input);
        } finally {
            Str::createRandomStringsNormally();
        }
        $this->assertSame($prefix.'BBBBB', $order->order_sn);
        $this->assertSame(2, $variant->fresh()->on_order);
        $this->assertSame(1, PayableEvent::where('source_id', $order->order_sn)->count());
    }

    public function test_paid_order_credits_cash_once_and_creates_a_protected_cashflow_receipt(): void
    {
        [$user, $variant] = $this->catalog();
        $account = $this->account($user);
        $input = [...$this->input($variant), 'financial_account_id' => $account->id];
        $response = $this->actingAs($user)->postJson('/api/manual-orders', $input)->assertCreated();
        $this->postJson('/api/manual-orders', $input)->assertCreated();
        $this->patchJson('/api/manual-orders/'.$response->json('id'), ['status' => 'SHIPPED', 'payment_status' => 'PAID'])->assertOk();
        $this->assertEquals(146000, $account->fresh()->balance);
        $this->assertSame(1, CashflowTransaction::count());
        $receipt = CashflowTransaction::firstOrFail();
        $this->getJson('/api/cashflow')->assertOk()->assertJsonPath('data.transactions.0.source', 'manual_order')->assertJsonPath('data.transactions.0.is_read_only', true);
        $this->deleteJson('/api/cashflow/transactions/'.$receipt->id)->assertNotFound();
        $this->putJson('/api/cashflow/transactions/'.$receipt->id, ['direction' => 'income', 'description' => 'Tidak boleh diubah', 'amount' => 1, 'occurred_at' => now()->toDateTimeString()])->assertNotFound();
        $this->assertEquals(146000, $account->fresh()->balance);
    }

    public function test_unpaid_partial_paid_and_account_corrections_reconcile_balance_without_duplicate_income(): void
    {
        [$user, $variant] = $this->catalog();
        $account = $this->account($user);
        $other = $this->account($user, 'BSI');
        $response = $this->actingAs($user)->postJson('/api/manual-orders', [...$this->input($variant), 'financial_account_id' => $account->id, 'payment_status' => 'UNPAID'])->assertCreated();
        $id = $response->json('id');
        $this->assertEquals(1000, $account->fresh()->balance);
        $this->assertSame(0, CashflowTransaction::count());
        $this->patchJson('/api/manual-orders/'.$id, ['status' => 'PROCESSED', 'payment_status' => 'PARTIAL', 'paid_amount' => 20000])->assertOk();
        $this->assertEquals(21000, $account->fresh()->balance);
        $this->patchJson('/api/manual-orders/'.$id, ['status' => 'PROCESSED', 'payment_status' => 'PARTIAL', 'paid_amount' => 30000])->assertOk();
        $this->assertEquals(31000, $account->fresh()->balance);
        $this->patchJson('/api/manual-orders/'.$id, ['status' => 'PROCESSED', 'payment_status' => 'PAID', 'financial_account_id' => $other->id])->assertOk();
        $this->assertEquals(1000, $account->fresh()->balance);
        $this->assertEquals(146000, $other->fresh()->balance);
        $this->assertSame(1, CashflowTransaction::count());
        $this->patchJson('/api/manual-orders/'.$id, ['status' => 'CANCELLED', 'payment_status' => 'PAID'])->assertOk();
        $this->assertEquals(146000, $other->fresh()->balance, 'Cancellation is not a bank refund.');
        $this->patchJson('/api/manual-orders/'.$id, ['status' => 'CANCELLED', 'payment_status' => 'UNPAID'])->assertOk();
        $this->assertEquals(1000, $other->fresh()->balance);
        $this->assertSame(0, CashflowTransaction::count());
    }

    public function test_invalid_accounts_and_partial_payments_roll_back_the_order(): void
    {
        [$user, $variant] = $this->catalog();
        $foreign = $this->account(User::factory()->create());
        $integrated = $this->account($user);
        $integrated->update(['source_type' => 'marketplace_escrow']);
        $asset = $this->account($user, 'Emas');
        $asset->update(['category' => 'asset']);
        foreach ([$foreign, $integrated, $asset] as $account) {
            $this->actingAs($user)->postJson('/api/manual-orders', [...$this->input($variant), 'financial_account_id' => $account->id])->assertUnprocessable();
        }
        $this->postJson('/api/manual-orders', [...$this->input($variant), 'payment_status' => 'PARTIAL', 'paid_amount' => 145001])->assertUnprocessable();
        $this->assertSame(0, Order::count());
        $this->assertSame(0, CashflowTransaction::count());
        $this->assertSame(0, $variant->fresh()->on_order);
        $this->getJson('/api/manual-orders/accounts')->assertOk()->assertJsonCount(0, 'accounts');
    }
}
