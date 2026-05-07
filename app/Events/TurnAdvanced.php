<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event fired when the active player signals they have completed
 * their turn by clicking the Done button.
 *
 * All connected board viewers receive the new active-player join_order so
 * every client's turn indicator and Roll/Done/Waiting controls update
 * reactively without a page reload.
 */
class TurnAdvanced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new TurnAdvanced event.
     *
     * Logic: Stores the game ID and the join_order of the player whose turn it
     * now is (i.e. the player after the one who just clicked Done). Both values
     * are serialised into the broadcast payload.
     *
     * @param  int  $gameId                The ID of the game.
     * @param  int  $currentTurnJoinOrder  join_order of the player whose turn it now is.
     */
    public function __construct(
        public readonly int $gameId,
        public readonly int $currentTurnJoinOrder,
    ) {}

    /**
     * Get the channel this event should broadcast on.
     *
     * Logic: Uses the same public `game.{gameId}` channel as PlayerJoined and
     * DiceRolled so all viewers — including unauthenticated guests — receive
     * the update without needing to be subscribed to a separate channel.
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
     * Logic: Returns the join_order of the player whose turn it now is. Clients
     * use current_turn_join_order to decide whether to show the Roll button and
     * to display a turn-indicator badge on the correct player card.
     *
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return [
            'current_turn_join_order' => $this->currentTurnJoinOrder,
        ];
    }
}
