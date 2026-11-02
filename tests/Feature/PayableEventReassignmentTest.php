<?php

namespace Tests\Feature;

use App\Models\PayableEvent;
use App\Models\PayablePeriod;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayableEventReassignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_move_recommends_period_containing_order_date_and_moves_both_fields(): void
    {
        $user = User::factory()->create();
        $sourceSupplier = $this->createSupplier($user, 'Supplier Awal');
        $targetSupplier = $this->createSupplier($user, 'Supplier Tujuan');
        $sourcePeriod = $this->createPeriod($user, $sourceSupplier, 'Periode Awal', '2026-09-01 00:00:00', '2026-09-30 23:59:59');
        $targetPeriod = $this->createPeriod($user, $targetSupplier, '11 Okt - 11 Nov', '2026-10-11 00:00:00', '2026-11-11 23:59:59');

        DB::table('orders')->insert([
            'order_sn' => 'ORDER-14-OCT',
            'order_time' => '2026-10-14 10:30:00',
            'order_status' => 'COMPLETED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = PayableEvent::create([
            'user_id' => $user->id,
            'supplier_id' => $sourceSupplier->id,
            'payable_period_id' => $sourcePeriod->id,
            'platform' => 'Shopee',
            'source_id' => 'ORDER-14-OCT',
            'source_type' => 'CREATE_ORDER',
            'event_date' => '2026-10-20 12:00:00',
            'amount' => 150000,
        ]);

        $this->actingAs($user)
            ->getJson('/api/payable/events/reassign-options?'.http_build_query([
                'supplier_id' => $targetSupplier->id,
                'event_ids' => [$event->id],
            ]))
            ->assertOk()
            ->assertJsonPath('data.recommended_period_id', $targetPeriod->id)
            ->assertJsonPath('data.recommendation_status', 'matched');

        $this->actingAs($user)
            ->postJson('/api/payable/events/reassign', [
                'event_ids' => [$event->id],
                'move_mode' => 'supplier',
                'target_supplier_id' => $targetSupplier->id,
                'target_period_id' => $targetPeriod->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.moved.0', $event->id);

        $event->refresh();
        $this->assertSame($targetSupplier->id, $event->supplier_id);
        $this->assertSame($targetPeriod->id, $event->payable_period_id);
        $this->assertSame($sourcePeriod->id, $event->original_period_id);
        $this->assertTrue($event->is_manual_moved);
    }

    public function test_supplier_move_rejects_period_owned_by_another_supplier(): void
    {
        $user = User::factory()->create();
        $sourceSupplier = $this->createSupplier($user, 'Supplier Awal');
        $targetSupplier = $this->createSupplier($user, 'Supplier Tujuan');
        $sourcePeriod = $this->createPeriod($user, $sourceSupplier, 'Periode Awal', '2026-10-01', '2026-10-31');
        $wrongPeriod = $this->createPeriod($user, $sourceSupplier, 'Periode Salah', '2026-11-01', '2026-11-30');

        $event = PayableEvent::create([
            'user_id' => $user->id,
            'supplier_id' => $sourceSupplier->id,
            'payable_period_id' => $sourcePeriod->id,
            'platform' => 'Tiktokshop',
            'source_id' => 'ORDER-WRONG-PERIOD',
            'source_type' => 'CREATE_ORDER',
            'event_date' => '2026-10-14 10:00:00',
            'amount' => 100000,
        ]);

        $this->actingAs($user)
            ->postJson('/api/payable/events/reassign', [
                'event_ids' => [$event->id],
                'move_mode' => 'supplier',
                'target_supplier_id' => $targetSupplier->id,
                'target_period_id' => $wrongPeriod->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Periode tujuan tidak termasuk dalam supplier tujuan.');

        $event->refresh();
        $this->assertSame($sourceSupplier->id, $event->supplier_id);
        $this->assertSame($sourcePeriod->id, $event->payable_period_id);
    }

    public function test_period_only_move_keeps_existing_supplier(): void
    {
        $user = User::factory()->create();
        $supplier = $this->createSupplier($user, 'Supplier Tetap');
        $sourcePeriod = $this->createPeriod($user, $supplier, 'Periode 1', '2026-10-01', '2026-10-15');
        $targetPeriod = $this->createPeriod($user, $supplier, 'Periode 2', '2026-10-16', '2026-10-31');

        $event = PayableEvent::create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'payable_period_id' => $sourcePeriod->id,
            'platform' => 'Shopee',
            'source_id' => 'ORDER-PERIOD-ONLY',
            'source_type' => 'CREATE_ORDER',
            'event_date' => '2026-10-14 10:00:00',
            'amount' => 90000,
        ]);

        $this->actingAs($user)
            ->postJson('/api/payable/events/reassign', [
                'event_ids' => [$event->id],
                'move_mode' => 'period',
                'target_period_id' => $targetPeriod->id,
            ])
            ->assertOk();

        $event->refresh();
        $this->assertSame($supplier->id, $event->supplier_id);
        $this->assertSame($targetPeriod->id, $event->payable_period_id);
    }

    private function createSupplier(User $user, string $name): Supplier
    {
        return Supplier::create([
            'user_id' => $user->id,
            'name' => $name,
        ]);
    }

    private function createPeriod(User $user, Supplier $supplier, string $name, string $start, string $end): PayablePeriod
    {
        return PayablePeriod::create([
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'name' => $name,
            'start_date' => $start,
            'end_date' => $end,
            'payment_status' => 'UNPAID',
            'is_closed' => false,
            'is_manual' => true,
        ]);
    }
}
