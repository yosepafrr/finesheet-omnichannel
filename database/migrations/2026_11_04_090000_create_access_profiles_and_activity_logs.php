<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('layout', 40)->default('custom');
            $table->json('permissions')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('access_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_activity_logs');
        Schema::dropIfExists('access_profiles');
    }
};
