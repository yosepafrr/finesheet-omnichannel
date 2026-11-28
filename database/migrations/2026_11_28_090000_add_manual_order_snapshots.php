<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('manual_request_key')->nullable();
            $table->unique(['store_id', 'manual_request_key']);
        });
        Schema::table('order_products', fn (Blueprint $table) => $table->json('manual_snapshot')->nullable());
    }

    public function down(): void
    {
        Schema::table('order_products', fn (Blueprint $table) => $table->dropColumn('manual_snapshot'));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'manual_request_key']);
            $table->dropColumn('manual_request_key');
        });
    }
};
