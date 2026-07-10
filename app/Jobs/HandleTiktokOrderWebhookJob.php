<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class HandleTiktokOrderWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;

    protected $shopId;
    protected $orderId;

    public function __construct($shopId, $orderId)
    {
        $this->shopId = $shopId;
        $this->orderId = $orderId;
    }

    public function handle()
    {
        Log::info("HandleTiktokOrderWebhookJob started for Order: {$this->orderId}");

        // TODO: Implement TiktokService logic here
        // 1. Fetch Store by shopId
        // 2. Fetch specific order details from TikTok API via TiktokService
        // 3. updateOrCreate to Order / OrderItem database
        // 4. Fire OrderCreated event

        Log::info("HandleTiktokOrderWebhookJob completed for Order: {$this->orderId}");
    }
}
