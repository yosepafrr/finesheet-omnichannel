<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_ad_campaign_spends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_ad_spend_id')
                ->constrained('marketplace_ad_spends')
                ->cascadeOnDelete();
            $table->foreignId('tiktok_ad_account_id')
                ->constrained('tiktok_ad_accounts')
                ->cascadeOnDelete();
            $table->string('campaign_id');
            $table->string('campaign_name')->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->json('raw_data')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['marketplace_ad_spend_id', 'campaign_id'],
                'tiktok_campaign_spend_daily_unique',
            );
            $table->index(['tiktok_ad_account_id', 'campaign_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_ad_campaign_spends');
    }
};
