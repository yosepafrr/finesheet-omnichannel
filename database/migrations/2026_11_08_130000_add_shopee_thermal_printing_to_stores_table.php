<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('shopee_thermal_printing')
                ->default(false)
                ->after('shipping_label_template');
        });

        DB::table('stores')->whereNotIn('shipping_label_size', ['a5', 'a6'])->update([
            'shipping_label_size' => 'a6',
        ]);
        DB::table('stores')->whereNotIn('packing_list_size', ['a5', 'a6'])->update([
            'packing_list_size' => 'a6',
        ]);
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('shopee_thermal_printing');
        });
    }
};
