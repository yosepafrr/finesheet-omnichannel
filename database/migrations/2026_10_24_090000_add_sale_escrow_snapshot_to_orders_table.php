<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('sale_escrow_amount', 18, 2)
                ->nullable()
                ->after('escrow_amount_after_adjustment');
        });

        DB::table('orders')
            ->select([
                'id',
                'platform',
                'escrow_amount',
                'escrow_amount_after_adjustment',
                'fee_details',
            ])
            ->whereNull('sale_escrow_amount')
            ->where(function ($query) {
                $query->where('escrow_amount', '>', 0)
                    ->orWhere('escrow_amount_after_adjustment', '>', 0)
                    ->orWhereNotNull('fee_details');
            })
            ->orderBy('id')
            ->chunkById(500, function ($orders) {
                foreach ($orders as $order) {
                    $amount = $this->snapshotAmount($order);

                    if ($amount === null) {
                        continue;
                    }

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->whereNull('sale_escrow_amount')
                        ->update(['sale_escrow_amount' => $amount]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('sale_escrow_amount');
        });
    }

    private function hasAuthoritativeFinanceData(object $order): bool
    {
        if (strcasecmp((string) $order->platform, 'Shopee') === 0) {
            return true;
        }

        $details = $order->fee_details;
        if (is_string($details)) {
            $details = json_decode($details, true);
        }

        if (! is_array($details) || $details === []) {
            return false;
        }

        return in_array($details['source'] ?? null, ['unsettled', 'settled'], true)
            || array_is_list($details);
    }

    private function snapshotAmount(object $order): ?float
    {
        if (! $this->hasAuthoritativeFinanceData($order)) {
            return null;
        }

        foreach ([$order->escrow_amount, $order->escrow_amount_after_adjustment] as $amount) {
            if (is_numeric($amount) && (float) $amount > 0) {
                return (float) $amount;
            }
        }

        $details = $order->fee_details;
        if (is_string($details)) {
            $details = json_decode($details, true);
        }

        if (! is_array($details) || $details === []) {
            return null;
        }

        if (strcasecmp((string) $order->platform, 'Shopee') === 0) {
            $income = is_array($details['order_income'] ?? null)
                ? $details['order_income']
                : $details;

            foreach (['escrow_amount', 'escrow_amount_after_adjustment'] as $key) {
                if (is_numeric($income[$key] ?? null) && (float) $income[$key] > 0) {
                    return (float) $income[$key];
                }
            }

            return null;
        }

        if (($details['source'] ?? null) === 'unsettled') {
            return $this->positiveTransactionSum(
                $details['transactions'] ?? [],
                'est_settlement_amount',
            );
        }

        if (($details['source'] ?? null) === 'settled') {
            $amount = $details['summary']['settlement_amount'] ?? null;

            return is_numeric($amount) && (float) $amount > 0
                ? (float) $amount
                : $this->positiveTransactionSum(
                    $details['transactions'] ?? [],
                    'settlement_amount',
                );
        }

        if (array_is_list($details)) {
            return $this->positiveTransactionSum($details, 'est_settlement_amount')
                ?? $this->positiveTransactionSum($details, 'settlement_amount');
        }

        return null;
    }

    private function positiveTransactionSum(array $transactions, string $key): ?float
    {
        $amounts = collect($transactions)
            ->filter(fn ($transaction) => is_array($transaction))
            ->pluck($key)
            ->filter(fn ($amount) => is_numeric($amount))
            ->map(fn ($amount) => (float) $amount);

        if ($amounts->isEmpty()) {
            return null;
        }

        $total = (float) $amounts->sum();

        return $total > 0 ? $total : null;
    }
};
