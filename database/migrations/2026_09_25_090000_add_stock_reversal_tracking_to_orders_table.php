<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->json('stock_sync_deductions')->nullable()->after('stock_sync_processed_at');
            $table->timestamp('stock_sync_reverted_at')->nullable()->after('stock_sync_deductions');
            $table->timestamp('stock_sync_shipped_at')->nullable()->after('stock_sync_reverted_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'stock_sync_deductions',
                'stock_sync_reverted_at',
                'stock_sync_shipped_at',
            ]);
        });
    }
};
