<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('fulfillment_handover_method', 20)
                ->default('pickup')
                ->after('platform_shop_id');
            $table->string('shipping_label_template', 20)
                ->default('platform')
                ->after('fulfillment_handover_method');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'fulfillment_handover_method',
                'shipping_label_template',
            ]);
        });
    }
};
