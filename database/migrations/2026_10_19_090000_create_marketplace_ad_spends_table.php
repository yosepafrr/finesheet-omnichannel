<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_ad_spends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 30);
            $table->date('spend_date');
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('currency', 10)->default('IDR');
            $table->json('raw_data')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'platform', 'spend_date']);
            $table->index(['user_id', 'spend_date']);
            $table->index(['platform', 'spend_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_ad_spends');
    }
};
