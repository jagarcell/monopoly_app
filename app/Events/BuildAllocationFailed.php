<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when pending builds could not be granted due to insufficient
 * bank inventory. Receivers should surface a message to the affected player.
 */
class BuildAllocationFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $gameId,
        public readonly int $ownerJoinOrder,
        public readonly array $deniedSquares,
        public readonly string $message = ''
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('game.' . $this->gameId);
    }

    public function broadcastWith(): array
    {
        return [
            'owner_join_order' => $this->ownerJoinOrder,
            'denied_squares'   => $this->deniedSquares,
            'message'          => $this->message,
        ];
    }
}
