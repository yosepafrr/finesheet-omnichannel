<?php

namespace App\Services;

use App\Models\MasterProductVariant;
use App\Models\Product;
use App\Models\SkuSyncGroup;
use App\Models\SkuSyncMember;
use App\Models\VariantProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MasterSkuSyncService
{
    public function syncVariant(MasterProductVariant $variant): ?SkuSyncGroup
    {
        $variant->loadMissing('masterProduct');
        $sku = $this->normalizeSku($variant->sku);

        if (! $sku) {
            SkuSyncGroup::query()
                ->where('master_product_variant_id', $variant->id)
                ->update(['master_product_variant_id' => null]);

            return null;
        }

        return DB::transaction(function () use ($variant, $sku) {
            $userId = $variant->user_id;
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();

            $currentGroup = SkuSyncGroup::query()
                ->where('master_product_variant_id', $variant->id)
                ->first();
            $matchingGroup = SkuSyncGroup::query()
                ->where('user_id', $userId)
                ->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)])
                ->first();

            if ($currentGroup && $matchingGroup && $currentGroup->id !== $matchingGroup->id) {
                $currentGroup->delete();
                $currentGroup = null;
            }

            $group = $matchingGroup ?? $currentGroup ?? SkuSyncGroup::create([
                'user_id' => $userId,
                'sku' => $sku,
                'master_stock' => $variant->stock,
                'is_active' => true,
            ]);

            $group->update([
                'master_product_variant_id' => $variant->id,
                'sku' => $sku,
                'master_stock' => $variant->stock,
            ]);

            $this->syncMembers($group, $this->matchingListings($userId, $sku));

            return $group->fresh(['members.store', 'members.product', 'members.variant']);
        });
    }

    public function syncProduct(Product $product): void
    {
        $product->loadMissing(['store', 'variantProducts']);
        if (! $product->store) {
            return;
        }

        $skus = $product->variantProducts
            ->pluck('model_sku')
            ->push($product->product_sku)
            ->map(fn ($sku) => $this->normalizeSku($sku))
            ->filter()
            ->map(fn ($sku) => mb_strtolower($sku))
            ->unique()
            ->values();

        MasterProductVariant::query()
            ->where('user_id', $product->store->user_id)
            ->where(function ($query) use ($skus, $product) {
                $query->when(
                    $skus->isNotEmpty(),
                    fn ($nested) => $nested->whereIn(DB::raw('LOWER(sku)'), $skus)
                )->orWhereHas(
                    'syncGroup.members',
                    fn ($memberQuery) => $memberQuery->where('product_id', $product->id)
                );
            })
            ->get()
            ->each(fn (MasterProductVariant $variant) => $this->syncVariant($variant));
    }

    public function detectUnlinkedSkus(int $userId): Collection
    {
        $existingSkus = MasterProductVariant::query()
            ->where('user_id', $userId)
            ->whereNotNull('sku')
            ->pluck('sku')
            ->map(fn ($sku) => mb_strtolower(trim($sku)))
            ->flip();

        return $this->allMarketplaceListings($userId)
            ->groupBy(fn (array $listing) => mb_strtolower($listing['sku']))
            ->filter(function (Collection $items, string $normalizedSku) use ($existingSkus) {
                return ! $existingSkus->has($normalizedSku)
                    && $items->pluck('store_id')->unique()->count() > 1;
            })
            ->map(function (Collection $items) {
                return [
                    'sku' => $items->first()['sku'],
                    'stores_count' => $items->pluck('store_id')->unique()->count(),
                    'items' => $items->values(),
                ];
            })
            ->values();
    }

    private function matchingListings(int $userId, string $sku): Collection
    {
        $normalizedSku = mb_strtolower($sku);
        $variants = VariantProduct::query()
            ->whereRaw('LOWER(model_sku) = ?', [$normalizedSku])
            ->whereHas('product.store', fn ($query) => $query->where('user_id', $userId))
            ->with(['product.store'])
            ->get()
            ->map(fn (VariantProduct $variant) => [
                'sku' => trim($variant->model_sku),
                'store_id' => $variant->product->store_id,
                'store_name' => $variant->product->store->store_name,
                'platform' => $variant->product->store->platform,
                'product_id' => $variant->product_id,
                'variant_product_id' => $variant->id,
                'platform_product_id' => (string) $variant->product->product_id,
                'platform_variant_id' => $variant->model_id ? (string) $variant->model_id : null,
                'product_name' => $variant->product->product_name,
                'variant_name' => $variant->variant_name ?: $variant->model_name,
                'stock' => (int) $variant->stock,
            ]);

        $products = Product::query()
            ->whereRaw('LOWER(product_sku) = ?', [$normalizedSku])
            ->whereHas('store', fn ($query) => $query->where('user_id', $userId))
            ->whereDoesntHave('variantProducts')
            ->with('store')
            ->get()
            ->map(fn (Product $product) => [
                'sku' => trim($product->product_sku),
                'store_id' => $product->store_id,
                'store_name' => $product->store->store_name,
                'platform' => $product->store->platform,
                'product_id' => $product->id,
                'variant_product_id' => null,
                'platform_product_id' => (string) $product->product_id,
                'platform_variant_id' => null,
                'product_name' => $product->product_name,
                'variant_name' => null,
                'stock' => (int) $product->stock,
            ]);

        return $variants->concat($products)->values();
    }

    private function allMarketplaceListings(int $userId): Collection
    {
        $variants = VariantProduct::query()
            ->whereNotNull('model_sku')
            ->whereHas('product.store', fn ($query) => $query->where('user_id', $userId))
            ->with(['product.store'])
            ->get()
            ->map(function (VariantProduct $variant) {
                $sku = $this->normalizeSku($variant->model_sku);
                if (! $sku || ! $variant->product?->store) {
                    return null;
                }

                return [
                    'sku' => $sku,
                    'store_id' => $variant->product->store_id,
                    'store_name' => $variant->product->store->store_name,
                    'platform' => $variant->product->store->platform,
                    'product_id' => $variant->product_id,
                    'variant_product_id' => $variant->id,
                    'platform_product_id' => (string) $variant->product->product_id,
                    'platform_variant_id' => $variant->model_id ? (string) $variant->model_id : null,
                    'product_name' => $variant->product->product_name,
                    'variant_name' => $variant->variant_name ?: $variant->model_name,
                    'stock' => (int) $variant->stock,
                ];
            })
            ->filter();

        $products = Product::query()
            ->whereNotNull('product_sku')
            ->whereHas('store', fn ($query) => $query->where('user_id', $userId))
            ->whereDoesntHave('variantProducts')
            ->with('store')
            ->get()
            ->map(function (Product $product) {
                $sku = $this->normalizeSku($product->product_sku);
                if (! $sku || ! $product->store) {
                    return null;
                }

                return [
                    'sku' => $sku,
                    'store_id' => $product->store_id,
                    'store_name' => $product->store->store_name,
                    'platform' => $product->store->platform,
                    'product_id' => $product->id,
                    'variant_product_id' => null,
                    'platform_product_id' => (string) $product->product_id,
                    'platform_variant_id' => null,
                    'product_name' => $product->product_name,
                    'variant_name' => null,
                    'stock' => (int) $product->stock,
                ];
            })
            ->filter();

        return $variants->concat($products)->values();
    }

    private function syncMembers(SkuSyncGroup $group, Collection $listings): void
    {
        $keptIds = [];

        foreach ($listings as $listing) {
            $memberQuery = $group->members()
                ->where('product_id', $listing['product_id']);
            $listing['variant_product_id']
                ? $memberQuery->where('variant_product_id', $listing['variant_product_id'])
                : $memberQuery->whereNull('variant_product_id');

            $member = $memberQuery->first() ?? new SkuSyncMember([
                'sku_sync_group_id' => $group->id,
                'sync_status' => 'idle',
            ]);
            $member->fill([
                'store_id' => $listing['store_id'],
                'product_id' => $listing['product_id'],
                'variant_product_id' => $listing['variant_product_id'],
                'platform_product_id' => $listing['platform_product_id'],
                'platform_variant_id' => $listing['platform_variant_id'],
            ]);
            $member->save();
            $keptIds[] = $member->id;
        }

        $group->members()
            ->when($keptIds !== [], fn ($query) => $query->whereNotIn('id', $keptIds))
            ->delete();
    }

    private function normalizeSku(mixed $sku): ?string
    {
        $sku = trim((string) $sku);

        return $sku !== '' && $sku !== '0' ? $sku : null;
    }
}
