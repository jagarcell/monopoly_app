<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event fired when a player purchases a property.
 *
 * Connected boards use this event to show a lightweight notification to all
 * other players, identify the purchaser with their token icon, and keep the
 * buyer's capital in sync without requiring a page refresh.
 */
class PropertyPurchased implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new PropertyPurchased event.
     *
     * Logic: Stores the purchaser identity, the updated capital balance, the
     * purchased square details, and the buyer's token metadata so observer
     * boards can render a complete purchase notification.
     *
     * @param  int  $gameId          The ID of the game.
     * @param  int  $buyerJoinOrder  join_order of the player who purchased the property.
     * @param  string  $buyerName    Display name of the purchasing player.
     * @param  int  $buyerCapital    Updated capital balance after the purchase.
     * @param  array{id:int, name:string, image_url:string}|null  $buyerIcon  The purchaser's token metadata.
     * @param  int  $squareIndex     The board square index that was purchased.
     * @param  string  $squareName   The name of the purchased property.
     * @param  int  $purchasePrice   The amount paid for the property.
     */
    public function __construct(
        public readonly int $gameId,
        public readonly int $buyerJoinOrder,
        public readonly string $buyerName,
        public readonly int $buyerCapital,
        public readonly ?array $buyerIcon,
        public readonly int $squareIndex,
        public readonly string $squareName,
        public readonly int $purchasePrice,
    ) {}

    /**
     * Get the channel this event should broadcast on.
     *
     * Logic: Uses the public `game.{gameId}` channel so every connected board
     * receives the purchase update without requiring a private subscription.
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
     * Logic: Returns the purchaser identity, token metadata, updated capital,
     * and square details so observer boards can update state and display the
     * same purchase information consistently.
     *
     * @return array{buyer_join_order: int, buyer_name: string, buyer_capital: int, buyer_icon: array{id:int, name:string, image_url:string}|null, square_index: int, square_name: string, purchase_price: int}
     */
    public function broadcastWith(): array
    {
        return [
            'buyer_join_order' => $this->buyerJoinOrder,
            'buyer_name'       => $this->buyerName,
            'buyer_capital'    => $this->buyerCapital,
            'buyer_icon'       => $this->buyerIcon,
            'square_index'     => $this->squareIndex,
            'square_name'      => $this->squareName,
            'purchase_price'   => $this->purchasePrice,
        ];
    }
}