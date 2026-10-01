<?php

namespace App\Services;

use App\Jobs\SyncPayableHistoryJob;
use Illuminate\Support\Facades\Cache;

class PayableSyncStatusService
{
    private const CACHE_TTL_SECONDS = 21600;

    public function dispatch(string $startDate, int $userId, string $context = 'history'): void
    {
        $status = $this->markQueued($userId, $context);

        try {
            SyncPayableHistoryJob::dispatch($startDate, $userId)->onQueue('orders');
        } catch (\Throwable $exception) {
            $this->markFailed($userId, $status['revision']);
            throw $exception;
        }
    }

    public function markQueued(int $userId, string $context = 'history'): array
    {
        Cache::add($this->revisionKey($userId), 0, self::CACHE_TTL_SECONDS);
        $revision = (int) Cache::increment($this->revisionKey($userId));

        $status = [
            'status' => 'queued',
            'revision' => $revision,
            'context' => $context,
            'queued_at' => now()->toIso8601String(),
            'started_at' => null,
            'finished_at' => null,
            'message' => 'Menunggu giliran worker.',
        ];

        $this->put($userId, $status);

        return $status;
    }

    public function markRunning(int $userId): int
    {
        $status = $this->get($userId);
        $revision = (int) ($status['revision'] ?? 0);

        if (($status['status'] ?? 'idle') !== 'queued') {
            Cache::add($this->revisionKey($userId), $revision, self::CACHE_TTL_SECONDS);
            $revision = (int) Cache::increment($this->revisionKey($userId));
        }

        $revision = max(1, $revision);

        $this->put($userId, [
            ...$status,
            'status' => 'running',
            'revision' => $revision,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'message' => 'Menghitung ulang histori dan periode payable.',
        ]);

        return $revision;
    }

    public function markCompleted(int $userId, int $revision): bool
    {
        return $this->finish($userId, $revision, 'completed', 'Sinkronisasi payable selesai.');
    }

    public function markFailed(int $userId, int $revision): bool
    {
        return $this->finish($userId, $revision, 'failed', 'Sinkronisasi payable gagal. Silakan coba lagi.');
    }

    public function get(int $userId): array
    {
        return Cache::get($this->statusKey($userId), [
            'status' => 'idle',
            'revision' => 0,
            'context' => null,
            'queued_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'message' => null,
        ]);
    }

    private function finish(int $userId, int $revision, string $statusName, string $message): bool
    {
        $status = $this->get($userId);

        // A newer request is queued/running, so this older job must not hide its indicator.
        if ((int) ($status['revision'] ?? 0) !== $revision) {
            return false;
        }

        $this->put($userId, [
            ...$status,
            'status' => $statusName,
            'finished_at' => now()->toIso8601String(),
            'message' => $message,
        ]);

        return true;
    }

    private function put(int $userId, array $status): void
    {
        Cache::put($this->statusKey($userId), $status, self::CACHE_TTL_SECONDS);
    }

    private function statusKey(int $userId): string
    {
        return "payable:sync-status:{$userId}";
    }

    private function revisionKey(int $userId): string
    {
        return "payable:sync-revision:{$userId}";
    }
}
