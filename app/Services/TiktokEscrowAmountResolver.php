<?php

namespace App\Services;

use App\Models\Order;

class TiktokEscrowAmountResolver
{
    public function needsRefresh(?array $financeDetails, ?string $status): bool
    {
        if (empty($financeDetails)) {
            return true;
        }

        return strtoupper((string) $status) === 'COMPLETED'
            && ($financeDetails['source'] ?? null) !== 'settled';
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
        if (($response['code'] ?? null) !== 0 || !is_array($response['data'] ?? null)) {
            return null;
        }

        $data = $response['data'];
        $transactions = array_values(array_filter(
            $data['transactions'] ?? [],
            fn (array $transaction) => !isset($transaction['order_id'])
                || (string) $transaction['order_id'] === $orderId
        ));

        if ($this->hasNumericAmount($data, 'sum_est_settlement_amount')) {
            return $this->result(
                $this->numericAmount($data['sum_est_settlement_amount']),
                'unsettled',
                $data,
                $transactions
            );
        }

        $amounts = array_values(array_filter(
            array_map(
                fn (array $transaction) => $transaction['est_settlement_amount'] ?? null,
                $transactions
            ),
            fn ($amount) => is_numeric($amount)
        ));

        if ($amounts === []) {
            return null;
        }

        return $this->result(
            array_sum(array_map('floatval', $amounts)),
            'unsettled',
            $data,
            $transactions
        );
    }

    public function settled(array $response): ?array
    {
        if (($response['code'] ?? null) !== 0 || !is_array($response['data'] ?? null)) {
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
        $amounts = array_values(array_filter(
            array_map(
                fn (array $transaction) => $transaction['settlement_amount'] ?? null,
                $transactions
            ),
            fn ($amount) => is_numeric($amount)
        ));

        if ($amounts === []) {
            return null;
        }

        return $this->result(
            array_sum(array_map('floatval', $amounts)),
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
}
