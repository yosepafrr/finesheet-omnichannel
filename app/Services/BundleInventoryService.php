<?php

namespace App\Services;

use App\Models\MasterProductBundleComponent;
use App\Models\MasterProductVariant;
use App\Models\SkuSyncGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BundleInventoryService
{
    public function __construct(
        private readonly MasterSkuSyncService $skuSync,
        private readonly StockSyncService $stockSync,
    ) {}

    /**
     * @return array{stock: int, hpp: float, components_count: int}
     */
    public function metrics(MasterProductVariant $bundle): array
    {
        $bundle->loadMissing('bundleComponents.componentVariant.syncGroup');
        $components = $bundle->bundleComponents;

        if (! $bundle->is_bundle || $components->isEmpty()) {
            return ['stock' => 0, 'hpp' => 0.0, 'components_count' => 0];
        }

        $availableBundles = [];
        $hpp = 0.0;

        foreach ($components as $component) {
            $quantity = max(1, (int) $component->quantity);
            $variant = $component->componentVariant;

            if (! $variant || $variant->is_bundle) {
                return ['stock' => 0, 'hpp' => 0.0, 'components_count' => $components->count()];
            }

            $componentStock = $variant->is_active
                ? (int) ($variant->syncGroup?->master_stock ?? $variant->stock)
                : 0;
            $availableBundles[] = intdiv(max(0, $componentStock), $quantity);
            $hpp += (float) $variant->hpp * $quantity;
        }

        return [
            'stock' => $availableBundles === [] ? 0 : min($availableBundles),
            'hpp' => round($hpp, 2),
            'components_count' => $components->count(),
        ];
    }

    public function hppFor(MasterProductVariant $variant): float
    {
        return $variant->is_bundle
            ? $this->metrics($variant)['hpp']
            : (float) $variant->hpp;
    }

    /**
     * Recalculate a bundle and optionally push its derived stock to matching listings.
     *
     * @return array{stock: int, hpp: float, components_count: int, queued_updates: int}
     */
    public function refreshBundle(MasterProductVariant $bundle, bool $pushStock = true): array
    {
        return DB::transaction(function () use ($bundle, $pushStock) {
            DB::table('users')->where('id', $bundle->user_id)->lockForUpdate()->first();

            $lockedBundle = MasterProductVariant::query()->lockForUpdate()->findOrFail($bundle->id);
            $componentIds = $lockedBundle->bundleComponents()
                ->orderBy('component_variant_id')
                ->pluck('component_variant_id');
            MasterProductVariant::query()
                ->whereIn('id', $componentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            SkuSyncGroup::query()
                ->whereIn('master_product_variant_id', $componentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $lockedBundle->load('bundleComponents.componentVariant.syncGroup');
            $metrics = $this->metrics($lockedBundle);
            $lockedBundle->updateQuietly([
                'stock' => $metrics['stock'],
                'hpp' => $metrics['hpp'],
            ]);

            $queued = 0;
            if ($pushStock && $lockedBundle->is_bundle) {
                $group = $this->skuSync->syncVariant($lockedBundle->fresh());
                if ($group) {
                    $queued = $this->stockSync->setMasterStock($group, $metrics['stock']);
                }
            }

            return [...$metrics, 'queued_updates' => $queued];
        });
    }

    public function refreshAffectedByVariantIds(array|Collection $variantIds, bool $pushStock = true): int
    {
        $ids = collect($variantIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return 0;
        }

        $bundleIds = MasterProductBundleComponent::query()
            ->whereIn('component_variant_id', $ids)
            ->distinct()
            ->pluck('bundle_variant_id');

        $queued = 0;
        MasterProductVariant::query()
            ->whereIn('id', $bundleIds)
            ->where('is_bundle', true)
            ->orderBy('id')
            ->get()
            ->each(function (MasterProductVariant $bundle) use (&$queued, $pushStock) {
                $queued += $this->refreshBundle($bundle, $pushStock)['queued_updates'];
            });

        return $queued;
    }

    public function refreshAffectedByGroupIds(array|Collection $groupIds, bool $pushStock = true): int
    {
        $variantIds = SkuSyncGroup::query()
            ->whereIn('id', collect($groupIds)->filter()->unique())
            ->pluck('master_product_variant_id');

        return $this->refreshAffectedByVariantIds($variantIds, $pushStock);
    }
}
