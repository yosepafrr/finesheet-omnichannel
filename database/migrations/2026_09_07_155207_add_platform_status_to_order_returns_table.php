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
        Schema::table('order_returns', function (Blueprint $table) {
            $table->string('platform_status', 100)->nullable()->after('external_return_id');
        });

        // Copy existing return_status to platform_status
        \Illuminate\Support\Facades\DB::table('order_returns')
            ->whereNull('platform_status')
            ->update(['platform_status' => \Illuminate\Support\Facades\DB::raw('return_status')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_returns', function (Blueprint $table) {
            $table->dropColumn('platform_status');
        });
    }
};
