<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(
                ['store_id', 'order_status', 'order_time'],
                'orders_store_status_time_list_idx',
            );
            $table->index(
                ['store_id', 'platform', 'order_status', 'order_time'],
                'orders_store_platform_status_time_list_idx',
            );
        });

        Schema::table('order_products', function (Blueprint $table) {
            $table->index('order_id', 'order_products_order_list_idx');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index(['store_id', 'created_at'], 'products_store_created_list_idx');
        });

        Schema::table('variant_products', function (Blueprint $table) {
            $table->index(['product_id', 'model_name'], 'variants_product_name_list_idx');
            $table->index(['product_id', 'model_sku'], 'variants_product_sku_list_idx');
        });
    }

    public function down(): void
    {
        Schema::table('variant_products', function (Blueprint $table) {
            $table->dropIndex('variants_product_sku_list_idx');
            $table->dropIndex('variants_product_name_list_idx');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_store_created_list_idx');
        });

        Schema::table('order_products', function (Blueprint $table) {
            $table->dropIndex('order_products_order_list_idx');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_store_platform_status_time_list_idx');
            $table->dropIndex('orders_store_status_time_list_idx');
        });
    }
};
