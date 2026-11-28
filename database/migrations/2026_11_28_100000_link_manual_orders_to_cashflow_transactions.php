<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashflow_transactions', function (Blueprint $table) {
            $table->foreignId('manual_order_id')->nullable()->unique()->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cashflow_transactions', function (Blueprint $table) {
            $table->dropUnique(['manual_order_id']);
            $table->dropConstrainedForeignId('manual_order_id');
        });
    }
};
