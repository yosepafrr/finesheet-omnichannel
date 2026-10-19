<?php

namespace App\Jobs;

use App\Events\ProfitAnalyticsUpdated;
use App\Models\Store;
use App\Services\ShopeeAdsSpendSyncService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncShopeeAdsSpendJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 600;

    public function __construct(
        private int $storeId,
        private string $spendDate,
    ) {}

    public function uniqueId(): string
    {
        return $this->storeId.':'.$this->spendDate;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ShopeeAdsSpendSyncService $syncService): void
    {
        $store = Store::query()
            ->whereKey($this->storeId)
            ->whereRaw('LOWER(platform) = ?', ['shopee'])
            ->first();

        if (! $store || ! $store->user_id) {
            Log::warning('Shopee ads spend sync skipped because store was not found', [
                'store_id' => $this->storeId,
                'spend_date' => $this->spendDate,
            ]);

            return;
        }

        $date = Carbon::createFromFormat('!Y-m-d', $this->spendDate, 'Asia/Jakarta');
        $spend = $syncService->syncDay($store, $date);

        Log::info('Shopee ads spend synced', [
            'store_id' => $store->id,
            'spend_date' => $this->spendDate,
            'amount' => (float) $spend->amount,
        ]);

        if ($date->greaterThanOrEqualTo(now('Asia/Jakarta')->subDay()->startOfDay())) {
            try {
                ProfitAnalyticsUpdated::dispatch(
                    (int) $store->user_id,
                    $this->spendDate,
                    'Shopee',
                );
            } catch (\Throwable $exception) {
                Log::warning('Shopee ads spend saved but realtime notification failed', [
                    'store_id' => $store->id,
                    'spend_date' => $this->spendDate,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }
}
