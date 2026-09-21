<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('master_products', 'reference_store_id')) {
            Schema::table('master_products', function (Blueprint $table) {
                $table->foreignId('reference_store_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('stores')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('master_products', 'source')) {
            Schema::table('master_products', function (Blueprint $table) {
                $table->string('source', 20)->default('manual')->after('status');
            });
        }

        Schema::table('master_product_variants', function (Blueprint $table) {
            $table->string('sku')->nullable()->change();
        });

        $listingsTableExists = Schema::hasTable('master_product_variant_listings');

        if (! $listingsTableExists) {
            Schema::create('master_product_variant_listings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('master_product_variant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('variant_product_id')->nullable()->constrained('variant_products')->nullOnDelete();
                $table->string('listing_key')->unique();
                $table->string('platform_product_id');
                $table->string('platform_variant_id')->nullable();
                $table->integer('snapshot_stock')->default(0);
                $table->decimal('snapshot_price', 15, 2)->default(0);
                $table->timestamps();

                $table->index(['store_id', 'product_id']);
            });
        } else {
            Schema::table('master_product_variant_listings', function (Blueprint $table) {
                $table->dropForeign(['variant_product_id']);
                $table->foreign('variant_product_id')
                    ->references('id')
                    ->on('variant_products')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('master_product_variant_listings');

        DB::table('master_product_variants')
            ->whereNull('sku')
            ->orderBy('id')
            ->eachById(function ($variants) {
                foreach ($variants as $variant) {
                    DB::table('master_product_variants')
                        ->where('id', $variant->id)
                        ->update(['sku' => "__rollback_no_sku_{$variant->id}"]);
                }
            });

        Schema::table('master_product_variants', function (Blueprint $table) {
            $table->string('sku')->nullable(false)->change();
        });

        if (Schema::hasColumn('master_products', 'reference_store_id')) {
            Schema::table('master_products', function (Blueprint $table) {
                $table->dropConstrainedForeignId('reference_store_id');
            });
        }

        if (Schema::hasColumn('master_products', 'source')) {
            Schema::table('master_products', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
