<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\CashflowService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshCashflowDailyProfitJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $userId,
        public readonly string $date,
    ) {}

    public function uniqueId(): string
    {
        return $this->userId.':'.$this->date;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(CashflowService $cashflowService): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            $this->storeStatus('failed', 'User tidak lagi tersedia.');

            return;
        }

        $this->storeStatus('running');
        $timezone = config('app.timezone', 'Asia/Jakarta');
        $start = Carbon::createFromFormat('Y-m-d', $this->date, $timezone)->startOfDay();
        $end = $start->copy()->endOfDay();
        $transactions = $cashflowService->dailyProfit($user, $start, $end)
            ->values()
            ->all();

        Cache::put(self::resultKey($this->userId, $this->date), [
            'transactions' => $transactions,
            'generated_at' => now()->toIso8601String(),
        ], now()->addMinutes(30));
        $this->storeStatus('ready');
    }

    public function failed(?Throwable $exception): void
    {
        $message = $exception?->getMessage() ?: 'Perhitungan profit harian berhenti sebelum selesai.';
        $this->storeStatus('failed', $message);

        Log::warning('Cashflow daily profit refresh failed.', [
            'user_id' => $this->userId,
            'date' => $this->date,
            'message' => $message,
        ]);
    }

    public static function statusKey(int $userId, string $date): string
    {
        return "cashflow-profit-status:{$userId}:{$date}";
    }

    public static function resultKey(int $userId, string $date): string
    {
        return "cashflow-profit-result:{$userId}:{$date}";
    }

    private function storeStatus(string $status, ?string $message = null): void
    {
        Cache::put(self::statusKey($this->userId, $this->date), [
            'status' => $status,
            'message' => $message,
            'updated_at' => now()->toIso8601String(),
        ], now()->addHour());
    }
}
