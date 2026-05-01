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
     * Logic: Stores the game ID, full players array, and pending invitations
     * array for serialisation into the broadcast payload. The players array is
     * ordered by join_order; the pending invitations list contains only
     * invitations not yet accepted and not yet expired.
     *
     * @param  int                                $gameId              The ID of the game being joined.
     * @param  array<int, array<string, mixed>>   $players             Full player list, ordered by join_order.
     * @param  array<int, array{email: string}>   $pendingInvitations  Pending (not yet joined) invitations.
     */
    public function __construct(
        public readonly int   $gameId,
        public readonly array $players,
        public readonly array $pendingInvitations,
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
     * Logic: Returns the full players array under the `players` key and the
     * pending invitations list under `pending_invitations` so subscribers
     * receive both lists atomically and can update their UI without a page
     * reload.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'players'             => $this->players,
            'pending_invitations' => $this->pendingInvitations,
        ];
    }
}
