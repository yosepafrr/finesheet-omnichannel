<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('master_product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->string('variant_name')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('hpp', 15, 2)->default(0);
            $table->integer('stock')->default(0);
            $table->json('attributes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'sku']);
            $table->index(['master_product_id', 'is_active']);
        });

        Schema::table('sku_sync_groups', function (Blueprint $table) {
            $table->foreignId('master_product_variant_id')
                ->nullable()
                ->unique()
                ->after('user_id')
                ->constrained('master_product_variants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sku_sync_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('master_product_variant_id');
        });

        Schema::dropIfExists('master_product_variants');
        Schema::dropIfExists('master_products');
    }
};
