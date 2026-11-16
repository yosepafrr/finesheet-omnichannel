<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_product_variants', function (Blueprint $table) {
            $table->unsignedInteger('on_hand')->default(0)->after('stock');
            $table->unsignedInteger('on_order')->default(0)->after('on_hand');
            $table->unsignedInteger('reserved_stock')->default(0)->after('on_order');
        });

        DB::table('master_product_variants')
            ->select(['id', 'stock'])
            ->orderBy('id')
            ->chunkById(500, function ($variants) {
                foreach ($variants as $variant) {
                    DB::table('master_product_variants')
                        ->where('id', $variant->id)
                        ->update(['on_hand' => max(0, (int) $variant->stock)]);
                }
            });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('master_product_variant_id')
                ->constrained('master_product_variants')
                ->cascadeOnDelete();
            $table->string('type', 40);
            $table->integer('on_hand_delta')->default(0);
            $table->integer('on_order_delta')->default(0);
            $table->integer('reserved_delta')->default(0);
            $table->unsignedInteger('on_hand_after');
            $table->unsignedInteger('on_order_after');
            $table->unsignedInteger('reserved_after');
            $table->unsignedInteger('available_after');
            $table->nullableMorphs('reference');
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('notes', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['master_product_variant_id', 'occurred_at']);
            $table->index(['user_id', 'type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');

        Schema::table('master_product_variants', function (Blueprint $table) {
            $table->dropColumn(['on_hand', 'on_order', 'reserved_stock']);
        });
    }
};
