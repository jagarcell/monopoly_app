<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event fired when a player rolls the dice.
 *
 * All connected board viewers receive the rolled values and the updated active
 * player (current_turn_join_order) so every client updates the dice display and
 * turn indicator in real time without a page reload.
 */
class DiceRolled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new DiceRolled event.
     *
     * Logic: Stores all dice data, the active-turn join_order, and the rolling
     * player's new board position for serialisation into the broadcast payload.
     * The currentTurnJoinOrder value is the join_order of the player who rolled
     * (the turn does not advance on roll — only on Done).
     *
     * @param  int  $gameId                The ID of the game.
     * @param  int  $die1                  Face value of die 1 (1–6).
     * @param  int  $die2                  Face value of die 2 (1–6).
     * @param  int  $total                 Sum of die1 + die2.
     * @param  int  $currentTurnJoinOrder  join_order of the player who rolled.
     * @param  int  $squareIndex           New board square index (0–39) for the rolling player.
     */
    public function __construct(
        public readonly int $gameId,
        public readonly int $die1,
        public readonly int $die2,
        public readonly int $total,
        public readonly int $currentTurnJoinOrder,
        public readonly int $squareIndex,
    ) {}

    /**
     * Get the channel this event should broadcast on.
     *
     * Logic: Uses the same public `game.{gameId}` channel as PlayerJoined so
     * all viewers — including unauthenticated guests — receive the update.
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
     * Logic: Returns the two individual die values, their sum, the join_order
     * of the player who rolled, and that player's new board square index.
     * Clients use current_turn_join_order to identify the rolling player and
     * square_index to animate the token movement on all connected boards.
     *
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return [
            'die1'                    => $this->die1,
            'die2'                    => $this->die2,
            'total'                   => $this->total,
            'current_turn_join_order' => $this->currentTurnJoinOrder,
            'square_index'            => $this->squareIndex,
        ];
    }
}
