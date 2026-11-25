<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
            $table->foreignId('destination_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
            $table->string('source_name', 100);
            $table->string('destination_name', 100);
            $table->decimal('amount', 20, 2);
            $table->decimal('fee', 20, 2)->default(0);
            $table->uuid('request_key');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
            $table->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transfers');
    }
};
