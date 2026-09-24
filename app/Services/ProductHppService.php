<?php

namespace App\Services;

use App\Models\MasterProductVariant;
use App\Models\Product;
use App\Models\VariantProduct;

class ProductHppService
{
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
            $masterVariant->update(['hpp' => $hpp]);

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
            $masterVariant->update(['hpp' => $hpp]);

            return $this->details($masterVariant->fresh(), (float) ($variant->hpp ?? 0));
        }

        $variant->hpp = $hpp;
        $variant->save();

        return $this->details(null, (float) $variant->hpp);
    }

    public function orderItemHpp(object $item): float
    {
        $productQuery = Product::query()->where('product_id', $item->product_id);
        $storeId = $item->order?->store_id ?? null;
        if ($storeId) {
            $productQuery->where('store_id', $storeId);
        }

        $product = $productQuery->first();
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

        return MasterProductVariant::query()
            ->where('user_id', $userId)
            ->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)])
            ->first();
    }

    private function resolveOrderVariant(Product $product, object $item): ?VariantProduct
    {
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
        return [
            'hpp' => $masterVariant ? (float) $masterVariant->hpp : $localHpp,
            'source' => $masterVariant ? 'master' : 'marketplace',
            'master_variant_id' => $masterVariant?->id,
        ];
    }
}
