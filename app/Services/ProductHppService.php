<?php

namespace App\Services;

use App\Models\MasterProductVariant;
use App\Models\Product;
use App\Models\VariantProduct;

class ProductHppService
{
    private array $masterVariantCache = [];

    public function __construct(private readonly BundleInventoryService $bundleInventory) {}

    public function productDetails(Product $product): array
    {
        return $this->details(
            $this->masterVariantForProduct($product),
            (float) ($product->hpp ?? 0)
        );
    }

    public function variantDetails(VariantProduct $variant): array
    {
        return $this->details(
            $this->masterVariantForVariant($variant),
            (float) ($variant->hpp ?? 0)
        );
    }

    public function updateProduct(Product $product, float $hpp): array
    {
        $masterVariant = $this->masterVariantForProduct($product);
        if ($masterVariant) {
            if ($masterVariant->is_bundle) {
                return $this->details($masterVariant, (float) ($product->hpp ?? 0));
            }

            $masterVariant->update(['hpp' => $hpp]);
            $this->bundleInventory->refreshAffectedByVariantIds([$masterVariant->id], false);

            return $this->details($masterVariant->fresh(), (float) ($product->hpp ?? 0));
        }

        $product->hpp = $hpp;
        $product->save();

        return $this->details(null, (float) $product->hpp);
    }

    public function updateVariant(VariantProduct $variant, float $hpp): array
    {
        $masterVariant = $this->masterVariantForVariant($variant);
        if ($masterVariant) {
            if ($masterVariant->is_bundle) {
                return $this->details($masterVariant, (float) ($variant->hpp ?? 0));
            }

            $masterVariant->update(['hpp' => $hpp]);
            $this->bundleInventory->refreshAffectedByVariantIds([$masterVariant->id], false);

            return $this->details($masterVariant->fresh(), (float) ($variant->hpp ?? 0));
        }

        $variant->hpp = $hpp;
        $variant->save();

        return $this->details(null, (float) $variant->hpp);
    }

    public function orderItemHpp(object $item): float
    {
        $product = $this->marketplaceProductForOrderItem($item);
        if (! $product) {
            return (float) ($item->price ?? 0);
        }

        $variant = $this->resolveOrderVariant($product, $item);
        if ($variant) {
            $details = $this->variantDetails($variant);
            if ($details['source'] === 'master') {
                return $details['hpp'];
            }
            if ($details['hpp'] > 0) {
                return $details['hpp'];
            }
            if ((float) $variant->price > 0) {
                return (float) $variant->price;
            }
        }

        $hasVariantIdentity = ! empty($item->platform_variant_id)
            || ! empty($item->sku)
            || ! in_array(strtolower(trim((string) ($item->model_name ?? ''))), ['', 'without variant'], true);

        if (! $variant && ! $hasVariantIdentity) {
            $firstVariant = $product->variantProducts()->first();
            if ($firstVariant) {
                $details = $this->variantDetails($firstVariant);
                if ($details['source'] === 'master' || $details['hpp'] > 0) {
                    return $details['hpp'];
                }
                if ((float) $firstVariant->price > 0) {
                    return (float) $firstVariant->price;
                }
            }
        }

        $details = $this->productDetails($product);
        if ($details['source'] === 'master' || $details['hpp'] > 0) {
            return $details['hpp'];
        }
        if ((float) $product->price > 0) {
            return (float) $product->price;
        }

        return (float) ($item->price ?? 0);
    }

    /**
     * Resolve the actual HPP configured by the user without falling back to a
     * marketplace selling price. Order List uses this to flag incomplete HPP.
     */
    public function orderItemHppDetails(object $item): array
    {
        $product = $this->marketplaceProductForOrderItem($item);
        if (! $product) {
            return $this->unavailableOrderItemHpp();
        }

        $variant = $this->resolveOrderVariant($product, $item);
        if ($variant) {
            $details = $this->variantDetails($variant);
            if ($details['hpp'] > 0) {
                return [...$details, 'available' => true];
            }
        }

        $hasVariantIdentity = ! empty($item->platform_variant_id)
            || ! empty($item->sku)
            || ! in_array(strtolower(trim((string) ($item->model_name ?? ''))), ['', 'without variant'], true);

        if (! $variant && ! $hasVariantIdentity) {
            $firstVariant = $product->variantProducts()->first();
            if ($firstVariant) {
                $details = $this->variantDetails($firstVariant);
                if ($details['hpp'] > 0) {
                    return [...$details, 'available' => true];
                }
            }
        }

        $details = $this->productDetails($product);
        if ($details['hpp'] > 0) {
            return [...$details, 'available' => true];
        }

        return $this->unavailableOrderItemHpp(
            $details['source'] ?? null,
            $details['master_variant_id'] ?? null,
        );
    }

    public function masterVariantForOrderItem(object $item): ?MasterProductVariant
    {
        $product = $this->marketplaceProductForOrderItem($item);
        if ($product) {
            $variant = $this->resolveOrderVariant($product, $item);
            if ($variant) {
                $masterVariant = $this->masterVariantForVariant($variant);
                if ($masterVariant) {
                    return $masterVariant;
                }
            }

            $masterVariant = $this->masterVariantForProduct($product);
            if ($masterVariant) {
                return $masterVariant;
            }
        }

        $sku = trim((string) ($item->sku ?? ''));
        $userId = ($item->order ?? null)?->store?->user_id;

        return $this->masterVariantBySku($sku, $userId);
    }

    private function masterVariantForProduct(Product $product): ?MasterProductVariant
    {
        $member = $product->relationLoaded('skuSyncMember')
            ? $product->skuSyncMember
            : $product->skuSyncMember()->with('group.masterVariant')->first();

        $linked = $this->memberMasterVariant($member);
        if ($linked) {
            return $linked;
        }

        $product->loadMissing('store');

        return $this->masterVariantBySku($product->product_sku, $product->store?->user_id);
    }

    private function marketplaceProductForOrderItem(object $item): ?Product
    {
        if (method_exists($item, 'relationLoaded') && $item->relationLoaded('product')) {
            $loadedProduct = $item->product;
            $storeId = ($item->order ?? null)?->store_id;

            if (! $loadedProduct) {
                return null;
            }

            if ($loadedProduct && (! $storeId || (int) $loadedProduct->store_id === (int) $storeId)) {
                return $loadedProduct;
            }
        }

        $query = Product::query()->where('product_id', $item->product_id);
        $storeId = ($item->order ?? null)?->store_id;
        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        return $query->first();
    }

    private function masterVariantForVariant(VariantProduct $variant): ?MasterProductVariant
    {
        $member = $variant->relationLoaded('skuSyncMember')
            ? $variant->skuSyncMember
            : $variant->skuSyncMember()->with('group.masterVariant')->first();

        $linked = $this->memberMasterVariant($member);
        if ($linked) {
            return $linked;
        }

        $variant->loadMissing('product.store');

        return $this->masterVariantBySku($variant->model_sku, $variant->product?->store?->user_id);
    }

    private function memberMasterVariant($member): ?MasterProductVariant
    {
        if (! $member) {
            return null;
        }

        $member->loadMissing('group.masterVariant');

        return $member->group?->masterVariant;
    }

    private function masterVariantBySku(mixed $sku, mixed $userId): ?MasterProductVariant
    {
        $sku = trim((string) $sku);
        if (! $userId || $sku === '' || $sku === '0') {
            return null;
        }

        $cacheKey = (int) $userId.':'.mb_strtolower($sku);
        if (array_key_exists($cacheKey, $this->masterVariantCache)) {
            return $this->masterVariantCache[$cacheKey];
        }

        return $this->masterVariantCache[$cacheKey] = MasterProductVariant::query()
            ->where('user_id', $userId)
            ->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)])
            ->first();
    }

    private function resolveOrderVariant(Product $product, object $item): ?VariantProduct
    {
        if ($product->relationLoaded('variantProducts')) {
            $variants = $product->variantProducts;

            if (! empty($item->platform_variant_id)) {
                $variant = $variants->first(
                    fn (VariantProduct $candidate) => (string) $candidate->model_id === (string) $item->platform_variant_id,
                );
                if ($variant) {
                    return $variant;
                }
            }

            $sku = trim((string) ($item->sku ?? ''));
            if ($sku !== '' && $sku !== '0') {
                $variant = $variants->first(
                    fn (VariantProduct $candidate) => mb_strtolower(trim((string) $candidate->model_sku)) === mb_strtolower($sku),
                );
                if ($variant) {
                    return $variant;
                }
            }

            $modelName = trim((string) ($item->model_name ?? ''));
            if ($modelName === '' || strcasecmp($modelName, 'without variant') === 0) {
                return null;
            }

            $normalized = str_replace(', ', ',', $modelName);

            return $variants->first(fn (VariantProduct $candidate) => in_array(
                (string) $candidate->model_name,
                [$modelName, $normalized],
                true,
            ) || (string) $candidate->variant_name === $modelName);
        }

        $query = VariantProduct::query()->where('product_id', $product->id);

        if (! empty($item->platform_variant_id)) {
            $variant = (clone $query)->where('model_id', $item->platform_variant_id)->first();
            if ($variant) {
                return $variant;
            }
        }

        $sku = trim((string) ($item->sku ?? ''));
        if ($sku !== '' && $sku !== '0') {
            $variant = (clone $query)
                ->whereRaw('LOWER(model_sku) = ?', [mb_strtolower($sku)])
                ->first();
            if ($variant) {
                return $variant;
            }
        }

        $modelName = trim((string) ($item->model_name ?? ''));
        if ($modelName === '' || strcasecmp($modelName, 'without variant') === 0) {
            return null;
        }

        $normalized = str_replace(', ', ',', $modelName);

        return (clone $query)
            ->where(function ($nameQuery) use ($modelName, $normalized) {
                $nameQuery->where('model_name', $modelName)
                    ->orWhere('model_name', $normalized)
                    ->orWhere('variant_name', $modelName);
            })
            ->first();
    }

    private function details(?MasterProductVariant $masterVariant, float $localHpp): array
    {
        $masterHpp = $masterVariant
            ? $this->bundleInventory->hppFor($masterVariant)
            : 0.0;
        $usesMasterHpp = $masterVariant && $masterHpp > 0;

        return [
            'hpp' => $usesMasterHpp ? $masterHpp : $localHpp,
            'source' => $usesMasterHpp ? 'master' : 'marketplace',
            'master_variant_id' => $masterVariant?->id,
        ];
    }

    private function unavailableOrderItemHpp(?string $source = null, ?int $masterVariantId = null): array
    {
        return [
            'hpp' => 0.0,
            'source' => $source,
            'master_variant_id' => $masterVariantId,
            'available' => false,
        ];
    }
}
