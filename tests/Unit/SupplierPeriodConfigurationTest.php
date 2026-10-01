<?php

namespace Tests\Unit;

use App\Models\Supplier;
use Carbon\Carbon;
use Tests\TestCase;

class SupplierPeriodConfigurationTest extends TestCase
{
    public function test_it_exposes_the_first_period_start_for_a_datetime_local_input(): void
    {
        $supplier = new Supplier;
        $supplier->first_period_start = Carbon::parse('2026-09-01 13:45:00');

        $this->assertSame('2026-09-01T13:45', $supplier->first_period_start_local);
    }

    public function test_an_equivalent_period_configuration_is_not_marked_as_changed(): void
    {
        $supplier = new Supplier;
        $supplier->setRawAttributes([
            'period_length_days' => 14,
            'first_period_start' => '2026-09-01 08:30:00',
        ], true);

        $supplier->fill([
            'period_length_days' => 14,
            'first_period_start' => Carbon::parse('2026-09-01T08:30'),
        ]);

        $this->assertFalse($supplier->isDirty([
            'first_period_start',
            'period_length_days',
        ]));
    }
}
