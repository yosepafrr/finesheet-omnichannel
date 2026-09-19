<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('payable_periods')
            ->select(['user_id', 'supplier_id', 'start_date', 'is_manual'])
            ->selectRaw('MIN(id) as keep_id, COUNT(*) as duplicate_count')
            ->whereNotNull('supplier_id')
            ->groupBy('user_id', 'supplier_id', 'start_date', 'is_manual')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $duplicateIds = DB::table('payable_periods')
                ->where('user_id', $duplicate->user_id)
                ->where('supplier_id', $duplicate->supplier_id)
                ->where('start_date', $duplicate->start_date)
                ->where('is_manual', $duplicate->is_manual)
                ->where('id', '!=', $duplicate->keep_id)
                ->pluck('id');

            if ($duplicateIds->isEmpty()) {
                continue;
            }

            DB::table('payable_events')
                ->whereIn('payable_period_id', $duplicateIds)
                ->update(['payable_period_id' => $duplicate->keep_id]);

            DB::table('payable_events')
                ->whereIn('original_period_id', $duplicateIds)
                ->update(['original_period_id' => $duplicate->keep_id]);

            DB::table('payable_payments')
                ->whereIn('payable_period_id', $duplicateIds)
                ->update(['payable_period_id' => $duplicate->keep_id]);

            DB::table('payable_audits')
                ->whereIn('payable_period_id', $duplicateIds)
                ->update(['payable_period_id' => $duplicate->keep_id]);

            DB::table('payable_periods')->whereIn('id', $duplicateIds)->delete();
        }

        $duplicateEvents = DB::table('payable_events')
            ->select(['user_id', 'supplier_id', 'source_id', 'source_type'])
            ->selectRaw('COUNT(*) as duplicate_count')
            ->whereNotNull('supplier_id')
            ->whereNotNull('source_id')
            ->groupBy('user_id', 'supplier_id', 'source_id', 'source_type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateEvents as $duplicateEvent) {
            $eventIds = DB::table('payable_events')
                ->where('user_id', $duplicateEvent->user_id)
                ->where('supplier_id', $duplicateEvent->supplier_id)
                ->where('source_id', $duplicateEvent->source_id)
                ->where('source_type', $duplicateEvent->source_type)
                ->orderByDesc('is_manual_moved')
                ->orderByDesc('updated_at')
                ->orderBy('id')
                ->pluck('id');

            DB::table('payable_events')->whereIn('id', $eventIds->slice(1))->delete();
        }

        Schema::table('payable_periods', function (Blueprint $table) {
            $table->unique(
                ['user_id', 'supplier_id', 'start_date', 'is_manual'],
                'payable_periods_scope_start_unique'
            );
        });

        Schema::table('payable_events', function (Blueprint $table) {
            $table->unique(
                ['user_id', 'supplier_id', 'source_id', 'source_type'],
                'payable_events_scope_source_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('payable_periods', function (Blueprint $table) {
            $table->dropUnique('payable_periods_scope_start_unique');
        });

        Schema::table('payable_events', function (Blueprint $table) {
            $table->dropUnique('payable_events_scope_source_unique');
        });
    }
};
