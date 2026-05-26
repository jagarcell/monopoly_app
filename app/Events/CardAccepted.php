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
 * card-drawn notification and, when present, merge any finalized card payment
 * result into local player state.
 *
 * This event carries no card data or player identity — it is primarily a
 * dismiss signal. When a card payment is finalized through the mortgage flow,
 * the optional payload carries the updated balances so every board stays in
 * sync.
 */
class CardAccepted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new CardAccepted event.
     *
     * Logic: Stores the game ID so the event can be broadcast on the correct
     * game-scoped channel. The optional payload keeps payment results available
     * to observer boards when the card required a deferred payment.
     *
     * @param  int  $gameId  The ID of the game in which the card was accepted.
     * @param  array<string, mixed>  $payload  Optional payment result payload.
     */
    public function __construct(
        public readonly int $gameId,
        public readonly array $payload = [],
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
     * Logic: Returns the payload supplied by the service layer. For plain card
     * dismissals this remains empty; for card payments it includes the updated
     * balances to merge reactively.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
