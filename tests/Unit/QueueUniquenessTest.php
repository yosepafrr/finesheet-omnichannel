<?php

namespace Tests\Unit;

use App\Jobs\HandleTiktokOrderWebhookJob;
use App\Jobs\SyncShopeeOrderJob;
use App\Jobs\SyncStoreLogisticsChunkJob;
use App\Jobs\SyncStoreLogisticsJob;
use App\Jobs\SyncTiktokEscrowJob;
use App\Jobs\SyncTiktokOrderJob;
use App\Jobs\SyncTiktokProductJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use PHPUnit\Framework\TestCase;

class QueueUniquenessTest extends TestCase
{
    public function test_order_sync_jobs_are_unique_per_scope(): void
    {
        $tiktok = new SyncTiktokOrderJob(12, 14);
        $shopee = new SyncShopeeOrderJob(12, 14);

        $this->assertInstanceOf(ShouldBeUnique::class, $tiktok);
        $this->assertInstanceOf(ShouldBeUnique::class, $shopee);
        $this->assertSame('12:14', $tiktok->uniqueId());
        $this->assertSame('12:14', $shopee->uniqueId());

        $range = new SyncTiktokOrderJob(12, 14, 1000, 2000);
        $this->assertSame('12:range:1000:2000', $range->uniqueId());

        $shopeeRange = new SyncShopeeOrderJob(12, 15, false, 'backfill', 1000, 2000);
        $this->assertSame('12:range:1000:2000', $shopeeRange->uniqueId());

        $trackedTiktok = new SyncTiktokOrderJob(12, 180, null, null, true, 'initial');
        $trackedShopee = new SyncShopeeOrderJob(12, 180, true, 'initial');
        $this->assertTrue($trackedTiktok->showProgress);
        $this->assertTrue($trackedShopee->showProgress);
        $this->assertSame('initial', $trackedTiktok->syncContext);
        $this->assertSame('initial', $trackedShopee->syncContext);
        $this->assertInstanceOf(WithoutOverlapping::class, $trackedTiktok->middleware()[0]);
        $this->assertInstanceOf(WithoutOverlapping::class, $trackedShopee->middleware()[0]);
    }

    public function test_tiktok_child_jobs_are_unique_per_order(): void
    {
        $escrow = new SyncTiktokEscrowJob(12, 'ORDER-123', 'completed', 100000);
        $webhook = new HandleTiktokOrderWebhookJob('SHOP-9', 'ORDER-123');

        $this->assertInstanceOf(ShouldBeUnique::class, $escrow);
        $this->assertInstanceOf(ShouldBeUnique::class, $webhook);
        $this->assertSame('12:ORDER-123:COMPLETED', $escrow->uniqueId());
        $this->assertSame('SHOP-9:ORDER-123', $webhook->uniqueId());
    }

    public function test_tiktok_product_sync_is_unique_per_store(): void
    {
        $job = new SyncTiktokProductJob(12);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('12', $job->uniqueId());
        $this->assertSame(300, $job->timeout);
    }

    public function test_logistics_sync_jobs_are_unique_and_track_the_logistics_phase(): void
    {
        $coordinator = new SyncStoreLogisticsJob(12, true, 'initial');
        $chunk = new SyncStoreLogisticsChunkJob(12, [3, 4, 5], true, 'initial');

        $this->assertInstanceOf(ShouldBeUnique::class, $coordinator);
        $this->assertInstanceOf(ShouldBeUnique::class, $chunk);
        $this->assertSame('12:logistics', $coordinator->uniqueId());
        $this->assertStringStartsWith('12:logistics-chunk:', $chunk->uniqueId());
        $this->assertSame('logistics', $coordinator->syncPhase);
        $this->assertSame('logistics', $chunk->syncPhase);
        $this->assertSame(300, $chunk->timeout);
    }
}
