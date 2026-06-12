<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a property receives a new house or is upgraded to a hotel.
 */
class PropertyBuilt implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $gameId,
        public readonly int $ownerJoinOrder,
        public readonly int $squareIndex,
        public readonly ?int $housesCount = null,
        public readonly ?bool $hasHotel = null,
        public readonly ?int $ownerCapital = null,
        public readonly ?int $bankHousesAvailable = null,
        public readonly ?int $bankHotelsAvailable = null,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('game.' . $this->gameId);
    }

    public function broadcastWith(): array
    {
        return [
            'owner_join_order' => $this->ownerJoinOrder,
            'square_index'     => $this->squareIndex,
            'houses_count'     => $this->housesCount,
            'has_hotel'        => $this->hasHotel,
            'owner_capital'    => $this->ownerCapital,
            'bank_houses_available' => $this->bankHousesAvailable,
            'bank_hotels_available' => $this->bankHotelsAvailable,
        ];
    }
}
