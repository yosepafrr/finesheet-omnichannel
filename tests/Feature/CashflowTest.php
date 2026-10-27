<?php

namespace Tests\Feature;

use App\Models\FinancialAccount;
use App\Models\PayableEvent;
use App\Models\PayablePayment;
use App\Models\PayablePeriod;
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
}
