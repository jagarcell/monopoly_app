<?php

namespace Tests\Feature\Events;

use App\Events\PropertyPurchased;
use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyPurchasedBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_broadcasts_on_the_correct_game_channel(): void
    {
        $event = new PropertyPurchased(
            gameId: 7,
            buyerJoinOrder: 2,
            buyerName: 'Alice',
            buyerCapital: 1100,
            buyerIcon: ['id' => 1, 'name' => 'Hat', 'image_url' => '/hat.svg'],
            squareIndex: 39,
            squareName: 'Boardwalk',
            purchasePrice: 400,
        );

        $channel = $event->broadcastOn();

        $this->assertInstanceOf(Channel::class, $channel);
        $this->assertSame('game.7', $channel->name);
    }

    public function test_broadcast_payload_contains_required_keys(): void
    {
        $event = new PropertyPurchased(
            gameId: 1,
            buyerJoinOrder: 1,
            buyerName: 'Bob',
            buyerCapital: 1100,
            buyerIcon: ['id' => 2, 'name' => 'Car', 'image_url' => '/car.svg'],
            squareIndex: 39,
            squareName: 'Boardwalk',
            purchasePrice: 400,
        );

        $payload = $event->broadcastWith();

        $this->assertArrayHasKey('buyer_join_order', $payload);
        $this->assertArrayHasKey('buyer_name', $payload);
        $this->assertArrayHasKey('buyer_capital', $payload);
        $this->assertArrayHasKey('buyer_icon', $payload);
        $this->assertArrayHasKey('square_index', $payload);
        $this->assertArrayHasKey('square_name', $payload);
        $this->assertArrayHasKey('purchase_price', $payload);
    }

    public function test_broadcast_payload_contains_correct_values(): void
    {
        $buyerIcon = ['id' => 3, 'name' => 'Dog', 'image_url' => '/dog.svg'];

        $event = new PropertyPurchased(
            gameId: 42,
            buyerJoinOrder: 3,
            buyerName: 'Carol',
            buyerCapital: 1200,
            buyerIcon: $buyerIcon,
            squareIndex: 28,
            squareName: 'Water Works',
            purchasePrice: 150,
        );

        $payload = $event->broadcastWith();

        $this->assertSame(3, $payload['buyer_join_order']);
        $this->assertSame('Carol', $payload['buyer_name']);
        $this->assertSame(1200, $payload['buyer_capital']);
        $this->assertSame($buyerIcon, $payload['buyer_icon']);
        $this->assertSame(28, $payload['square_index']);
        $this->assertSame('Water Works', $payload['square_name']);
        $this->assertSame(150, $payload['purchase_price']);
    }
}