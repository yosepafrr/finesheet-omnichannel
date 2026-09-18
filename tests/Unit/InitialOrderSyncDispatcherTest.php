<?php

namespace Tests\Unit;

use App\Jobs\SyncShopeeOrderJob;
use App\Jobs\SyncShopeeReturnJob;
use App\Jobs\SyncStoreLogisticsJob;
use App\Jobs\SyncTiktokOrderJob;
use App\Jobs\SyncTiktokReturnJob;
use App\Models\Store;
use App\Services\InitialOrderSyncDispatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InitialOrderSyncDispatcherTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_tiktok_initial_sync_prioritizes_recent_orders_and_backfills_history(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-09-19 12:00:00', 'UTC'));
        $store = $this->store(12, 'Tiktokshop');

        app(InitialOrderSyncDispatcher::class)->dispatch($store);

        Queue::assertPushed(SyncTiktokOrderJob::class, 14);
        Queue::assertPushed(SyncTiktokOrderJob::class, function ($job) {
            return $job->queue === 'orders'
                && $job->showProgress
                && $job->syncContext === 'initial';
        });
        Queue::assertPushed(SyncTiktokOrderJob::class, function ($job) {
            return $job->queue === 'orders-low'
                && !$job->showProgress
                && $job->syncContext === 'backfill';
        });
        Queue::assertPushed(SyncTiktokReturnJob::class, 2);
        Queue::assertPushed(SyncStoreLogisticsJob::class, function ($job) {
            return $job->queue === 'logistics' && $job->showProgress;
        });
    }

    public function test_shopee_initial_sync_uses_platform_window_sizes(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-09-19 12:00:00', 'UTC'));
        $store = $this->store(18, 'Shopee');

        app(InitialOrderSyncDispatcher::class)->dispatch($store);

        Queue::assertPushed(SyncShopeeOrderJob::class, 12);
        Queue::assertPushed(SyncShopeeReturnJob::class, 2);
        Queue::assertPushed(SyncStoreLogisticsJob::class, function ($job) {
            return $job->queue === 'logistics' && $job->storeId === 18;
        });
    }

    private function store(int $id, string $platform): Store
    {
        $store = new Store(['platform' => $platform, 'store_name' => 'Test Store']);
        $store->id = $id;

        return $store;
    }
}
