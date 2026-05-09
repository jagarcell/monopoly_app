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
     * and the authoritative final square index read from the database. These are
     * serialised into the broadcast payload so all connected observers receive a
     * consistent final position regardless of the order in which events arrive.
     *
     * @param  int  $gameId      The ID of the game.
     * @param  int  $joinOrder   The join_order of the player whose token moved.
     * @param  int  $squareIndex The final board square index (0–39) after movement.
     */
    public function __construct(
        public readonly int $gameId,
        public readonly int $joinOrder,
        public readonly int $squareIndex,
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
     * Logic: Returns the join_order of the moving player and their authoritative
     * final square index. Observers use join_order to identify the token and
     * square_index to determine the animation destination.
     *
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return [
            'join_order'   => $this->joinOrder,
            'square_index' => $this->squareIndex,
        ];
    }
}
