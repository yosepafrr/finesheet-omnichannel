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
        Schema::table('variant_products', function (Blueprint $table) {
            $table->json('tier_index')->nullable();
            $table->string('variant_name')->nullable();
            $table->json('variant_options')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('variant_products', function (Blueprint $table) {
            $table->dropColumn(['tier_index', 'variant_name', 'variant_options']);
        });
    }
};
