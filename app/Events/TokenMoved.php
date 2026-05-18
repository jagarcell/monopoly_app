<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event fired when a player's token has finished moving on the board.
 *
 * The rolling player dispatches this after their local step-by-step animation
 * completes, signalling all other connected boards to animate that token to the
 * final square. This decouples remote animation from the DiceRolled event so
 * observer boards move the token only once the roller has confirmed the animation
 * is complete, preventing any race between dice roll dispatch timing and token
 * movement.
 */
class TokenMoved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new TokenMoved event.
     *
     * Logic: Stores the game ID, the join_order of the player whose token moved,
     * the authoritative final square index read from the database, and a flag
     * indicating whether the movement was backward. The backward flag is used
     * by observer boards to animate the token in the correct direction (e.g.
     * for the 'Go Back 3 Spaces' Chance card).
     *
     * @param  int   $gameId      The ID of the game.
     * @param  int   $joinOrder   The join_order of the player whose token moved.
     * @param  int   $squareIndex The final board square index (0–39) after movement.
     * @param  bool  $backward    Whether the token moved backward (default false).
     */
    public function __construct(
        public readonly int  $gameId,
        public readonly int  $joinOrder,
        public readonly int  $squareIndex,
        public readonly bool $backward = false,
    ) {}

    /**
     * Get the channel this event should broadcast on.
     *
     * Logic: Uses the same public `game.{gameId}` channel as DiceRolled and
     * PlayerJoined so all viewers — including unauthenticated guests — receive
     * the update without server-side authorisation.
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
     * Logic: Returns the join_order of the moving player, their authoritative
     * final square index, and the backward flag so observer boards animate the
     * token in the correct direction.
     *
     * @return array<string, int|bool>
     */
    public function broadcastWith(): array
    {
        return [
            'join_order'   => $this->joinOrder,
            'square_index' => $this->squareIndex,
            'backward'     => $this->backward,
        ];
    }
}
