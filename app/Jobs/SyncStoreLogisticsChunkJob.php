<?php

namespace App\Jobs;

use App\Services\LogisticsSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncStoreLogisticsChunkJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300;
    public $uniqueFor = 1800;
    public $storeId;
    public $packageIds;
    public $showProgress;
    public $syncContext;
    public $syncPhase = 'logistics';

    public function __construct(
        int $storeId,
        array $packageIds,
        bool $showProgress = true,
        string $syncContext = 'manual'
    ) {
        $this->storeId = $storeId;
        $this->packageIds = array_values(array_map('intval', $packageIds));
        $this->showProgress = $showProgress;
        $this->syncContext = $syncContext;
    }

    public function uniqueId(): string
    {
        return $this->storeId.':logistics-chunk:'.sha1(implode(',', $this->packageIds));
    }

    public function handle(LogisticsSyncService $logistics): void
    {
        $logistics->syncPackages($this->packageIds);
    }
}
