<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payable_payments', 'notes')) {
            Schema::table('payable_payments', function (Blueprint $table) {
                $table->dropColumn('notes');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('payable_payments', 'notes')) {
            Schema::table('payable_payments', function (Blueprint $table) {
                $table->text('notes')->nullable();
            });
        }
    }
};
