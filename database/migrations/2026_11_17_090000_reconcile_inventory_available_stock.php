<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('master_product_variants')
            ->select(['id', 'on_hand', 'on_order', 'reserved_stock'])
            ->orderBy('id')
            ->chunkById(500, function ($variants) {
                foreach ($variants as $variant) {
                    $available = max(
                        0,
                        (int) $variant->on_hand
                            - (int) $variant->on_order
                            - (int) $variant->reserved_stock
                    );

                    DB::table('master_product_variants')
                        ->where('id', $variant->id)
                        ->update(['stock' => $available]);

                    DB::table('sku_sync_groups')
                        ->where('master_product_variant_id', $variant->id)
                        ->update(['master_stock' => $available]);
                }
            });
    }

    public function down(): void
    {
        // This data reconciliation cannot be safely reversed.
    }
};
