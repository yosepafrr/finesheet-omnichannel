<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PayableUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $periodId;
    public $action;
    public $userId;

    /**
     * Create a new event instance.
     */
    public function __construct($periodId = null, $action = 'updated', $userId = null)
    {
        $this->periodId = $periodId;
        $this->action = $action;
        $this->userId = $userId;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        if ($this->userId) {
            return new PrivateChannel('payables.' . $this->userId);
        }
        return new PrivateChannel('payables.0');
    }

    /**
     * Broadcast name
     */
    public function broadcastAs(): string
    {
        return 'PayableUpdated';
    }

    /**
     * Data to broadcast
     */
    public function broadcastWith(): array
    {
        return [
            'period_id' => $this->periodId,
            'action' => $this->action,
            'user_id' => $this->userId,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
