<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event fired when a guest player successfully joins a game.
 *
 * Subscribers (creator and other guests watching the board) receive an updated
 * players array over the public channel so their boards update in real time
 * without a page reload.
 */
class PlayerJoined implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new PlayerJoined event.
     *
     * Logic: Stores the game ID and full players array for serialisation into
     * the broadcast payload. The players array is already ordered by join_order
     * and built by PlayerIconRepository::getPlayersForGame.
     *
     * @param  int                                $gameId   The ID of the game being joined.
     * @param  array<int, array<string, mixed>>   $players  Full player list, ordered by join_order.
     */
    public function __construct(
        public readonly int   $gameId,
        public readonly array $players,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * Logic: Uses a public (non-authenticated) channel named `game.{gameId}`
     * so every board viewer — including guests who hold only an invitation token
     * and are not authenticated — can subscribe without server-side authorisation.
     *
     * @return Channel
     */
    public function broadcastOn(): Channel
    {
        return new Channel('game.' . $this->gameId);
    }

    /**
     * Get the data to broadcast with the event.
     *
     * Logic: Returns the full players array under the `players` key so the
     * frontend listener receives a complete, authoritative snapshot and can
     * replace its local state wholesale rather than applying a partial diff.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['players' => $this->players];
    }
}
