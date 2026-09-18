<?php

namespace App\Services;

use App\Jobs\SyncShopeeOrderJob;
use App\Jobs\SyncStoreLogisticsChunkJob;
use App\Jobs\SyncStoreLogisticsJob;
use App\Jobs\SyncTiktokOrderJob;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class OrderSyncStatusService
{
    public function forStores(Collection $stores): array
    {
        if ($stores->isEmpty()) {
            return [];
        }

        $storesById = $stores->keyBy('id');
        $syncs = [];

        $jobs = $this->trackedJobs();

        foreach ($jobs as $row) {
            $job = $this->decodeJob($row->payload);

            if (!$job || !($job->showProgress ?? false) || !$job->storeId) {
                continue;
            }

            $store = $storesById->get((int) $job->storeId);
            if (!$store) {
                continue;
            }

            $storeId = (int) $store->id;
            $status = $row->reserved_at ? 'running' : 'queued';
            $phase = $job->syncPhase ?? 'orders';

            if (!isset($syncs[$storeId])) {
                $syncs[$storeId] = [
                    'store_id' => $storeId,
                    'store_name' => $store->store_name,
                    'platform' => $store->platform,
                    'status' => $status,
                    'phase' => $phase,
                    'context' => $job->syncContext ?? 'manual',
                    'pending_jobs' => 0,
                    'started_at' => date(DATE_ATOM, (int) $row->created_at),
                    '_has_order_jobs' => false,
                ];
            }

            $syncs[$storeId]['pending_jobs']++;
            if ($phase === 'orders') {
                $syncs[$storeId]['_has_order_jobs'] = true;
                $syncs[$storeId]['phase'] = 'orders';
            } elseif (!$syncs[$storeId]['_has_order_jobs']) {
                $syncs[$storeId]['phase'] = 'logistics';
            }

            if ($status === 'running') {
                $syncs[$storeId]['status'] = 'running';
            }

            if (($job->syncContext ?? null) === 'initial') {
                $syncs[$storeId]['context'] = 'initial';
            }

            if ((int) $row->created_at < strtotime($syncs[$storeId]['started_at'])) {
                $syncs[$storeId]['started_at'] = date(DATE_ATOM, (int) $row->created_at);
            }
        }

        return array_values(array_map(function ($sync) {
            unset($sync['_has_order_jobs']);
            return $sync;
        }, $syncs));
    }

    public function hasPendingOrderJobsForStore(int $storeId): bool
    {
        $jobs = DB::table('jobs')
            ->where('queue', 'orders')
            ->where(function ($query) {
                $query->where('payload', 'like', '%SyncShopeeOrderJob%')
                    ->orWhere('payload', 'like', '%SyncTiktokOrderJob%');
            })
            ->pluck('payload');

        foreach ($jobs as $payload) {
            $job = $this->decodeJob($payload);
            if ($job && (int) ($job->storeId ?? 0) === $storeId) {
                return true;
            }
        }

        return false;
    }

    private function trackedJobs()
    {
        return DB::table('jobs')
            ->where('queue', 'orders')
            ->where(function ($query) {
                $query->where('payload', 'like', '%SyncShopeeOrderJob%')
                    ->orWhere('payload', 'like', '%SyncTiktokOrderJob%')
                    ->orWhere('payload', 'like', '%SyncStoreLogisticsJob%')
                    ->orWhere('payload', 'like', '%SyncStoreLogisticsChunkJob%');
            })
            ->get(['id', 'payload', 'reserved_at', 'created_at']);
    }

    private function decodeJob(string $payload): ?object
    {
        $decoded = json_decode($payload, true);
        $command = $decoded['data']['command'] ?? null;

        if (!is_string($command)) {
            return null;
        }

        $job = @unserialize($command, [
            'allowed_classes' => [
                SyncShopeeOrderJob::class,
                SyncTiktokOrderJob::class,
                SyncStoreLogisticsJob::class,
                SyncStoreLogisticsChunkJob::class,
            ],
        ]);

        if (!is_object($job)) {
            return null;
        }

        return in_array(get_class($job), [
            SyncShopeeOrderJob::class,
            SyncTiktokOrderJob::class,
            SyncStoreLogisticsJob::class,
            SyncStoreLogisticsChunkJob::class,
        ], true) ? $job : null;
    }
}
