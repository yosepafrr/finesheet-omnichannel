<?php

namespace App\Jobs;

use App\Events\PayableUpdated;
use App\Models\User;
use App\Services\PayableService;
use App\Services\PayableSyncStatusService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncPayableHistoryJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $uniqueFor = 1200;

    public int $tries = 15;

    protected $startDate;

    protected $userId;

    protected array $statusRevisions = [];

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

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('payable-history:'.($this->userId ?? 'all')))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 60),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(PayableService $payableService, PayableSyncStatusService $syncStatus): void
    {
        $userIds = $this->userId ? [$this->userId] : User::pluck('id')->toArray();

        foreach ($userIds as $uid) {
            $revision = $syncStatus->markRunning($uid);
            $this->statusRevisions[$uid] = $revision;
            $payableService->syncPayableForUser($uid, $this->startDate);
            $syncStatus->markCompleted($uid, $revision);

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

    public function failed(\Throwable $exception): void
    {
        $userIds = $this->userId ? [$this->userId] : User::pluck('id')->toArray();
        $syncStatus = app(PayableSyncStatusService::class);

        foreach ($userIds as $uid) {
            $status = $syncStatus->get($uid);
            if (($status['status'] ?? null) !== 'running') {
                continue;
            }

            $revision = $this->statusRevisions[$uid] ?? (int) ($status['revision'] ?? 0);
            $syncStatus->markFailed($uid, $revision);
        }
    }
}
