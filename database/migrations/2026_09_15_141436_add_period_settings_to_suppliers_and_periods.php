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
        Schema::table('suppliers', function (Blueprint $table) {
            $table->integer('period_length_days')->nullable()->after('notes');
            $table->dateTime('first_period_start')->nullable()->after('period_length_days');
        });

        Schema::table('payable_periods', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('user_id')->constrained('suppliers')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payable_periods', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropColumn('supplier_id');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('first_period_start');
            $table->dropColumn('period_length_days');
        });
    }
};
