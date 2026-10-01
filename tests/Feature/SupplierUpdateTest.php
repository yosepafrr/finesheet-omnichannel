<?php

namespace Tests\Feature;

use App\Jobs\SyncPayableHistoryJob;
use App\Models\PayablePeriod;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PayableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use Tests\TestCase;

class SupplierUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_supplier_identity_does_not_regenerate_periods_or_dispatch_history_sync(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Supplier Lama',
            'contact_person' => 'Kontak Lama',
            'phone' => '081200000000',
            'notes' => 'Catatan lama',
            'period_length_days' => 14,
            'first_period_start' => '2026-09-01 08:30:00',
        ]);
        $period = PayablePeriod::create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'name' => 'Periode Existing',
            'start_date' => '2026-09-01 08:30:00',
            'end_date' => '2026-09-15 08:29:59',
            'payment_status' => 'UNPAID',
            'is_closed' => false,
            'is_manual' => false,
        ]);

        $this->mock(PayableService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('syncPayableForUser');
        });

        $this->actingAs($user)
            ->putJson("/api/payable/suppliers/{$supplier->id}", [
                'name' => 'Supplier Baru',
                'contact_person' => 'Kontak Baru',
                'phone' => '081299999999',
                'address' => 'Bandung',
                'notes' => 'Catatan baru',
                'period_length_days' => 14,
                'first_period_start' => '2026-09-01T08:30',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Supplier Baru')
            ->assertJsonPath('data.first_period_start_local', '2026-09-01T08:30');

        $this->assertDatabaseHas('payable_periods', ['id' => $period->id]);
        Bus::assertNotDispatched(SyncPayableHistoryJob::class);
    }

    public function test_updating_period_start_persists_time_and_regenerates_automatic_periods(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Supplier A',
            'period_length_days' => 14,
            'first_period_start' => '2026-09-01 08:30:00',
        ]);
        Setting::create([
            'user_id' => $user->id,
            'key' => 'recap_period_config',
            'value' => ['first_period_start' => '2026-09-01 08:30:00'],
        ]);

        $this->mock(PayableService::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('syncPayableForUser')->once()->with($user->id);
        });

        $this->actingAs($user)
            ->putJson("/api/payable/suppliers/{$supplier->id}", [
                'name' => 'Supplier A',
                'period_length_days' => 14,
                'first_period_start' => '2026-09-02T13:45',
            ])
            ->assertOk()
            ->assertJsonPath('data.first_period_start_local', '2026-09-02T13:45');

        $this->assertSame(
            '2026-09-02 13:45:00',
            $supplier->fresh()->first_period_start->format('Y-m-d H:i:s')
        );
        Bus::assertDispatched(SyncPayableHistoryJob::class);
    }
}
