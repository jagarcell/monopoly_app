<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event fired when a player pays rent on an owned property.
 *
 * All connected board viewers receive the payer and owner details, the rent
 * amount, and both updated capital balances so every client can update the
 * player panels reactively and display a rent-paid notification dialog without
 * a page reload.
 */
class RentPaid implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new RentPaid event.
     *
     * Logic: Stores all rent transaction data — payer and owner identities,
     * both updated capital balances, the rent amount charged, and the square
     * name — for serialisation into the broadcast payload.
     *
     * @param  int     $gameId           The ID of the game.
     * @param  int     $payerJoinOrder   join_order of the player who paid rent.
     * @param  string  $payerName        Display name of the paying player.
     * @param  int     $payerCapital     Updated capital balance of the payer.
     * @param  int     $ownerJoinOrder   join_order of the property owner.
     * @param  string  $ownerName        Display name of the property owner.
     * @param  int     $ownerCapital     Updated capital balance of the owner.
     * @param  int     $rentAmount       The rent amount charged.
     * @param  string  $squareName       The name of the property where rent was owed.
     */
    public function __construct(
        public readonly int    $gameId,
        public readonly int    $payerJoinOrder,
        public readonly string $payerName,
        public readonly int    $payerCapital,
        public readonly int    $ownerJoinOrder,
        public readonly string $ownerName,
        public readonly int    $ownerCapital,
        public readonly int    $rentAmount,
        public readonly string $squareName,
    ) {}

    /**
     * Get the channel this event should broadcast on.
     *
     * Logic: Uses the same public `game.{gameId}` channel as DiceRolled and
     * TokenMoved so all viewers — including unauthenticated guests — receive
     * the update without needing separate channel subscriptions.
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
     * Logic: Returns the full rent transaction details. Clients use
     * payer_join_order and owner_join_order to update the matching player
     * cards reactively, and payer_name / owner_name / rent_amount / square_name
     * to populate the rent-paid notification dialog shown to all participants.
     *
     * @return array<string, int|string>
     */
    public function broadcastWith(): array
    {
        return [
            'payer_join_order' => $this->payerJoinOrder,
            'payer_name'       => $this->payerName,
            'payer_capital'    => $this->payerCapital,
            'owner_join_order' => $this->ownerJoinOrder,
            'owner_name'       => $this->ownerName,
            'owner_capital'    => $this->ownerCapital,
            'rent_amount'      => $this->rentAmount,
            'square_name'      => $this->squareName,
        ];
    }
}
