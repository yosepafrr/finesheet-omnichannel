<?php

use App\Services\OrderListSortService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('ready_to_ship_at')->nullable();
            $table->index(['store_id', 'ready_to_ship_at', 'id'], 'orders_store_ready_sort_index');
            $table->index(['store_id', 'ship_by_date', 'id'], 'orders_store_deadline_sort_index');
        });

        $sort = new OrderListSortService;
        DB::table('orders')->select(['id', 'platform', 'raw_data', 'ship_by_date'])
            ->whereNotNull('raw_data')->chunkById(500, function ($rows) use ($sort) {
                foreach ($rows as $row) {
                    $raw = json_decode($row->raw_data, true);
                    if (! is_array($raw)) continue;
                    $dates = $sort->dates($raw, (string) $row->platform);
                    if ($row->ship_by_date !== null) unset($dates['ship_by_date']);
                    if ($dates !== []) DB::table('orders')->where('id', $row->id)->update($dates);
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_store_ready_sort_index');
            $table->dropIndex('orders_store_deadline_sort_index');
            $table->dropColumn('ready_to_ship_at');
        });
    }
};
