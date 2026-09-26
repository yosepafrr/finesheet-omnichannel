<?php

namespace App\Services;

use App\Models\Order;

class TiktokEscrowAmountResolver
{
    public function shouldTryStatement(?string $status): bool
    {
        return in_array(strtoupper((string) $status), ['DELIVERED', 'COMPLETED'], true);
    }

    public function needsRefresh(?array $financeDetails, ?string $status, $storedAmount = null): bool
    {
        if (! $this->hasValidStoredAmount($financeDetails, $storedAmount)) {
            return true;
        }

        return $this->shouldTryStatement($status)
            && ($financeDetails['source'] ?? null) !== 'settled';
    }

    public function hasValidStoredAmount(?array $financeDetails, $storedAmount): bool
    {
        if (empty($financeDetails) || ! is_numeric($storedAmount)) {
            return false;
        }

        $source = $financeDetails['source'] ?? null;
        if ($source === 'unsettled') {
            $amounts = $this->transactionAmounts(
                $financeDetails['transactions'] ?? [],
                'est_settlement_amount'
            );

            return $amounts !== []
                && abs(array_sum($amounts) - (float) $storedAmount) < 0.01;
        }

        if ($source === 'settled') {
            $settledAmount = $financeDetails['summary']['settlement_amount'] ?? null;

            return is_numeric($settledAmount)
                && abs((float) $settledAmount - (float) $storedAmount) < 0.01;
        }

        // Finance details saved by the previous implementation were a raw list.
        if (array_is_list($financeDetails)) {
            $amounts = $this->transactionAmounts($financeDetails, 'est_settlement_amount');
            if ($amounts === []) {
                $amounts = $this->transactionAmounts($financeDetails, 'settlement_amount');
            }

            return $amounts !== []
                && abs(array_sum($amounts) - (float) $storedAmount) < 0.01;
        }

        return false;
    }

    public function fallbackSalePrice(array $order): float
    {
        return array_reduce(
            $order['line_items'] ?? [],
            fn (float $total, array $item) => $total + $this->numericAmount($item['sale_price'] ?? null),
            0.0
        );
    }

    public function fallbackForOrder(Order $order): float
    {
        $rawFallback = $this->fallbackSalePrice($order->raw_data ?? []);
        if ($rawFallback > 0) {
            return $rawFallback;
        }

        return (float) $order->orderProducts->sum(
            fn ($item) => (float) $item->price * max(1, (int) $item->quantity_purchased)
        );
    }

    public function unsettled(array $response, string $orderId): ?array
    {
        if (($response['code'] ?? null) !== 0 || ! is_array($response['data'] ?? null)) {
            return null;
        }

        $data = $response['data'];
        $allTransactions = array_values(array_filter(
            $data['transactions'] ?? [],
            fn ($transaction) => is_array($transaction)
        ));
        $transactions = array_values(array_filter(
            $allTransactions,
            fn (array $transaction) => isset($transaction['order_id'])
                && (string) $transaction['order_id'] === $orderId
        ));

        // Some response variants omit order_id when exactly one filtered row is
        // returned. Never use the shop-wide aggregate for a multi-row response.
        if ($transactions === [] && count($allTransactions) === 1 && ! isset($allTransactions[0]['order_id'])) {
            $transactions = $allTransactions;
        }

        $amounts = $this->transactionAmounts($transactions, 'est_settlement_amount');
        if ($amounts === []) {
            return null;
        }

        return $this->result(
            array_sum($amounts),
            'unsettled',
            $data,
            $transactions
        );
    }

    public function settled(array $response): ?array
    {
        if (($response['code'] ?? null) !== 0 || ! is_array($response['data'] ?? null)) {
            return null;
        }

        $data = $response['data'];
        if ($this->hasNumericAmount($data, 'settlement_amount')) {
            return $this->result(
                $this->numericAmount($data['settlement_amount']),
                'settled',
                $data,
                $data['sku_transactions'] ?? []
            );
        }

        $transactions = $data['statement_transactions'] ?? [];
        $amounts = $this->transactionAmounts($transactions, 'settlement_amount');

        if ($amounts === []) {
            return null;
        }

        return $this->result(
            array_sum($amounts),
            'settled',
            $data,
            $transactions
        );
    }

    private function result(float $amount, string $source, array $data, array $transactions): array
    {
        return [
            'amount' => $amount,
            'details' => [
                'source' => $source,
                'summary' => array_diff_key($data, [
                    'transactions' => true,
                    'statement_transactions' => true,
                    'sku_transactions' => true,
                ]),
                'transactions' => $transactions,
            ],
        ];
    }

    private function hasNumericAmount(array $data, string $key): bool
    {
        return array_key_exists($key, $data) && is_numeric($data[$key]);
    }

    private function numericAmount($amount): float
    {
        return is_numeric($amount) ? (float) $amount : 0.0;
    }

    private function transactionAmounts(array $transactions, string $key): array
    {
        return array_values(array_map(
            'floatval',
            array_filter(
                array_map(
                    fn ($transaction) => is_array($transaction) ? ($transaction[$key] ?? null) : null,
                    $transactions
                ),
                fn ($amount) => is_numeric($amount)
            )
        ));
    }
}
