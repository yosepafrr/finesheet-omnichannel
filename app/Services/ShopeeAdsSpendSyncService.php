<?php

namespace App\Services;

use App\Models\MarketplaceAdSpend;
use App\Models\Store;
use Carbon\CarbonInterface;
use RuntimeException;

class ShopeeAdsSpendSyncService
{
    public function __construct(private ShopeeService $shopee) {}

    public function syncDay(Store $store, CarbonInterface $date): MarketplaceAdSpend
    {
        $result = $this->shopee->getAdsHourlyPerformance($store, $date);
        $error = trim((string) ($result['error'] ?? ''));

        if ($error !== '') {
            throw new RuntimeException(
                'Shopee Ads API error: '.$error.' - '.($result['message'] ?? 'unknown error'),
            );
        }

        $records = $this->performanceRecords($result['response'] ?? []);
        $amount = collect($records)->sum(
            fn ($record) => is_array($record) ? (float) ($record['expense'] ?? 0) : 0,
        );

        return MarketplaceAdSpend::updateOrCreate(
            [
                'store_id' => $store->id,
                'platform' => 'Shopee',
                'spend_date' => $date->toDateString(),
            ],
            [
                'user_id' => $store->user_id,
                'amount' => $amount,
                'currency' => 'IDR',
                'raw_data' => $result,
                'synced_at' => now(),
            ],
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function performanceRecords(mixed $response): array
    {
        if (! is_array($response)) {
            return [];
        }

        if (array_is_list($response)) {
            return $response;
        }

        foreach (['performance_list', 'data_list', 'report_list', 'list'] as $key) {
            $records = $response[$key] ?? null;
            if (is_array($records) && array_is_list($records)) {
                return $records;
            }
        }

        return [];
    }
}
