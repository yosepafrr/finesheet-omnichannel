<?php

namespace App\Services;

use App\Jobs\SyncStockToMarketplaceJob;
use App\Models\InventoryMovement;
use App\Models\MasterProductVariant;
use App\Models\SkuSyncGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StockSyncService
{
    public function deductStock(SkuSyncGroup $group, int $qty): int
    {
        return $this->deductStockWithResult($group, $qty)['queued_updates'];
    }

    /** @return array{queued_updates:int,deducted_quantity:int,stock:int} */
    public function deductStockWithResult(SkuSyncGroup $group, int $qty): array
    {
        return $this->allocateOrderStock($group, $qty);
    }

    /** @return array{queued_updates:int,deducted_quantity:int,stock:int} */
    public function allocateOrderStock(SkuSyncGroup $group, int $qty, ?Model $reference = null): array
    {
        $qty = max(0, $qty);

        return DB::transaction(function () use ($group, $qty, $reference) {
            [$group, $variant] = $this->lockInventory($group);
            if (! $group->is_active || $qty === 0) {
                return $this->result($group, 0, 0);
            }
            if (! $variant) {
                return $this->legacyDeduct($group, $qty);
            }

            $before = $this->position($variant);
            $variant->on_order += $qty;
            $queued = $this->persistPosition($group, $variant, $reference, 'order_allocated', $before);

            return $this->result($group, $queued, $qty);
        });
    }

    public function restoreStock(SkuSyncGroup $group, int $qty): int
    {
        return $this->releaseOrderStock($group, $qty);
    }

    public function releaseOrderStock(SkuSyncGroup $group, int $qty, ?Model $reference = null): int
    {
        $qty = max(0, $qty);
        if ($qty === 0) {
            return 0;
        }

        return DB::transaction(function () use ($group, $qty, $reference) {
            [$group, $variant] = $this->lockInventory($group);
            if (! $variant) {
                return $this->legacyRestore($group, $qty);
            }

            $before = $this->position($variant);
            $variant->on_order = max(0, $variant->on_order - $qty);

            return $this->persistPosition($group, $variant, $reference, 'order_released', $before);
        });
    }

    public function shipOrderStock(SkuSyncGroup $group, int $qty, ?Model $reference = null): int
    {
        $qty = max(0, $qty);
        if ($qty === 0) {
            return 0;
        }

        return DB::transaction(function () use ($group, $qty, $reference) {
            [$group, $variant] = $this->lockInventory($group);
            if (! $variant) {
                return 0;
            }

            $before = $this->position($variant);
            $variant->on_order = max(0, $variant->on_order - $qty);
            $variant->on_hand = max(0, $variant->on_hand - $qty);

            return $this->persistPosition($group, $variant, $reference, 'order_shipped', $before);
        });
    }

    /** The supplied value is available marketplace stock. */
    public function setMasterStock(SkuSyncGroup $group, int $stock): int
    {
        $stock = max(0, $stock);

        return DB::transaction(function () use ($group, $stock) {
            [$group, $variant] = $this->lockInventory($group);
            if (! $variant) {
                $group->update(['master_stock' => $stock]);

                return $group->is_active ? $this->dispatchMemberUpdates($group, $stock) : 0;
            }

            $before = $this->position($variant);
            $variant->on_hand = $stock + $variant->on_order + $variant->reserved_stock;

            return $this->persistPosition($group, $variant, null, 'available_stock_set', $before);
        });
    }

    public function stockOpname(SkuSyncGroup $group, int $onHand, ?Model $reference = null, ?string $notes = null): int
    {
        return DB::transaction(function () use ($group, $onHand, $reference, $notes) {
            [$group, $variant] = $this->lockInventory($group);
            if (! $variant) {
                $group->update(['master_stock' => max(0, $onHand)]);

                return $group->is_active ? $this->dispatchMemberUpdates($group, max(0, $onHand)) : 0;
            }

            $before = $this->position($variant);
            $variant->on_hand = max(0, $onHand);

            return $this->persistPosition($group, $variant, $reference, 'stock_opname', $before, $notes);
        });
    }

    public function setReservedStock(SkuSyncGroup $group, int $reserved, ?Model $reference = null, ?string $notes = null): int
    {
        return DB::transaction(function () use ($group, $reserved, $reference, $notes) {
            [$group, $variant] = $this->lockInventory($group);
            if (! $variant) {
                return 0;
            }

            $before = $this->position($variant);
            $variant->reserved_stock = max(0, $reserved);

            return $this->persistPosition($group, $variant, $reference, 'reserved_stock_set', $before, $notes);
        });
    }

    public function updateInventoryPosition(
        SkuSyncGroup $group,
        int $onHand,
        int $reserved,
        ?Model $reference = null,
        ?string $notes = null
    ): int {
        return DB::transaction(function () use ($group, $onHand, $reserved, $reference, $notes) {
            [$group, $variant] = $this->lockInventory($group);
            if (! $variant) {
                $available = max(0, $onHand - $reserved);
                $group->update(['master_stock' => $available]);

                return $group->is_active ? $this->dispatchMemberUpdates($group, $available) : 0;
            }

            $before = $this->position($variant);
            $variant->on_hand = max(0, $onHand);
            $variant->reserved_stock = max(0, $reserved);

            return $this->persistPosition($group, $variant, $reference, 'inventory_adjusted', $before, $notes);
        });
    }

    public function pushAvailableStock(SkuSyncGroup $group): int
    {
        return DB::transaction(function () use ($group) {
            [$group, $variant] = $this->lockInventory($group);
            $available = $variant?->availableStock() ?? max(0, (int) $group->master_stock);
            if ($variant) {
                $variant->updateQuietly(['stock' => $available]);
                $group->updateQuietly(['master_stock' => $available]);
            }

            return $group->is_active ? $this->dispatchMemberUpdates($group, $available) : 0;
        });
    }

    private function lockInventory(SkuSyncGroup $group): array
    {
        $group = SkuSyncGroup::query()->lockForUpdate()->findOrFail($group->id);
        $variant = $group->master_product_variant_id
            ? MasterProductVariant::query()->lockForUpdate()->find($group->master_product_variant_id)
            : null;

        return [$group, $variant];
    }

    private function persistPosition(SkuSyncGroup $group, MasterProductVariant $variant, ?Model $reference, string $type, array $before, ?string $notes = null): int
    {
        $available = $variant->availableStock();
        $variant->stock = $available;
        $variant->saveQuietly();
        $group->updateQuietly(['master_stock' => $available]);
        $after = $this->position($variant);

        InventoryMovement::query()->create([
            'user_id' => $variant->user_id,
            'master_product_variant_id' => $variant->id,
            'type' => $type,
            'on_hand_delta' => $after['on_hand'] - $before['on_hand'],
            'on_order_delta' => $after['on_order'] - $before['on_order'],
            'reserved_delta' => $after['reserved_stock'] - $before['reserved_stock'],
            'on_hand_after' => $after['on_hand'],
            'on_order_after' => $after['on_order'],
            'reserved_after' => $after['reserved_stock'],
            'available_after' => $available,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'notes' => $notes,
            'occurred_at' => now(),
        ]);

        return $group->is_active ? $this->dispatchMemberUpdates($group, $available) : 0;
    }

    private function position(MasterProductVariant $variant): array
    {
        return [
            'on_hand' => (int) $variant->on_hand,
            'on_order' => (int) $variant->on_order,
            'reserved_stock' => (int) $variant->reserved_stock,
        ];
    }

    private function legacyDeduct(SkuSyncGroup $group, int $qty): array
    {
        $previous = (int) $group->master_stock;
        $stock = max(0, $previous - $qty);
        $group->update(['master_stock' => $stock]);

        return $this->result($group, $this->dispatchMemberUpdates($group, $stock), $previous - $stock);
    }

    private function legacyRestore(SkuSyncGroup $group, int $qty): int
    {
        $stock = (int) $group->master_stock + $qty;
        $group->update(['master_stock' => $stock]);

        return $group->is_active ? $this->dispatchMemberUpdates($group, $stock) : 0;
    }

    private function result(SkuSyncGroup $group, int $queued, int $deducted): array
    {
        return [
            'queued_updates' => $queued,
            'deducted_quantity' => $deducted,
            'stock' => (int) $group->fresh()->master_stock,
        ];
    }

    private function dispatchMemberUpdates(SkuSyncGroup $group, int $stock): int
    {
        $members = $group->members()->get();
        foreach ($members as $member) {
            $member->update(['sync_status' => 'pending', 'sync_requested_at' => now(), 'last_sync_error' => null]);
            SyncStockToMarketplaceJob::dispatch($member->id, $stock)->onQueue('products')->afterCommit();
        }

        return $members->count();
    }
}
