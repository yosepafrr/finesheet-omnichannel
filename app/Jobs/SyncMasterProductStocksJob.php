<?php

namespace App\Jobs;

use App\Models\MasterProductVariant;
use App\Services\MasterSkuSyncService;
use App\Services\StockSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncMasterProductStocksJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public array $variantIds) {}

    public function uniqueId(): string
    {
        $ids = $this->variantIds;
        sort($ids);

        return hash('sha256', implode(',', $ids));
    }

    public function handle(MasterSkuSyncService $skuSync, StockSyncService $stockSync): void
    {
        MasterProductVariant::query()
            ->whereIn('id', $this->variantIds)
            ->with('masterProduct')
            ->orderBy('id')
            ->get()
            ->each(function (MasterProductVariant $variant) use ($skuSync, $stockSync) {
                $group = $skuSync->syncVariant($variant);
                if ($group) {
                    $stockSync->setMasterStock($group, (int) $variant->stock);
                }
            });
    }
}
