<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add user_id to payable_periods
        if (!Schema::hasColumn('payable_periods', 'user_id')) {
            Schema::table('payable_periods', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->onDelete('cascade');
            });
        }

        // 2. Add user_id to payable_events
        if (!Schema::hasColumn('payable_events', 'user_id')) {
            Schema::table('payable_events', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->onDelete('cascade');
            });
        }

        // 3. Add user_id to payable_payments
        if (!Schema::hasColumn('payable_payments', 'user_id')) {
            Schema::table('payable_payments', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->onDelete('cascade');
            });
        }

        // 4. Add user_id to settings & update unique index
        if (!Schema::hasColumn('settings', 'user_id')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->onDelete('cascade');
            });

            // In MySQL/SQLite, drop old unique on 'key' and create composite unique on ['user_id', 'key']
            try {
                Schema::table('settings', function (Blueprint $table) {
                    $table->dropUnique(['key']);
                });
            } catch (\Throwable $e) {
                // Ignore if unique index was named differently or already dropped
            }

            try {
                Schema::table('settings', function (Blueprint $table) {
                    $table->unique(['user_id', 'key']);
                });
            } catch (\Throwable $e) {
                // Ignore if already created
            }
        }

        // 5. Backfill existing data to user_id = 1 (yosepafrr)
        // Check if user 1 exists, otherwise fallback to first user
        $firstUser = DB::table('users')->where('id', 1)->first() ?? DB::table('users')->first();
        if ($firstUser) {
            $defaultUserId = $firstUser->id;
            DB::table('payable_periods')->whereNull('user_id')->update(['user_id' => $defaultUserId]);
            DB::table('payable_events')->whereNull('user_id')->update(['user_id' => $defaultUserId]);
            DB::table('payable_payments')->whereNull('user_id')->update(['user_id' => $defaultUserId]);
            DB::table('settings')->whereNull('user_id')->update(['user_id' => $defaultUserId]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'user_id')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropUnique(['user_id', 'key']);
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
                $table->unique(['key']);
            });
        }

        if (Schema::hasColumn('payable_payments', 'user_id')) {
            Schema::table('payable_payments', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            });
        }

        if (Schema::hasColumn('payable_events', 'user_id')) {
            Schema::table('payable_events', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            });
        }

        if (Schema::hasColumn('payable_periods', 'user_id')) {
            Schema::table('payable_periods', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            });
        }
    }
};
