<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event fired when a player lands on a mortgaged property.
 *
 * Connected boards use this event to show a real-time notification dialog to
 * every observer so they can see that no rent is due, while the payer still
 * receives the same dialog immediately from the HTTP roll response.
 */
class MortgagedPropertyNotified implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new MortgagedPropertyNotified event.
     *
     * Logic: Stores the game context, both player identities, their token
     * metadata, and the mortgaged square name so observer boards can render
     * the same no-rent notification without additional lookups.
     *
     * @param  int     $gameId           The ID of the game.
     * @param  int     $payerJoinOrder   join_order of the player who landed on the property.
     * @param  string  $payerName        Display name of the landing player.
     * @param  array{id:int, name:string, image_url:string}|null  $payerIcon  Token metadata for the landing player.
     * @param  int     $ownerJoinOrder   join_order of the property owner.
     * @param  string  $ownerName        Display name of the property owner.
     * @param  array{id:int, name:string, image_url:string}|null  $ownerIcon  Token metadata for the property owner.
     * @param  string  $squareName       The name of the mortgaged property.
     */
    public function __construct(
        public readonly int $gameId,
        public readonly int $payerJoinOrder,
        public readonly string $payerName,
        public readonly ?array $payerIcon,
        public readonly int $ownerJoinOrder,
        public readonly string $ownerName,
        public readonly ?array $ownerIcon,
        public readonly string $squareName,
    ) {}

    /**
     * Get the channel this event should broadcast on.
     *
     * Logic: Uses the public `game.{gameId}` channel so every connected board
     * receives the no-rent notification in real time.
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
     * Logic: Returns the landing and owner identities, their token metadata,
     * and the square name so observer boards can show the same mortgaged
     * property dialog without a page refresh.
     *
     * @return array{payer_join_order: int, payer_name: string, payer_icon: array{id:int, name:string, image_url:string}|null, owner_join_order: int, owner_name: string, owner_icon: array{id:int, name:string, image_url:string}|null, square_name: string}
     */
    public function broadcastWith(): array
    {
        return [
            'payer_join_order' => $this->payerJoinOrder,
            'payer_name'       => $this->payerName,
            'payer_icon'       => $this->payerIcon,
            'owner_join_order' => $this->ownerJoinOrder,
            'owner_name'       => $this->ownerName,
            'owner_icon'       => $this->ownerIcon,
            'square_name'      => $this->squareName,
        ];
    }
}