<?php

namespace App\Jobs;

use App\Events\PayableUpdated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Setting;
use App\Services\PayableService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SyncPayableHistoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $startDate;
    protected $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $startDate, ?int $userId = null)
    {
        $this->startDate = $startDate;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(PayableService $payableService): void
    {
        $userIds = $this->userId ? [$this->userId] : \App\Models\User::pluck('id')->toArray();

        foreach ($userIds as $uid) {
            $payableService->syncPayableForUser($uid, $this->startDate);

            try {
                broadcast(new PayableUpdated(null, 'history_synced', $uid));
            } catch (\Throwable $e) {
                Log::warning('Failed to broadcast payable history sync completion', [
                    'user_id' => $uid,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        Log::info("SyncPayableHistoryJob completed from {$this->startDate} for user " . ($this->userId ?? 'ALL'));
    }
}
