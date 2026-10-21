<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['store_id', 'order_time'], 'orders_store_order_time_profit_idx');
        });

        Schema::table('order_returns', function (Blueprint $table) {
            $table->index(['order_id', 'created_at_platform'], 'returns_order_created_profit_idx');
        });

        Schema::table('order_packages', function (Blueprint $table) {
            $table->index(
                ['order_id', 'normalized_logistics_status', 'failed_at'],
                'packages_order_failed_profit_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('order_packages', function (Blueprint $table) {
            $table->dropIndex('packages_order_failed_profit_idx');
        });

        Schema::table('order_returns', function (Blueprint $table) {
            $table->dropIndex('returns_order_created_profit_idx');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_store_order_time_profit_idx');
        });
    }
};
