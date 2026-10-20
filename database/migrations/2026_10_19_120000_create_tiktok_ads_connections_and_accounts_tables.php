<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_ads_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('access_token');
            $table->json('advertiser_ids')->nullable();
            $table->json('scopes')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('tiktok_ad_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tiktok_ads_connection_id')
                ->constrained('tiktok_ads_connections')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('advertiser_id');
            $table->string('advertiser_name')->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('timezone', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'advertiser_id']);
            $table->index(['store_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_ad_accounts');
        Schema::dropIfExists('tiktok_ads_connections');
    }
};
