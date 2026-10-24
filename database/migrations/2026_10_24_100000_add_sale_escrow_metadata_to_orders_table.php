<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('sale_escrow_source', 64)
                ->nullable()
                ->after('sale_escrow_amount');
            $table->boolean('sale_escrow_is_estimated')
                ->default(false)
                ->after('sale_escrow_source');
            $table->unsignedSmallInteger('sale_escrow_sample_size')
                ->default(0)
                ->after('sale_escrow_is_estimated');
            $table->decimal('sale_escrow_ratio', 8, 6)
                ->nullable()
                ->after('sale_escrow_sample_size');
        });

        DB::table('orders')
            ->whereNotNull('sale_escrow_amount')
            ->where('sale_escrow_amount', '>', 0)
            ->whereNull('sale_escrow_source')
            ->update([
                'sale_escrow_source' => 'platform_snapshot',
                'sale_escrow_is_estimated' => false,
            ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'sale_escrow_source',
                'sale_escrow_is_estimated',
                'sale_escrow_sample_size',
                'sale_escrow_ratio',
            ]);
        });
    }
};
