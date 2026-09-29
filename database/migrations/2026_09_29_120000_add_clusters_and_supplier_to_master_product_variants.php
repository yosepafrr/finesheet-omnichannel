<?php

use App\Services\MasterProductClusterService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_product_variants', function (Blueprint $table) {
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('user_id')
                ->constrained('suppliers')
                ->nullOnDelete();
            $table->string('product_cluster_key', 160)->nullable()->after('sku');
            $table->json('variant_cluster_keys')->nullable()->after('product_cluster_key');

            $table->index(['user_id', 'product_cluster_key'], 'master_variants_user_product_cluster_index');
            $table->index(['user_id', 'supplier_id'], 'master_variants_user_supplier_index');
        });

        $clusterService = app(MasterProductClusterService::class);
        $supplierMappings = DB::table('supplier_product_mappings')
            ->whereNotNull('sku')
            ->orderBy('id')
            ->get(['user_id', 'supplier_id', 'sku'])
            ->mapWithKeys(fn ($mapping) => [
                $mapping->user_id.'|'.mb_strtolower(trim((string) $mapping->sku)) => $mapping->supplier_id,
            ]);

        DB::table('master_product_variants')
            ->orderBy('id')
            ->chunkById(500, function ($variants) use ($clusterService, $supplierMappings) {
                foreach ($variants as $variant) {
                    $clusters = $clusterService->fromSku($variant->sku);
                    $mappingKey = $variant->user_id.'|'.mb_strtolower(trim((string) $variant->sku));

                    DB::table('master_product_variants')
                        ->where('id', $variant->id)
                        ->update([
                            'supplier_id' => $supplierMappings->get($mappingKey),
                            'product_cluster_key' => $clusters['product_key'],
                            'variant_cluster_keys' => json_encode($clusters['variant_keys']),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('master_product_variants', function (Blueprint $table) {
            $table->dropIndex('master_variants_user_product_cluster_index');
            $table->dropIndex('master_variants_user_supplier_index');
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['product_cluster_key', 'variant_cluster_keys']);
        });
    }
};
