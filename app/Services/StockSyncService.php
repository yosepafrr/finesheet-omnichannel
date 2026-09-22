<?php

namespace App\Services;

use App\Jobs\SyncStockToMarketplaceJob;
use App\Models\SkuSyncGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockSyncService
{
    /**
     * Deduct stock from the master stock of a group, and dispatch jobs to update marketplaces.
     */
    public function deductStock(SkuSyncGroup $group, int $qty): int
    {
        return DB::transaction(function () use ($group, $qty) {
            $lockedGroup = SkuSyncGroup::query()->lockForUpdate()->findOrFail($group->id);

            if (! $lockedGroup->is_active) {
                return 0;
            }

            $newStock = max(0, $lockedGroup->master_stock - $qty);
            $lockedGroup->update(['master_stock' => $newStock]);
            $lockedGroup->masterVariant?->update(['stock' => $newStock]);

            Log::info("StockSyncService: Deducted stock for group {$lockedGroup->id} (SKU: {$lockedGroup->sku}). New stock: {$newStock}");

            return $this->dispatchMemberUpdates($lockedGroup, $newStock);
        });
    }

    /**
     * Set master stock manually (e.g. from UI) and push to all marketplaces.
     */
    public function setMasterStock(SkuSyncGroup $group, int $stock): int
    {
        $stock = max(0, $stock);

        return DB::transaction(function () use ($group, $stock) {
            $lockedGroup = SkuSyncGroup::query()->lockForUpdate()->findOrFail($group->id);
            $lockedGroup->update(['master_stock' => $stock]);
            $lockedGroup->masterVariant?->update(['stock' => $stock]);

            Log::info("StockSyncService: Set master stock for group {$lockedGroup->id} (SKU: {$lockedGroup->sku}) to {$stock}");

            if (! $lockedGroup->is_active) {
                return 0;
            }

            return $this->dispatchMemberUpdates($lockedGroup, $stock);
        });
    }

    private function dispatchMemberUpdates(SkuSyncGroup $group, int $stock): int
    {
        $members = $group->members()->get();

        foreach ($members as $member) {
            $member->update([
                'sync_status' => 'pending',
                'sync_requested_at' => now(),
                'last_sync_error' => null,
            ]);

            SyncStockToMarketplaceJob::dispatch($member->id, $stock)
                ->onQueue('products')
                ->afterCommit();
        }

        return $members->count();
    }
}
