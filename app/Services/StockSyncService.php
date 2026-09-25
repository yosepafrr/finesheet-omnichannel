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
        return $this->deductStockWithResult($group, $qty)['queued_updates'];
    }

    /**
     * @return array{queued_updates: int, deducted_quantity: int, stock: int}
     */
    public function deductStockWithResult(SkuSyncGroup $group, int $qty): array
    {
        $qty = max(0, $qty);

        return DB::transaction(function () use ($group, $qty) {
            $lockedGroup = SkuSyncGroup::query()->lockForUpdate()->findOrFail($group->id);

            if (! $lockedGroup->is_active) {
                return [
                    'queued_updates' => 0,
                    'deducted_quantity' => 0,
                    'stock' => (int) $lockedGroup->master_stock,
                ];
            }

            $previousStock = (int) $lockedGroup->master_stock;
            $newStock = max(0, $previousStock - $qty);
            $deductedQuantity = $previousStock - $newStock;
            $lockedGroup->update(['master_stock' => $newStock]);
            $lockedGroup->masterVariant?->update(['stock' => $newStock]);

            Log::info("StockSyncService: Deducted stock for group {$lockedGroup->id} (SKU: {$lockedGroup->sku}). New stock: {$newStock}");

            return [
                'queued_updates' => $this->dispatchMemberUpdates($lockedGroup, $newStock),
                'deducted_quantity' => $deductedQuantity,
                'stock' => $newStock,
            ];
        });
    }

    /**
     * Restore a previous order deduction and push the resulting stock to marketplaces.
     */
    public function restoreStock(SkuSyncGroup $group, int $qty): int
    {
        $qty = max(0, $qty);
        if ($qty === 0) {
            return 0;
        }

        return DB::transaction(function () use ($group, $qty) {
            $lockedGroup = SkuSyncGroup::query()->lockForUpdate()->findOrFail($group->id);
            $newStock = (int) $lockedGroup->master_stock + $qty;

            $lockedGroup->update(['master_stock' => $newStock]);
            $lockedGroup->masterVariant?->update(['stock' => $newStock]);

            Log::info("StockSyncService: Restored stock for group {$lockedGroup->id} (SKU: {$lockedGroup->sku}). New stock: {$newStock}");

            if (! $lockedGroup->is_active) {
                return 0;
            }

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
