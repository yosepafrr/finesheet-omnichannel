<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop foreign keys first to allow renaming table and columns safely
        // In order_items
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });

        // In variant_items
        Schema::table('variant_items', function (Blueprint $table) {
            $table->dropForeign(['item_id']); // actually it points to items.id
        });

        // Rename Tables
        Schema::rename('items', 'products');
        Schema::rename('order_items', 'order_products');
        Schema::rename('variant_items', 'variant_products');

        // Rename Columns in products
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('item_id', 'product_id');
            $table->renameColumn('item_name', 'product_name');
            $table->renameColumn('item_sku', 'product_sku');
            $table->renameColumn('item_status', 'product_status');
        });

        // Rename Columns in order_products
        Schema::table('order_products', function (Blueprint $table) {
            $table->renameColumn('item_id', 'product_id');
            $table->renameColumn('item_name', 'product_name');
        });

        // Rename Columns in variant_products
        Schema::table('variant_products', function (Blueprint $table) {
            $table->renameColumn('item_id', 'product_id');
        });

        // Rename Columns in orders
        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('item_id', 'product_id');
        });

        // Restore Foreign Keys
        Schema::table('order_products', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });

        Schema::table('variant_products', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });

        Schema::table('variant_products', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('product_id', 'item_id');
        });

        Schema::table('variant_products', function (Blueprint $table) {
            $table->renameColumn('product_id', 'item_id');
        });

        Schema::table('order_products', function (Blueprint $table) {
            $table->renameColumn('product_id', 'item_id');
            $table->renameColumn('product_name', 'item_name');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('product_id', 'item_id');
            $table->renameColumn('product_name', 'item_name');
            $table->renameColumn('product_sku', 'item_sku');
            $table->renameColumn('product_status', 'item_status');
        });

        Schema::rename('products', 'items');
        Schema::rename('order_products', 'order_items');
        Schema::rename('variant_products', 'variant_items');

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });

        Schema::table('variant_items', function (Blueprint $table) {
            $table->foreign('item_id')->references('id')->on('items')->onDelete('cascade');
        });
    }
};
