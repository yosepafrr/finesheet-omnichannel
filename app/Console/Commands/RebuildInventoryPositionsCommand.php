<?php

namespace App\Console\Commands;

use App\Models\InventoryMovement;
use App\Models\MasterProductVariant;
use App\Models\Order;
use App\Models\SkuSyncGroup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildInventoryPositionsCommand extends Command
{
    protected $signature = 'inventory:rebuild-positions {--user_id=}';

    protected $description = 'Rebuild On Order from active order allocations while preserving current available stock';

    public function handle(): int
    {
        $userId = $this->option('user_id') ? (int) $this->option('user_id') : null;
        $allocations = [];

        Order::query()
            ->whereNotNull('stock_sync_processed_at')
            ->whereNull('stock_sync_reverted_at')
            ->whereNull('stock_sync_shipped_at')
            ->whereNotNull('stock_sync_deductions')
            ->when($userId, fn ($query) => $query->whereHas('store', fn ($store) => $store->where('user_id', $userId)))
            ->select(['id', 'stock_sync_deductions'])
            ->orderBy('id')
            ->chunkById(500, function ($orders) use (&$allocations) {
                foreach ($orders as $order) {
                    foreach ((array) $order->stock_sync_deductions as $deduction) {
                        $groupId = (int) ($deduction['group_id'] ?? 0);
                        $quantity = max(0, (int) ($deduction['quantity'] ?? 0));
                        if ($groupId && $quantity) {
                            $allocations[$groupId] = ($allocations[$groupId] ?? 0) + $quantity;
                        }
                    }
                }
            });

        $updated = 0;
        DB::transaction(function () use ($allocations, $userId, &$updated) {
            SkuSyncGroup::query()
                ->whereNotNull('master_product_variant_id')
                ->when($userId, fn ($query) => $query->where('user_id', $userId))
                ->with('masterVariant')
                ->orderBy('id')
                ->chunkById(250, function ($groups) use ($allocations, &$updated) {
                    foreach ($groups as $group) {
                        $variant = MasterProductVariant::query()->lockForUpdate()->find($group->master_product_variant_id);
                        if (! $variant) {
                            continue;
                        }

                        $available = max(0, (int) $group->master_stock);
                        $onOrder = max(0, (int) ($allocations[$group->id] ?? 0));
                        $oldOnHand = (int) $variant->on_hand;
                        $oldOnOrder = (int) $variant->on_order;
                        $variant->on_order = $onOrder;
                        $variant->on_hand = $available + $onOrder + (int) $variant->reserved_stock;
                        $variant->stock = $available;
                        $variant->saveQuietly();

                        if ($oldOnHand !== (int) $variant->on_hand || $oldOnOrder !== $onOrder) {
                            InventoryMovement::query()->create([
                                'user_id' => $variant->user_id,
                                'master_product_variant_id' => $variant->id,
                                'type' => 'position_rebuilt',
                                'on_order_delta' => $onOrder - $oldOnOrder,
                                'on_hand_delta' => (int) $variant->on_hand - $oldOnHand,
                                'reserved_delta' => 0,
                                'on_hand_after' => $variant->on_hand,
                                'on_order_after' => $variant->on_order,
                                'reserved_after' => $variant->reserved_stock,
                                'available_after' => $available,
                                'occurred_at' => now(),
                            ]);
                        }
                        $updated++;
                    }
                });
        });

        $this->info("Posisi inventory {$updated} SKU diperbarui; stok tersedia tetap dipertahankan.");

        return self::SUCCESS;
    }
}
