<?php

namespace App\Jobs;

use App\Events\PayableUpdated;
use App\Models\User;
use App\Services\PayableService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncPayableHistoryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $uniqueFor = 1200;

    protected $startDate;

    protected $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $startDate, ?int $userId = null)
    {
        $this->startDate = Carbon::parse($startDate)->format('Y-m-d H:i:s');
        $this->userId = $userId;
    }

    public function uniqueId(): string
    {
        return ($this->userId ?? 'all').':'.$this->startDate;
    }

    /**
     * Execute the job.
     */
    public function handle(PayableService $payableService): void
    {
        $userIds = $this->userId ? [$this->userId] : User::pluck('id')->toArray();

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

        Log::info("SyncPayableHistoryJob completed from {$this->startDate} for user ".($this->userId ?? 'ALL'));
    }
}
