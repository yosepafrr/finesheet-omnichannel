<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use App\Services\PayableService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PayableMonthlyPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_periods_follow_the_first_day_cutoff_without_gaps(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Supplier Bulanan',
            'period_type' => 'months',
            'period_length_months' => 1,
            'period_length_days' => 14,
            'first_period_start' => '2026-01-01 08:30:00',
        ]);
        $service = app(PayableService::class);

        $january = $service->getPeriodForDate(Carbon::parse('2026-01-31 23:59:59'), $user->id, $supplier);
        $beforeCutoff = $service->getPeriodForDate(Carbon::parse('2026-02-01 08:29:59'), $user->id, $supplier);
        $february = $service->getPeriodForDate(Carbon::parse('2026-02-01 08:30:00'), $user->id, $supplier);

        $this->assertSame($january->id, $beforeCutoff->id);
        $this->assertSame('2026-01-01 08:30:00', $january->start_date->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-01 08:29:59', $january->end_date->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-01 08:30:00', $february->start_date->format('Y-m-d H:i:s'));
    }

    public function test_multi_month_period_uses_the_selected_number_of_calendar_months(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Supplier Dua Bulan',
            'period_type' => 'months',
            'period_length_months' => 2,
            'period_length_days' => 14,
            'first_period_start' => '2026-01-01 00:00:00',
        ]);

        $period = app(PayableService::class)->getPeriodForDate(
            Carbon::parse('2026-02-28 23:59:59'),
            $user->id,
            $supplier
        );

        $this->assertSame('2026-01-01 00:00:00', $period->start_date->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-28 23:59:59', $period->end_date->format('Y-m-d H:i:s'));
    }

    public function test_saving_monthly_configuration_normalizes_start_to_day_one_and_preserves_time(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Supplier API',
        ]);

        $this->actingAs($user)
            ->postJson('/api/payable/config', [
                'supplier_id' => $supplier->id,
                'first_period_start' => '2026-11-18T13:45',
                'period_type' => 'months',
                'length_months' => 2,
                'length_days' => 14,
            ])
            ->assertOk()
            ->assertJsonPath('data.period_type', 'months')
            ->assertJsonPath('data.length_months', 2)
            ->assertJsonPath('data.first_period_start', '2026-11-01 13:45:00');

        $supplier->refresh();
        $this->assertSame('months', $supplier->period_type);
        $this->assertSame(2, $supplier->period_length_months);
        $this->assertSame('2026-11-01 13:45:00', $supplier->first_period_start->format('Y-m-d H:i:s'));
    }
}
