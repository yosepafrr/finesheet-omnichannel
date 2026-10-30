<?php

namespace App\Services;

use App\Models\MarketplaceAdSpend;
use App\Models\TiktokAdAccount;
use App\Models\TiktokAdCampaignSpend;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TiktokAdsSpendSyncService
{
    public function __construct(private TiktokAdsApiService $api) {}

    /** @return array{days: int, campaigns: int, amount: float} */
    public function sync(
        TiktokAdAccount $account,
        CarbonInterface $start,
        CarbonInterface $end,
    ): array {
        $account->loadMissing(['connection', 'store']);

        if (! $account->is_active || ! $account->connection || $account->connection->status !== 'active') {
            throw new RuntimeException('Koneksi TikTok Ads tidak aktif.');
        }

        if (! $account->store || strtolower((string) $account->store->platform) !== 'tiktokshop') {
            throw new RuntimeException('Advertiser account belum dipetakan ke toko TikTok Shop.');
        }

        $startDate = $start->toDateString();
        $endDate = $end->toDateString();
        $info = $this->api->advertiserInfo($account->connection, $account->advertiser_id);

        $currency = trim((string) ($info['currency'] ?? $account->currency ?? 'IDR')) ?: 'IDR';
        $account->update([
            'advertiser_name' => trim((string) ($info['name'] ?? '')) ?: $account->advertiser_name,
            'currency' => $currency,
            'timezone' => trim((string) ($info['timezone'] ?? '')) ?: $account->timezone,
        ]);

        $dailyRecords = $this->api->report(
            $account->connection,
            $account->advertiser_id,
            ['stat_time_day'],
            ['spend'],
            $startDate,
            $endDate,
        );
        $campaignRecords = $this->api->report(
            $account->connection,
            $account->advertiser_id,
            ['campaign_id', 'stat_time_day'],
            ['campaign_name', 'spend', 'impressions', 'clicks'],
            $startDate,
            $endDate,
        );

        $platformStoreId = trim((string) $account->store->platform_shop_id);
        if ($platformStoreId === '') {
            throw new RuntimeException(
                'ID TikTok Shop belum tersedia. Otorisasi ulang toko sebelum menyinkronkan GMV Max Ads.',
            );
        }

        $gmvCampaignRecords = $this->api->gmvMaxReport(
            $account->connection,
            $account->advertiser_id,
            $platformStoreId,
            ['campaign_id', 'stat_time_day'],
            ['campaign_name', 'cost'],
            $startDate,
            $endDate,
        );

        $dailyRecords = [...$dailyRecords, ...$gmvCampaignRecords];
        $campaignRecords = [...$campaignRecords, ...$gmvCampaignRecords];

        $dailyByDate = collect($dailyRecords)->groupBy(fn (array $record) => $this->recordDate($record));
        $campaignsByDate = collect($campaignRecords)->groupBy(fn (array $record) => $this->recordDate($record));
        $syncedAt = now();
        $summary = DB::transaction(function () use (
            $account,
            $start,
            $end,
            $dailyByDate,
            $campaignsByDate,
            $currency,
            $syncedAt,
        ): array {
            $days = 0;
            $campaigns = 0;
            $amount = 0.0;
            $cursor = Carbon::parse($start->toDateString());
            $last = Carbon::parse($end->toDateString());

            while ($cursor->lessThanOrEqualTo($last)) {
                $date = $cursor->toDateString();
                $dailyRecords = $dailyByDate->get($date, collect());
                $dailyAmount = (float) $dailyRecords->sum(
                    fn (array $record) => $this->recordSpend($record),
                );

                $spend = MarketplaceAdSpend::query()->updateOrCreate(
                    [
                        'store_id' => $account->store_id,
                        'platform' => 'Tiktokshop',
                        'spend_date' => $date,
                    ],
                    [
                        'user_id' => $account->user_id,
                        'amount' => $dailyAmount,
                        'currency' => $currency,
                        'raw_data' => [
                            'advertiser_id' => $account->advertiser_id,
                            'records' => $dailyRecords->values()->all(),
                        ],
                        'synced_at' => $syncedAt,
                    ],
                );

                $spend->campaigns()->delete();
                foreach ($campaignsByDate->get($date, collect()) as $record) {
                    $campaignId = trim((string) (
                        data_get($record, 'dimensions.campaign_id')
                        ?? data_get($record, 'metrics.campaign_id')
                        ?? ''
                    ));
                    if ($campaignId === '') {
                        continue;
                    }

                    TiktokAdCampaignSpend::query()->create([
                        'marketplace_ad_spend_id' => $spend->id,
                        'tiktok_ad_account_id' => $account->id,
                        'campaign_id' => $campaignId,
                        'campaign_name' => trim((string) data_get($record, 'metrics.campaign_name')) ?: null,
                        'amount' => $this->recordSpend($record),
                        'impressions' => (int) $this->number(
                            data_get($record, 'metrics.impressions')
                                ?? data_get($record, 'metrics.product_impressions', 0),
                        ),
                        'clicks' => (int) $this->number(
                            data_get($record, 'metrics.clicks')
                                ?? data_get($record, 'metrics.product_clicks', 0),
                        ),
                        'raw_data' => $record,
                        'synced_at' => $syncedAt,
                    ]);
                    $campaigns++;
                }

                $days++;
                $amount += $dailyAmount;
                $cursor->addDay();
            }

            return compact('days', 'campaigns', 'amount');
        });

        $account->update([
            'last_synced_at' => $syncedAt,
            'last_error' => null,
        ]);
        $account->connection->update([
            'last_synced_at' => $syncedAt,
            'last_error' => null,
        ]);

        return $summary;
    }

    private function recordDate(array $record): string
    {
        $value = data_get($record, 'dimensions.stat_time_day')
            ?? data_get($record, 'metrics.stat_time_day');

        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        return Carbon::parse($value)->toDateString();
    }

    private function number(mixed $value): float
    {
        if (! is_numeric($value)) {
            $value = str_replace(',', '', (string) $value);
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /** @param array<string, mixed> $record */
    private function recordSpend(array $record): float
    {
        return $this->number(
            data_get($record, 'metrics.spend')
                ?? data_get($record, 'metrics.cost', 0),
        );
    }
}
