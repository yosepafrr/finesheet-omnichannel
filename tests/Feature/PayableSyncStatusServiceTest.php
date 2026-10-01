<?php

namespace Tests\Feature;

use App\Jobs\SyncPayableHistoryJob;
use App\Services\PayableSyncStatusService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PayableSyncStatusServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();
    }

    public function test_dispatch_records_a_persistent_queued_status(): void
    {
        Queue::fake();
        $service = app(PayableSyncStatusService::class);

        $service->dispatch('2026-09-01 00:00:00', 12, 'supplier_assignment');

        $status = $service->get(12);
        $this->assertSame('queued', $status['status']);
        $this->assertSame('supplier_assignment', $status['context']);
        $this->assertSame(1, $status['revision']);
        Queue::assertPushed(SyncPayableHistoryJob::class);
    }

    public function test_an_older_job_cannot_finish_a_newer_sync_request(): void
    {
        $service = app(PayableSyncStatusService::class);

        $first = $service->markQueued(12, 'manual');
        $firstRevision = $service->markRunning(12);
        $second = $service->markQueued(12, 'supplier_assignment');

        $this->assertFalse($service->markCompleted(12, $firstRevision));
        $this->assertSame('queued', $service->get(12)['status']);
        $this->assertGreaterThan($first['revision'], $second['revision']);

        $secondRevision = $service->markRunning(12);
        $this->assertTrue($service->markCompleted(12, $secondRevision));
        $this->assertSame('completed', $service->get(12)['status']);
    }
}
