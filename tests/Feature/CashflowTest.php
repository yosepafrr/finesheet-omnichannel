<?php

namespace Tests\Feature;

use App\Models\FinancialAccount;
use App\Models\Order;
use App\Models\PayableEvent;
use App\Models\PayablePayment;
use App\Models\PayablePeriod;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_transaction_updates_the_selected_account_balance(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::create([
            'user_id' => $user->id,
            'name' => 'BCA Operasional',
            'category' => 'cash',
            'subtype' => 'bank',
            'balance' => 100000,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/cashflow/transactions', [
            'financial_account_id' => $account->id,
            'direction' => 'income',
            'category' => 'Modal',
            'description' => 'Tambahan modal',
            'amount' => 50000,
            'occurred_at' => '2026-10-27 10:00:00',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.description', 'Tambahan modal');
        $this->assertSame(150000.0, (float) $account->fresh()->balance);

        $transactionId = $response->json('data.id');
        $this->actingAs($user)->putJson("/api/cashflow/transactions/{$transactionId}", [
            'financial_account_id' => $account->id,
            'direction' => 'expense',
            'category' => 'Operasional',
            'description' => 'Biaya operasional',
            'amount' => 20000,
            'occurred_at' => '2026-10-27 11:00:00',
        ])->assertOk();

        $this->assertSame(80000.0, (float) $account->fresh()->balance);

        $this->actingAs($user)
            ->deleteJson("/api/cashflow/transactions/{$transactionId}")
            ->assertOk();

        $this->assertSame(100000.0, (float) $account->fresh()->balance);
    }

    public function test_summary_includes_supplier_payable_breakdown_and_net_worth(): void
    {
        $user = User::factory()->create();
        FinancialAccount::create([
            'user_id' => $user->id,
            'name' => 'BCA',
            'category' => 'cash',
            'subtype' => 'bank',
            'balance' => 10000000,
            'is_active' => true,
        ]);
        FinancialAccount::create([
            'user_id' => $user->id,
            'name' => 'Emas',
            'category' => 'asset',
            'subtype' => 'investment',
            'balance' => 2000000,
            'is_active' => true,
        ]);

        $supplier = Supplier::create(['user_id' => $user->id, 'name' => 'Supplier A']);
        $period = PayablePeriod::withoutEvents(fn () => PayablePeriod::create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'name' => 'Periode berjalan',
            'start_date' => '2026-10-20 00:00:00',
            'end_date' => '2026-11-02 23:59:59',
            'payment_status' => 'PARTIAL',
            'is_closed' => false,
            'is_manual' => false,
        ]));
        PayableEvent::withoutEvents(fn () => PayableEvent::create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'payable_period_id' => $period->id,
            'platform' => 'Shopee',
            'source_id' => 'ORDER-1',
            'source_type' => 'CREATE_ORDER',
            'event_date' => '2026-10-25 10:00:00',
            'amount' => 5000000,
        ]));
        PayablePayment::withoutEvents(fn () => PayablePayment::create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'payable_period_id' => $period->id,
            'payment_date' => '2026-10-26 10:00:00',
            'amount' => 2000000,
            'payment_method' => 'Transfer',
        ]));

        $this->actingAs($user)
            ->getJson('/api/cashflow?start_date=2026-10-20&end_date=2026-10-27')
            ->assertOk()
            ->assertJsonPath('data.summary.cash_position', 10000000)
            ->assertJsonPath('data.summary.assets', 2000000)
            ->assertJsonPath('data.summary.supplier_payable', 3000000)
            ->assertJsonPath('data.summary.total_debt', 3000000)
            ->assertJsonPath('data.summary.net_worth', 9000000)
            ->assertJsonPath('data.supplier_payables.0.supplier_name', 'Supplier A')
            ->assertJsonPath('data.supplier_payables.0.amount', 3000000);
    }

    public function test_user_cannot_update_another_users_financial_account(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = FinancialAccount::create([
            'user_id' => $owner->id,
            'name' => 'Private Account',
            'category' => 'cash',
            'subtype' => 'bank',
            'balance' => 100000,
            'is_active' => true,
        ]);

        $this->actingAs($otherUser)->putJson("/api/cashflow/accounts/{$account->id}", [
            'name' => 'Taken Over',
            'category' => 'cash',
            'subtype' => 'bank',
            'balance' => 1,
        ])->assertNotFound();

        $this->assertSame('Private Account', $account->fresh()->name);
    }

    public function test_marketplace_escrow_integration_only_counts_active_order_escrow_and_can_be_removed(): void
    {
        $user = User::factory()->create();
        $shopee = Store::create([
            'user_id' => $user->id,
            'platform' => 'Shopee',
            'store_name' => 'Shopee Utama',
            'shopee_shop_id' => 'ESCROW-SHOPEE',
        ]);
        $tiktok = Store::create([
            'user_id' => $user->id,
            'platform' => 'Tiktokshop',
            'store_name' => 'TikTok Utama',
            'shopee_shop_id' => 'ESCROW-TIKTOK-COMPAT',
            'platform_shop_id' => 'ESCROW-TIKTOK',
        ]);

        $this->createEscrowOrder($shopee, 'ACTIVE-SHOPEE', 'READY_TO_SHIP', 100000);
        $this->createEscrowOrder($tiktok, 'ACTIVE-TIKTOK', 'IN_TRANSIT', 200000);
        $this->createEscrowOrder($shopee, 'SELLER-BALANCE', 'COMPLETED', 500000);
        $this->createEscrowOrder($tiktok, 'CANCELLED', 'CANCELLED', 700000);

        $created = $this->actingAs($user)
            ->postJson('/api/cashflow/integrations/marketplace-escrow')
            ->assertCreated()
            ->assertJsonPath('data.source_type', 'marketplace_escrow');

        $this->actingAs($user)
            ->postJson('/api/cashflow/integrations/marketplace-escrow')
            ->assertOk();

        $this->assertSame(1, FinancialAccount::where('user_id', $user->id)
            ->where('source_type', 'marketplace_escrow')
            ->count());

        $this->actingAs($user)
            ->getJson('/api/cashflow?start_date=2026-10-20&end_date=2026-10-27')
            ->assertOk()
            ->assertJsonPath('data.summary.cash_position', 300000)
            ->assertJsonPath('data.accounts.0.balance', '300000.00')
            ->assertJsonPath('data.accounts.0.integration_details.excludes_seller_balance', true)
            ->assertJsonCount(2, 'data.accounts.0.integration_details.stores');

        $accountId = $created->json('data.id');
        $this->actingAs($user)
            ->deleteJson("/api/cashflow/accounts/{$accountId}")
            ->assertOk();

        $this->assertDatabaseMissing('financial_accounts', ['id' => $accountId]);
    }

    private function createEscrowOrder(Store $store, string $orderSn, string $status, int $escrow): void
    {
        Order::withoutEvents(fn () => Order::create([
            'store_id' => $store->id,
            'platform' => $store->platform,
            'order_sn' => $orderSn,
            'order_status' => $status,
            'order_time' => '2026-10-27 10:00:00',
            'escrow_amount' => $escrow,
        ]));
    }
}
