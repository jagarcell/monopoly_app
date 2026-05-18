<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event fired when the drawing player dismisses their card reveal
 * modal, signalling to all observer boards that they may auto-close the
 * card-drawn notification.
 *
 * This event carries no card data or player identity — it is purely a dismiss
 * signal.  Observer boards react by closing their CardDrawnNotification; any
 * observer who has already dismissed manually simply ignores the event.
 */
class CardAccepted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new CardAccepted event.
     *
     * Logic: Stores the game ID so the event can be broadcast on the correct
     * game-scoped channel.  No card data or player identity is needed — the
     * event functions as a pure dismiss signal.
     *
     * @param  int  $gameId  The ID of the game in which the card was accepted.
     */
    public function __construct(
        public readonly int $gameId,
    ) {}

    /**
     * Get the channel this event should broadcast on.
     *
     * Logic: Uses the same public `game.{gameId}` channel as CardDrawn so all
     * observer boards receive the dismiss signal without a separate channel
     * subscription.
     *
     * @return Channel
     */
    public function broadcastOn(): Channel
    {
        return new Channel('game.' . $this->gameId);
    }

    /**
     * Build the broadcast payload.
     *
     * Logic: Returns an empty array — the event carries no data beyond its
     * existence on the channel.  Observer boards only need to know the event
     * occurred; the game ID is already encoded in the channel name.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
