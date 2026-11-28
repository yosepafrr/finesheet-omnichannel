<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_boost_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->timestamp('next_check_at')->nullable()->index();
            $table->timestamp('checked_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
        Schema::create('shopee_product_boosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('item_id', 30);
            $table->boolean('selected')->default(false);
            $table->timestamp('last_boosted_at')->nullable();
            $table->timestamp('active_until')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->unsignedInteger('boost_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_product_boosts');
        Schema::dropIfExists('shopee_boost_settings');
    }
};
