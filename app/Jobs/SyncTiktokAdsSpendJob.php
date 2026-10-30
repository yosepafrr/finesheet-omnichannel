<?php

namespace App\Jobs;

use App\Events\ProfitAnalyticsUpdated;
use App\Models\TiktokAdAccount;
use App\Services\TiktokAdsSpendSyncService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncTiktokAdsSpendJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(
        private int $accountId,
        private string $startDate,
        private string $endDate,
    ) {}

    public function uniqueId(): string
    {
        return $this->accountId.':'.$this->startDate.':'.$this->endDate;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(TiktokAdsSpendSyncService $syncService): void
    {
        $account = TiktokAdAccount::query()
            ->with(['connection', 'store'])
            ->whereKey($this->accountId)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            Log::warning('TikTok Ads spend sync skipped because account was not found', [
                'account_id' => $this->accountId,
            ]);

            return;
        }

        try {
            $summary = $syncService->sync(
                $account,
                Carbon::createFromFormat('!Y-m-d', $this->startDate, 'Asia/Jakarta'),
                Carbon::createFromFormat('!Y-m-d', $this->endDate, 'Asia/Jakarta'),
            );

            Log::info('TikTok Ads spend and campaigns synced', [
                'account_id' => $account->id,
                'advertiser_id' => $account->advertiser_id,
                'start_date' => $this->startDate,
                'end_date' => $this->endDate,
                ...$summary,
            ]);

            if ($account->store_id) {
                ProfitAnalyticsUpdated::dispatch(
                    (int) $account->user_id,
                    $this->endDate,
                    'Tiktokshop',
                );
            }
        } catch (Throwable $exception) {
            $account->update(['last_error' => $exception->getMessage()]);
            $account->connection?->update(['last_error' => $exception->getMessage()]);

            throw $exception;
        }
    }
}
