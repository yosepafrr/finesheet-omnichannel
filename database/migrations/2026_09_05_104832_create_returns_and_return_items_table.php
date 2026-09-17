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
        Schema::create('order_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('platform', 50);
            $table->string('external_return_id', 100);
            $table->string('return_status', 100)->nullable();
            $table->string('normalized_status', 50)->default('UNKNOWN');
            $table->string('return_type', 100)->nullable();
            $table->decimal('refund_amount', 15, 2)->nullable();
            $table->string('return_reason')->nullable();
            $table->text('text_reason')->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamp('created_at_platform')->nullable();
            $table->timestamp('updated_at_platform')->nullable();
            $table->timestamps();

            // Constraint: one unique return per platform
            $table->unique(['platform', 'external_return_id']);
        });

        Schema::create('order_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_return_id')->constrained('order_returns')->cascadeOnDelete();
            $table->string('external_line_item_id')->nullable();
            $table->string('sku_id')->nullable();
            $table->string('product_name')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('refund_amount', 15, 2)->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_return_items');
        Schema::dropIfExists('order_returns');
    }
};
