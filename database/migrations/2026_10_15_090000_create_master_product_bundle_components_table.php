<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_product_variants', function (Blueprint $table) {
            $table->boolean('is_bundle')->default(false)->after('is_active');
            $table->index(['user_id', 'is_bundle']);
        });

        Schema::create('master_product_bundle_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_variant_id')
                ->constrained('master_product_variants')
                ->cascadeOnDelete();
            $table->foreignId('component_variant_id')
                ->constrained('master_product_variants')
                ->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['bundle_variant_id', 'component_variant_id'], 'bundle_component_unique');
            $table->index('component_variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_product_bundle_components');

        Schema::table('master_product_variants', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_bundle']);
            $table->dropColumn('is_bundle');
        });
    }
};
