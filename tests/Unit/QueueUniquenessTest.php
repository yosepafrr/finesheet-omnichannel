<?php

namespace Tests\Unit;

use App\Jobs\HandleTiktokOrderWebhookJob;
use App\Jobs\SyncShopeeOrderJob;
use App\Jobs\SyncTiktokEscrowJob;
use App\Jobs\SyncTiktokOrderJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
}
