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
        // 1. Create suppliers table
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        // 2. Add supplier_id to products
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('store_id')->constrained('suppliers')->onDelete('set null');
        });

        // 3. Create supplier_product_mappings table
        Schema::create('supplier_product_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('supplier_id')->constrained('suppliers')->onDelete('cascade');
            $table->string('sku')->nullable()->index();
            $table->string('platform_product_id')->nullable()->index();
            $table->foreignId('product_id')->nullable()->constrained('products')->onDelete('cascade');
            $table->timestamps();
        });

        // 4. Update payable_events: drop old source_unique, add supplier_id, and add new index
        Schema::table('payable_events', function (Blueprint $table) {
            $table->dropUnique('payable_events_source_unique');
            $table->foreignId('supplier_id')->nullable()->after('payable_period_id')->constrained('suppliers')->onDelete('cascade');
            $table->index(['user_id', 'source_id', 'source_type', 'supplier_id'], 'payable_events_lookup_index');
        });

        // 5. Add supplier_id to payable_payments
        Schema::table('payable_payments', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('payable_period_id')->constrained('suppliers')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payable_payments', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropColumn('supplier_id');
        });

        Schema::table('payable_events', function (Blueprint $table) {
            $table->dropIndex('payable_events_lookup_index');
            $table->dropForeign(['supplier_id']);
            $table->dropColumn('supplier_id');
            $table->unique(['source_id', 'source_type'], 'payable_events_source_unique');
        });

        Schema::dropIfExists('supplier_product_mappings');

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropColumn('supplier_id');
        });

        Schema::dropIfExists('suppliers');
    }
};
