<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sku_sync_members', function (Blueprint $table) {
            $table->string('sync_status', 20)->default('idle')->after('platform_variant_id');
            $table->timestamp('sync_requested_at')->nullable()->after('sync_status');
            $table->timestamp('last_synced_at')->nullable()->after('sync_requested_at');
            $table->text('last_sync_error')->nullable()->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('sku_sync_members', function (Blueprint $table) {
            $table->dropColumn([
                'sync_status',
                'sync_requested_at',
                'last_synced_at',
                'last_sync_error',
            ]);
        });
    }
};
