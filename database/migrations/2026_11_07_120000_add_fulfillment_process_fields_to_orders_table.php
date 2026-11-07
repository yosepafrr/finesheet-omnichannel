<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('fulfillment_status', 24)->nullable()->index();
            $table->text('fulfillment_error')->nullable();
            $table->timestamp('fulfillment_requested_at')->nullable();
            $table->timestamp('fulfillment_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['fulfillment_status']);
            $table->dropColumn([
                'fulfillment_status',
                'fulfillment_error',
                'fulfillment_requested_at',
                'fulfillment_completed_at',
            ]);
        });
    }
};
