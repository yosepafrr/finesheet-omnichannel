<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payable_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->dateTime('start_date');
            $table->dateTime('end_date');
            $table->string('payment_status')->default('UNPAID'); // UNPAID, PARTIAL, PAID
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
        });

        Schema::create('payable_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payable_period_id')->constrained('payable_periods')->onDelete('cascade');
            $table->foreignId('store_id')->nullable()->constrained('stores')->onDelete('set null');
            $table->string('platform');
            $table->string('source_id')->nullable();
            $table->string('source_type'); // CREATE_ORDER, RETURN_ORDER, FAILED_DELIVERY, MANUAL_ADJUSTMENT
            $table->dateTime('event_date');
            $table->decimal('amount', 15, 2); // Positive for debt, negative for reduction
            $table->boolean('is_manual_moved')->default(false);
            $table->foreignId('original_period_id')->nullable()->constrained('payable_periods')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'source_type'], 'payable_events_source_unique');
        });

        Schema::create('payable_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payable_period_id')->constrained('payable_periods')->onDelete('cascade');
            $table->dateTime('payment_date');
            $table->decimal('amount', 15, 2);
            $table->string('payment_method')->nullable();
            $table->string('proof_file_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('payable_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('payable_period_id')->nullable()->constrained('payable_periods')->onDelete('cascade');
            $table->string('action'); // MOVE_EVENT, ADD_PAYMENT, etc.
            $table->text('description');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payable_audits');
        Schema::dropIfExists('payable_payments');
        Schema::dropIfExists('payable_events');
        Schema::dropIfExists('payable_periods');
    }
};
