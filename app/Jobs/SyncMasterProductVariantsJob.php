<?php

namespace App\Jobs;

use App\Models\MasterProductVariant;
use App\Services\MasterSkuSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncMasterProductVariantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public array $variantIds) {}

    public function handle(MasterSkuSyncService $syncService): void
    {
        MasterProductVariant::query()
            ->whereIn('id', $this->variantIds)
            ->with('masterProduct')
            ->orderBy('id')
            ->get()
            ->each(fn (MasterProductVariant $variant) => $syncService->syncVariant($variant));
    }
}
