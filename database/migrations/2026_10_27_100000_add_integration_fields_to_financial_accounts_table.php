<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->string('source_type', 50)->nullable()->after('subtype');
            $table->timestamp('synced_at')->nullable()->after('balance');
            $table->unique(['user_id', 'source_type']);
        });
    }

    public function down(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'source_type']);
            $table->dropColumn(['source_type', 'synced_at']);
        });
    }
};
