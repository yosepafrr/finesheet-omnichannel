<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category', 20);
            $table->string('subtype', 30);
            $table->decimal('balance', 20, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'category']);
        });

        Schema::create('financial_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('title');
            $table->string('counterparty')->nullable();
            $table->decimal('amount', 20, 2);
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'kind', 'status']);
        });

        Schema::create('cashflow_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_account_id')
                ->nullable()
                ->constrained('financial_accounts')
                ->nullOnDelete();
            $table->string('direction', 20);
            $table->string('category')->nullable();
            $table->string('description');
            $table->decimal('amount', 20, 2);
            $table->dateTime('occurred_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashflow_transactions');
        Schema::dropIfExists('financial_obligations');
        Schema::dropIfExists('financial_accounts');
    }
};
