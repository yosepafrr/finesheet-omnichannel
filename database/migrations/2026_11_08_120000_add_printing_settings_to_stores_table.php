<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('print_shipping_label')->default(true)->after('shipping_label_template');
            $table->boolean('print_packing_list')->default(false)->after('print_shipping_label');
            $table->boolean('print_pick_list')->default(false)->after('print_packing_list');
            $table->string('shipping_label_size', 10)->default('a6')->after('print_pick_list');
            $table->string('packing_list_size', 10)->default('a6')->after('shipping_label_size');
            $table->string('pick_list_size', 10)->default('a4')->after('packing_list_size');
            $table->string('printing_layout', 20)->default('separate')->after('pick_list_size');
            $table->string('printing_file_format', 10)->default('pdf')->after('printing_layout');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'print_shipping_label',
                'print_packing_list',
                'print_pick_list',
                'shipping_label_size',
                'packing_list_size',
                'pick_list_size',
                'printing_layout',
                'printing_file_format',
            ]);
        });
    }
};
