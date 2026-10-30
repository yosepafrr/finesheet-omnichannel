<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_ads_connections', function (Blueprint $table) {
            $table->dropUnique('tiktok_ads_connections_user_id_unique');
            $table->index('user_id', 'tiktok_ads_connections_user_id_index');
        });
    }

    public function down(): void
    {
        DB::table('tiktok_ads_connections')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id')
            ->each(function ($userId): void {
                $connectionIds = DB::table('tiktok_ads_connections')
                    ->where('user_id', $userId)
                    ->orderByDesc('authorized_at')
                    ->orderByDesc('id')
                    ->pluck('id');
                $keeperId = $connectionIds->shift();

                DB::table('tiktok_ad_accounts')
                    ->whereIn('tiktok_ads_connection_id', $connectionIds)
                    ->update(['tiktok_ads_connection_id' => $keeperId]);
                DB::table('tiktok_ads_connections')
                    ->whereIn('id', $connectionIds)
                    ->delete();
            });

        Schema::table('tiktok_ads_connections', function (Blueprint $table) {
            $table->dropIndex('tiktok_ads_connections_user_id_index');
            $table->unique('user_id');
        });
    }
};
