<?php

namespace App\Jobs;

use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\MarketplaceEscrowSnapshotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshMarketplaceEscrowSnapshotJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $userId,
        public readonly int $accountId,
    ) {}

    public function uniqueId(): string
    {
        return $this->userId.':'.$this->accountId;
    }

    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(MarketplaceEscrowSnapshotService $snapshotService): void
    {
        $user = User::find($this->userId);
        $account = $this->account();
        if (! $user || ! $account) {
            return;
        }

        $snapshotService->markRunning($account);
        $snapshotService->storeAccountSnapshot($account, $snapshotService->forUser($user));
    }

    public function failed(Throwable $exception): void
    {
        $account = $this->account();
        if ($account) {
            app(MarketplaceEscrowSnapshotService::class)->markFailed($account);
        }

        Log::warning('Marketplace escrow snapshot refresh failed.', [
            'user_id' => $this->userId,
            'financial_account_id' => $this->accountId,
            'message' => $exception->getMessage(),
        ]);
    }

    private function account(): ?FinancialAccount
    {
        return FinancialAccount::query()
            ->whereKey($this->accountId)
            ->where('user_id', $this->userId)
            ->where('source_type', 'marketplace_escrow')
            ->first();
    }
}
