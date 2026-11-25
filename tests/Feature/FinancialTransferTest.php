<?php

namespace Tests\Feature;

use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialTransferTest extends TestCase
{
    use RefreshDatabase;

    private function account(User $user, array $attributes = []): FinancialAccount
    {
        return FinancialAccount::create([
            'user_id' => $user->id, 'name' => 'BCA', 'category' => 'cash',
            'subtype' => 'bank', 'balance' => 2000000, 'is_active' => true,
            ...$attributes,
        ]);
    }

    public function test_transfer_changes_both_balances_and_only_the_fee_counts_as_expense(): void
    {
        $user = User::factory()->create();
        $source = $this->account($user, ['name' => 'Saldo penjual toko A', 'subtype' => 'marketplace']);
        $destination = $this->account($user, ['balance' => 100000]);
        $payload = [
            'source_account_id' => $source->id, 'destination_account_id' => $destination->id,
            'amount' => 1000000, 'fee' => 2500, 'occurred_at' => now()->subMinute()->toDateTimeString(),
            'request_key' => (string) Str::uuid(),
        ];
        $this->actingAs($user)->postJson('/api/cashflow/transfers', $payload)->assertCreated();
        $this->postJson('/api/cashflow/transfers', $payload)->assertCreated();
        $this->postJson('/api/cashflow/transfers', [...$payload, 'amount' => 200000])->assertUnprocessable();
        $this->assertSame(997500.0, (float) $source->fresh()->balance);
        $this->assertSame(1100000.0, (float) $destination->fresh()->balance);
        $this->assertDatabaseCount('financial_transfers', 1);
        $this->getJson('/api/cashflow')->assertOk()
            ->assertJsonPath('data.cashflow_totals.income', 0)
            ->assertJsonPath('data.cashflow_totals.expense', 2500)
            ->assertJsonPath('data.cashflow_totals.net', -2500)
            ->assertJsonPath('data.summary.cash_position', 2097500)
            ->assertJsonPath('data.transactions.0.direction', 'transfer')
            ->assertJsonPath('data.transactions.0.account.name', 'Saldo penjual toko A → BCA')
            ->assertJsonPath('data.chart.0.expense', 2500);
    }

    public function test_transfer_without_fee_does_not_change_cashflow_or_total_cash(): void
    {
        $user = User::factory()->create();
        $source = $this->account($user);
        $destination = $this->account($user);
        $this->actingAs($user)->postJson('/api/cashflow/transfers', [
            'source_account_id' => $source->id, 'destination_account_id' => $destination->id,
            'amount' => '1000.25', 'fee' => 0, 'occurred_at' => now()->subMinute()->toDateTimeString(),
            'request_key' => (string) Str::uuid(),
        ])->assertCreated();
        $this->assertSame(1998999.75, (float) $source->fresh()->balance);
        $this->assertSame(2001000.25, (float) $destination->fresh()->balance);
        $this->getJson('/api/cashflow')->assertOk()
            ->assertJsonPath('data.cashflow_totals.net', 0)
            ->assertJsonPath('data.cashflow_totals.income', 0)
            ->assertJsonPath('data.cashflow_totals.expense', 0)
            ->assertJsonPath('data.summary.cash_position', 4000000);
    }

    public function test_invalid_accounts_and_insufficient_funds_never_change_balances(): void
    {
        $user = User::factory()->create();
        $source = $this->account($user);
        $destination = $this->account($user);
        $other = $this->account(User::factory()->create());
        $escrow = $this->account($user, ['source_type' => 'marketplace_escrow']);
        $asset = $this->account($user, ['category' => 'asset']);
        $inactive = $this->account($user, ['is_active' => false]);
        $payload = [
            'source_account_id' => $source->id, 'destination_account_id' => $destination->id,
            'amount' => 1000000, 'fee' => 0, 'occurred_at' => now()->subMinute()->toDateTimeString(),
            'request_key' => (string) Str::uuid(),
        ];
        $this->actingAs($user);
        foreach ([$other, $escrow, $asset, $inactive] as $invalid) {
            $this->postJson('/api/cashflow/transfers', [...$payload, 'source_account_id' => $invalid->id])->assertUnprocessable();
            $this->postJson('/api/cashflow/transfers', [...$payload, 'destination_account_id' => $invalid->id])->assertUnprocessable();
        }
        $this->postJson('/api/cashflow/transfers', [...$payload, 'destination_account_id' => $source->id])->assertUnprocessable();
        $this->postJson('/api/cashflow/transfers', [...$payload, 'amount' => 2000000, 'fee' => 1])->assertUnprocessable();
        $this->postJson('/api/cashflow/transfers', [...$payload, 'amount' => -1])->assertUnprocessable();
        $this->assertSame(2000000.0, (float) $source->fresh()->balance);
        $this->assertSame(2000000.0, (float) $destination->fresh()->balance);
        $this->assertDatabaseCount('financial_transfers', 0);
    }
}
