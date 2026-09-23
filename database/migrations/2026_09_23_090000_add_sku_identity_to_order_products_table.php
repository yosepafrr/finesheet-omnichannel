<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $table->string('platform_variant_id')->nullable()->after('model_name');
            $table->string('sku')->nullable()->after('platform_variant_id');

            $table->index(['product_id', 'platform_variant_id']);
            $table->index('sku');
        });

        DB::table('orders')
            ->whereNotNull('raw_data')
            ->select(['id', 'platform', 'raw_data'])
            ->orderBy('id')
            ->chunkById(200, function ($orders) {
                foreach ($orders as $order) {
                    $rawData = is_string($order->raw_data)
                        ? json_decode($order->raw_data, true)
                        : (array) $order->raw_data;
                    $items = strtolower((string) $order->platform) === 'shopee'
                        ? ($rawData['item_list'] ?? [])
                        : ($rawData['line_items'] ?? []);

                    foreach (is_array($items) ? $items : [] as $item) {
                        $productId = $item['item_id'] ?? $item['product_id'] ?? null;
                        if (! $productId) {
                            continue;
                        }

                        $isShopee = strtolower((string) $order->platform) === 'shopee';
                        $modelName = $isShopee
                            ? ($item['model_name'] ?? 'without variant')
                            : ($item['sku_name'] ?? 'without variant');
                        $variantId = $isShopee ? ($item['model_id'] ?? null) : ($item['sku_id'] ?? null);
                        $sku = $isShopee
                            ? ($item['model_sku'] ?? $item['item_sku'] ?? null)
                            : ($item['seller_sku'] ?? null);

                        DB::table('order_products')
                            ->where('order_id', $order->id)
                            ->where('product_id', $productId)
                            ->where('model_name', $modelName)
                            ->update([
                                'platform_variant_id' => $variantId !== null ? (string) $variantId : null,
                                'sku' => $sku,
                            ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'platform_variant_id']);
            $table->dropIndex(['sku']);
            $table->dropColumn(['platform_variant_id', 'sku']);
        });
    }
};
