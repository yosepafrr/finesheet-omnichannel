<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('stores')
            ->whereRaw("LOWER(REPLACE(REPLACE(REPLACE(platform, ' ', ''), '-', ''), '_', '')) = ?", ['tiktokshop'])
            ->update(['print_packing_list' => true]);

        DB::table('stores')
            ->where('shipping_label_template', 'finesheet')
            ->update(['shipping_label_template' => 'platform']);
    }

    public function down(): void
    {
        DB::table('stores')
            ->whereRaw("LOWER(REPLACE(REPLACE(REPLACE(platform, ' ', ''), '-', ''), '_', '')) = ?", ['tiktokshop'])
            ->update(['print_packing_list' => false]);
    }
};
