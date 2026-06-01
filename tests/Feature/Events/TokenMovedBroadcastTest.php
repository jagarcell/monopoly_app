<?php

namespace Tests\Feature\Events;

use App\Events\TokenMoved;
use Illuminate\Broadcasting\Channel;
use Tests\TestCase;

class TokenMovedBroadcastTest extends TestCase
{

    public function test_event_broadcasts_on_the_correct_game_channel(): void
    {
        $event = new TokenMoved(
            gameId:      5,
            joinOrder:   2,
            squareIndex: 8,
        );

        $channel = $event->broadcastOn();

        $this->assertInstanceOf(Channel::class, $channel);
        $this->assertSame('game.5', $channel->name);
    }

    public function test_broadcast_payload_contains_join_order_square_index_and_jail_state(): void
    {
        $event = new TokenMoved(
            gameId:      1,
            joinOrder:   3,
            squareIndex: 15,
            isInJail:    true,
            jailAnimationSource: 'square',
        );

        $payload = $event->broadcastWith();

        $this->assertArrayHasKey('join_order', $payload);
        $this->assertArrayHasKey('square_index', $payload);
        $this->assertArrayHasKey('isInJail', $payload);
        $this->assertArrayHasKey('is_in_jail', $payload);
        $this->assertArrayHasKey('jail_animation_source', $payload);
    }

    public function test_broadcast_payload_contains_correct_values(): void
    {
        $event = new TokenMoved(
            gameId:      2,
            joinOrder:   4,
            squareIndex: 22,
            isInJail:    false,
            jailAnimationSource: 'card',
        );

        $payload = $event->broadcastWith();

        $this->assertSame(4, $payload['join_order']);
        $this->assertSame(22, $payload['square_index']);
        $this->assertFalse($payload['isInJail']);
        $this->assertFalse($payload['is_in_jail']);
        $this->assertSame('card', $payload['jail_animation_source']);
    }

    public function test_broadcast_payload_does_not_expose_game_id(): void
    {
        $event = new TokenMoved(
            gameId:      9,
            joinOrder:   1,
            squareIndex: 0,
        );

        $payload = $event->broadcastWith();

        $this->assertArrayNotHasKey('game_id', $payload);
    }

    public function test_broadcast_channel_name_includes_game_id(): void
    {
        foreach ([1, 99, 1000] as $gameId) {
            $event   = new TokenMoved(gameId: $gameId, joinOrder: 1, squareIndex: 0);
            $channel = $event->broadcastOn();
            $this->assertSame("game.{$gameId}", $channel->name, "Channel name mismatch for game {$gameId}");
        }
    }

    public function test_square_index_zero_is_valid_go_position(): void
    {
        $event = new TokenMoved(
            gameId:      3,
            joinOrder:   1,
            squareIndex: 0,
        );

        $payload = $event->broadcastWith();

        $this->assertSame(0, $payload['square_index']);
    }

    public function test_square_index_39_is_valid_boardwalk_position(): void
    {
        $event = new TokenMoved(
            gameId:      3,
            joinOrder:   1,
            squareIndex: 39,
        );

        $payload = $event->broadcastWith();

        $this->assertSame(39, $payload['square_index']);
    }
}
