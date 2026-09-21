<?php

namespace App\Services;

use App\Models\MasterProduct;
use App\Models\MasterProductVariant;
use App\Models\MasterProductVariantListing;
use App\Models\Product;
use App\Models\SkuSyncGroup;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MasterCatalogService
{
    public function syncStore(Store $store): int
    {
        $synced = 0;

        Product::query()
            ->where('store_id', $store->id)
            ->orderBy('id')
            ->chunkById(100, function ($products) use (&$synced) {
                foreach ($products as $product) {
                    $this->syncProduct($product);
                    $synced++;
                }
            });

        return $synced;
    }

    public function syncProduct(Product $product): void
    {
        $product->loadMissing(['store', 'variantProducts']);
        $store = $product->store;

        if (! $store) {
            return;
        }

        DB::transaction(function () use ($product, $store) {
            DB::table('users')->where('id', $store->user_id)->lockForUpdate()->first();

            $units = $this->listingUnits($product);
            $listingKeys = $units->pluck('listing_key');
            $existingMappings = MasterProductVariantListing::query()
                ->where('store_id', $store->id)
                ->where('product_id', $product->id)
                ->with('masterVariant.masterProduct')
                ->get()
                ->keyBy('listing_key');

            $normalizedSkus = $units->pluck('sku')
                ->filter(fn ($sku) => $sku !== null)
                ->map(fn ($sku) => mb_strtolower($sku))
                ->unique()
                ->values();
            $variantsBySku = MasterProductVariant::query()
                ->where('user_id', $store->user_id)
                ->when(
                    $normalizedSkus->isNotEmpty(),
                    fn ($query) => $query->whereIn(DB::raw('LOWER(sku)'), $normalizedSkus),
                    fn ($query) => $query->whereRaw('1 = 0')
                )
                ->with('masterProduct')
                ->get()
                ->keyBy(fn ($variant) => mb_strtolower((string) $variant->sku));

            $fallbackMaster = $existingMappings->first()?->masterVariant?->masterProduct
                ?? $variantsBySku->first()?->masterProduct;
            $touchedMasterIds = $existingMappings
                ->pluck('masterVariant.master_product_id')
                ->filter()
                ->values();

            foreach ($units as $unit) {
                $existingMapping = $existingMappings->get($unit['listing_key']);
                $normalizedSku = $unit['sku'] ? mb_strtolower($unit['sku']) : null;
                $mappedVariant = $existingMapping?->masterVariant;
                $mappedSku = $mappedVariant?->sku
                    ? mb_strtolower($mappedVariant->sku)
                    : null;
                $masterVariant = $mappedSku === $normalizedSku
                    ? $mappedVariant
                    : ($normalizedSku ? $variantsBySku->get($normalizedSku) : null);

                if (! $masterVariant && $mappedVariant && $mappedVariant->listings()->count() <= 1) {
                    $mappedVariant->update([
                        'sku' => $unit['sku'],
                        'variant_name' => $unit['variant_name'],
                        'hpp' => $unit['hpp'],
                    ]);
                    $masterVariant = $mappedVariant;

                    if ($normalizedSku) {
                        $variantsBySku->put($normalizedSku, $masterVariant);
                    }
                }

                if (! $masterVariant) {
                    $fallbackMaster = $mappedVariant?->masterProduct
                        ?? $fallbackMaster
                        ?? $this->createAutomaticMaster($product, $store->user_id);
                    $masterVariant = $fallbackMaster->variants()->create([
                        'user_id' => $store->user_id,
                        'sku' => $unit['sku'],
                        'variant_name' => $unit['variant_name'],
                        'barcode' => null,
                        'hpp' => $unit['hpp'],
                        'stock' => $unit['stock'],
                        'is_active' => true,
                    ]);

                    if ($normalizedSku) {
                        $variantsBySku->put($normalizedSku, $masterVariant);
                    }
                }

                MasterProductVariantListing::updateOrCreate(
                    ['listing_key' => $unit['listing_key']],
                    [
                        'master_product_variant_id' => $masterVariant->id,
                        'store_id' => $store->id,
                        'product_id' => $product->id,
                        'variant_product_id' => $unit['variant_product_id'],
                        'platform_product_id' => (string) $product->product_id,
                        'platform_variant_id' => $unit['platform_variant_id'],
                        'snapshot_stock' => $unit['stock'],
                        'snapshot_price' => $unit['price'],
                    ]
                );

                $this->linkSyncGroup($masterVariant, $store->user_id);
                $touchedMasterIds->push($masterVariant->master_product_id);
            }

            MasterProductVariantListing::query()
                ->where('store_id', $store->id)
                ->where('product_id', $product->id)
                ->when(
                    $listingKeys->isNotEmpty(),
                    fn ($query) => $query->whereNotIn('listing_key', $listingKeys),
                    fn ($query) => $query
                )
                ->delete();

            MasterProduct::query()
                ->whereIn('id', $touchedMasterIds->unique())
                ->where('reference_store_id', $store->id)
                ->get()
                ->each(fn (MasterProduct $master) => $this->refreshFromReference($master, $store));
        });
    }

    public function setReferenceStore(MasterProduct $master, Store $store): MasterProduct
    {
        $hasListing = MasterProductVariantListing::query()
            ->where('store_id', $store->id)
            ->whereHas('masterVariant', fn ($query) => $query->where('master_product_id', $master->id))
            ->exists();

        if (! $hasListing || $store->user_id !== $master->user_id) {
            abort(422, 'Toko tersebut tidak memiliki listing yang terhubung ke produk master ini.');
        }

        return DB::transaction(function () use ($master, $store) {
            $master->update(['reference_store_id' => $store->id]);
            $this->refreshFromReference($master, $store);

            return $master->fresh();
        });
    }

    private function listingUnits(Product $product): Collection
    {
        if ($product->variantProducts->isNotEmpty()) {
            return $product->variantProducts->map(fn ($variant) => [
                'listing_key' => $this->listingKey($product, $variant->id),
                'variant_product_id' => $variant->id,
                'platform_variant_id' => $variant->model_id ? (string) $variant->model_id : null,
                'sku' => $this->cleanSku($variant->model_sku),
                'variant_name' => $variant->variant_name ?: $variant->model_name,
                'stock' => max(0, (int) $variant->stock),
                'price' => max(0, (float) $variant->price),
                'hpp' => max(0, (float) $variant->hpp),
            ]);
        }

        return collect([[
            'listing_key' => $this->listingKey($product),
            'variant_product_id' => null,
            'platform_variant_id' => null,
            'sku' => $this->cleanSku($product->product_sku),
            'variant_name' => null,
            'stock' => max(0, (int) $product->stock),
            'price' => max(0, (float) $product->price),
            'hpp' => max(0, (float) $product->hpp),
        ]]);
    }

    private function createAutomaticMaster(Product $product, int $userId): MasterProduct
    {
        return MasterProduct::create([
            'user_id' => $userId,
            'reference_store_id' => null,
            'name' => $product->product_name,
            'category' => $product->category,
            'image' => $product->image,
            'status' => 'active',
            'source' => 'automatic',
        ]);
    }

    private function refreshFromReference(MasterProduct $master, Store $store): void
    {
        $master->load(['variants.listings' => fn ($query) => $query
            ->where('store_id', $store->id)
            ->with('product')]);
        $referenceListing = $master->variants
            ->flatMap->listings
            ->first();

        if ($referenceListing?->product) {
            $master->update([
                'name' => $referenceListing->product->product_name,
                'category' => $referenceListing->product->category,
                'image' => $referenceListing->product->image,
            ]);
        }

        foreach ($master->variants as $variant) {
            $variant->update(['stock' => (int) $variant->listings->sum('snapshot_stock')]);
        }
    }

    private function linkSyncGroup(MasterProductVariant $variant, int $userId): void
    {
        if (! $variant->sku) {
            return;
        }

        SkuSyncGroup::query()
            ->where('user_id', $userId)
            ->whereRaw('LOWER(sku) = ?', [mb_strtolower($variant->sku)])
            ->update(['master_product_variant_id' => $variant->id]);
    }

    private function listingKey(Product $product, ?int $variantId = null): string
    {
        return "store:{$product->store_id}:product:{$product->id}:variant:".($variantId ?? 0);
    }

    private function cleanSku(mixed $sku): ?string
    {
        $sku = trim((string) $sku);

        return $sku !== '' && $sku !== '0' ? $sku : null;
    }
}
