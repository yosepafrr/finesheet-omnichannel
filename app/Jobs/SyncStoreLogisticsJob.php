<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\LogisticsSyncService;
use App\Services\OrderSyncStatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncStoreLogisticsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 120;
    public $timeout = 60;
    public $uniqueFor = 1800;
    public $storeId;
    public $showProgress;
    public $syncContext;
    public $syncPhase = 'logistics';

    public function __construct(int $storeId, bool $showProgress = true, string $syncContext = 'manual')
    {
        $this->storeId = $storeId;
        $this->showProgress = $showProgress;
        $this->syncContext = $syncContext;
    }

    public function uniqueId(): string
    {
        return $this->storeId.':logistics';
    }

    public function handle(
        LogisticsSyncService $logistics,
        OrderSyncStatusService $statusService
    ): void {
        if ($statusService->hasPendingOrderJobsForStore($this->storeId)) {
            $this->release(5);
            return;
        }

        $store = Store::find($this->storeId);
        if (!$store) {
            Log::warning('Logistics sync skipped because store was not found', [
                'store_id' => $this->storeId,
            ]);
            return;
        }

        $logistics->prepare($store->id);
        $packageIds = $logistics->packageIds($store->id);

        foreach (array_chunk($packageIds, 25) as $packageIdsChunk) {
            SyncStoreLogisticsChunkJob::dispatch(
                $store->id,
                $packageIdsChunk,
                $this->showProgress,
                $this->syncContext
            )->onQueue('orders');
        }

        Log::info('Store logistics sync prepared', [
            'store_id' => $store->id,
            'packages' => count($packageIds),
            'chunks' => count(array_chunk($packageIds, 25)),
        ]);
    }
}
