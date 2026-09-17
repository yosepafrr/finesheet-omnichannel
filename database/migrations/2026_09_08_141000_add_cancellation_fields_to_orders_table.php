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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('cancel_source', 100)->nullable()->after('order_status');
            $table->text('cancel_reason')->nullable()->after('cancel_source');
            $table->text('buyer_cancel_reason')->nullable()->after('cancel_reason');
            $table->string('normalized_cancel_category', 50)->nullable()->index()->after('buyer_cancel_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'cancel_source',
                'cancel_reason',
                'buyer_cancel_reason',
                'normalized_cancel_category',
            ]);
        });
    }
};
