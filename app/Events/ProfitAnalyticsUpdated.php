<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProfitAnalyticsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public string $spendDate,
        public string $platform = 'Shopee',
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('orders.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'ProfitAnalyticsUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'spend_date' => $this->spendDate,
            'platform' => $this->platform,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
