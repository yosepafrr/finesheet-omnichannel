<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('master_product_variant_listings')->delete();
            DB::table('master_products')->update(['reference_store_id' => null]);
            DB::table('master_products')->where('source', 'automatic')->delete();

            DB::table('sku_sync_groups')
                ->whereNull('master_product_variant_id')
                ->orderBy('id')
                ->get()
                ->each(fn ($group) => $this->convertLegacyGroup($group));
        });
    }

    public function down(): void
    {
        // Generated catalog rows cannot be reconstructed without syncing marketplace data again.
    }

    private function convertLegacyGroup(object $group): void
    {
        $sku = trim((string) $group->sku);
        if ($sku === '' || $sku === '0') {
            DB::table('sku_sync_groups')->where('id', $group->id)->delete();

            return;
        }

        $existingVariant = DB::table('master_product_variants as variants')
            ->join('master_products as products', 'products.id', '=', 'variants.master_product_id')
            ->where('variants.user_id', $group->user_id)
            ->where('products.source', 'manual')
            ->whereRaw('LOWER(variants.sku) = ?', [mb_strtolower($sku)])
            ->select('variants.id')
            ->first();

        if ($existingVariant) {
            $this->attachOrMergeGroup($group, (int) $existingVariant->id);

            return;
        }

        $listing = DB::table('sku_sync_members as members')
            ->join('products', 'products.id', '=', 'members.product_id')
            ->leftJoin('variant_products as variants', 'variants.id', '=', 'members.variant_product_id')
            ->where('members.sku_sync_group_id', $group->id)
            ->select([
                'products.product_name',
                'products.category',
                'products.image',
                'products.hpp as product_hpp',
                'variants.variant_name',
                'variants.model_name',
                'variants.variant_image',
                'variants.hpp as variant_hpp',
            ])
            ->first();

        $timestamp = now();
        $productId = DB::table('master_products')->insertGetId([
            'user_id' => $group->user_id,
            'reference_store_id' => null,
            'name' => $listing?->product_name ?: $sku,
            'brand' => null,
            'category' => $listing?->category,
            'description' => null,
            'image' => $listing?->variant_image ?: $listing?->image,
            'status' => 'active',
            'source' => 'manual',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $variantId = DB::table('master_product_variants')->insertGetId([
            'master_product_id' => $productId,
            'user_id' => $group->user_id,
            'sku' => $sku,
            'variant_name' => $listing?->variant_name ?: $listing?->model_name,
            'barcode' => null,
            'hpp' => $listing?->variant_hpp ?? $listing?->product_hpp ?? 0,
            'stock' => max(0, (int) $group->master_stock),
            'attributes' => null,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('sku_sync_groups')->where('id', $group->id)->update([
            'master_product_variant_id' => $variantId,
            'sku' => $sku,
            'updated_at' => $timestamp,
        ]);
    }

    private function attachOrMergeGroup(object $group, int $variantId): void
    {
        $attachedGroup = DB::table('sku_sync_groups')
            ->where('master_product_variant_id', $variantId)
            ->first();

        if (! $attachedGroup) {
            DB::table('master_product_variants')->where('id', $variantId)->update([
                'stock' => max(0, (int) $group->master_stock),
                'updated_at' => now(),
            ]);
            DB::table('sku_sync_groups')->where('id', $group->id)->update([
                'master_product_variant_id' => $variantId,
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('sku_sync_members')
            ->where('sku_sync_group_id', $group->id)
            ->orderBy('id')
            ->get()
            ->each(function ($member) use ($attachedGroup) {
                $duplicate = DB::table('sku_sync_members')
                    ->where('sku_sync_group_id', $attachedGroup->id)
                    ->where('product_id', $member->product_id)
                    ->when(
                        $member->variant_product_id,
                        fn ($query, $variantId) => $query->where('variant_product_id', $variantId),
                        fn ($query) => $query->whereNull('variant_product_id')
                    )
                    ->exists();

                if ($duplicate) {
                    DB::table('sku_sync_members')->where('id', $member->id)->delete();
                } else {
                    DB::table('sku_sync_members')->where('id', $member->id)->update([
                        'sku_sync_group_id' => $attachedGroup->id,
                        'updated_at' => now(),
                    ]);
                }
            });

        DB::table('sku_sync_groups')->where('id', $group->id)->delete();
    }
};
