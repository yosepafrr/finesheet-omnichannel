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
        Schema::table('items', function (Blueprint $table) {
            $table->unsignedBigInteger('hpp')->nullable()->change();
        });

        Schema::table('variant_items', function (Blueprint $table) {
            $table->unsignedBigInteger('hpp')->nullable()->change();
            $table->unsignedBigInteger('price')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->decimal('hpp', 15, 2)->nullable()->change();
        });

        Schema::table('variant_items', function (Blueprint $table) {
            $table->decimal('hpp', 15, 2)->nullable()->change();
            $table->decimal('price', 15, 2)->nullable()->change();
        });
    }
};
