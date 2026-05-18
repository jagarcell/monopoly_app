<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event fired when a player draws a Chance or Community Chest card
 * upon landing on one of those squares.
 *
 * The full card array is embedded directly in the payload (rather than
 * broadcasting only the ID) for three reasons:
 *  1. The card is already in memory on the server when the event is dispatched.
 *  2. It avoids a race condition — the deck rotates immediately after drawing,
 *     so a per-observer DB lookup could return the next card in the deck.
 *  3. It eliminates an additional HTTP round-trip on every observer's board,
 *     matching the approach used by RentPaid for its capital-balance fields.
 */
class CardDrawn implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new CardDrawn event.
     *
     * Logic: Stores the game context, the card type ('chance' or 'community'),
     * the full card data array, the join_order of the player who drew the card,
     * and that player's display name. The join_order is used by observers to
     * deduplicate: the drawing player already sees the card via the HTTP roll
     * response, so their own board should not show the modal a second time via
     * the broadcast. The display name is included in the payload so observer
     * boards can show a notification without an additional DB lookup.
     *
     * @param  int     $gameId            The ID of the game in which the card was drawn.
     * @param  string  $type              'chance' or 'community'.
     * @param  array   $card              The full card data (id, action, text, amount, etc.).
     * @param  int     $drawnByJoinOrder  join_order of the player who drew the card.
     * @param  string  $drawnByName       Display name of the player who drew the card.
     * @param  array   $cardEffect        The computed effect descriptor returned by applyCardEffect.
     */
    public function __construct(
        public readonly int    $gameId,
        public readonly string $type,
        public readonly array  $card,
        public readonly int    $drawnByJoinOrder,
        public readonly string $drawnByName,
        public readonly array  $cardEffect = [],
    ) {}

    /**
     * Get the channel this event should broadcast on.
     *
     * Logic: Uses the same public `game.{gameId}` channel as every other game
     * event so all board viewers — including unauthenticated guests — receive
     * the card reveal without needing a separate channel subscription.
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
     * Logic: Returns the card type, the full card array, the join_order of the
     * drawing player, and their display name. Observers compare
     * drawn_by_join_order against their own join_order to decide whether to
     * show the card reveal modal or the lighter observer notification.
     * drawn_by_name is included so observer boards can display the player's
     * name without an additional DB lookup on the client.
     *
     * @return array{type: string, card: array, drawn_by_join_order: int, drawn_by_name: string}
     */
    public function broadcastWith(): array
    {
        return [
            'type'                => $this->type,
            'card'                => $this->card,
            'drawn_by_join_order' => $this->drawnByJoinOrder,
            'drawn_by_name'       => $this->drawnByName,
            'card_effect'         => $this->cardEffect,
        ];
    }
}
