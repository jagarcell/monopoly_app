<?php

namespace Tests\Feature\Events;

use App\Events\DiceRolled;
use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiceRolledBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_broadcasts_on_the_correct_game_channel(): void
    {
        $event = new DiceRolled(
            gameId:               7,
            die1:                 3,
            die2:                 5,
            total:                8,
            currentTurnJoinOrder: 2,
            squareIndex:          8,
        );

        $channels = $event->broadcastOn();

        $this->assertInstanceOf(Channel::class, $channels);
        $this->assertSame('game.7', $channels->name);
    }

    public function test_broadcast_payload_contains_required_keys(): void
    {
        $event = new DiceRolled(
            gameId:               1,
            die1:                 4,
            die2:                 6,
            total:                10,
            currentTurnJoinOrder: 3,
            squareIndex:          10,
        );

        $payload = $event->broadcastWith();

        $this->assertArrayHasKey('die1', $payload);
        $this->assertArrayHasKey('die2', $payload);
        $this->assertArrayHasKey('total', $payload);
        $this->assertArrayHasKey('current_turn_join_order', $payload);
        $this->assertArrayHasKey('square_index', $payload);
    }

    public function test_broadcast_payload_contains_correct_die_values(): void
    {
        $event = new DiceRolled(
            gameId:               2,
            die1:                 2,
            die2:                 5,
            total:                7,
            currentTurnJoinOrder: 1,
            squareIndex:          7,
        );

        $payload = $event->broadcastWith();

        $this->assertSame(2, $payload['die1']);
        $this->assertSame(5, $payload['die2']);
    }

    public function test_broadcast_payload_total_equals_sum_of_dice(): void
    {
        $event = new DiceRolled(
            gameId:               3,
            die1:                 3,
            die2:                 4,
            total:                7,
            currentTurnJoinOrder: 2,
            squareIndex:          7,
        );

        $payload = $event->broadcastWith();

        $this->assertSame($payload['die1'] + $payload['die2'], $payload['total']);
    }

    public function test_broadcast_payload_contains_correct_current_turn_join_order(): void
    {
        $event = new DiceRolled(
            gameId:               5,
            die1:                 1,
            die2:                 1,
            total:                2,
            currentTurnJoinOrder: 4,
            squareIndex:          2,
        );

        $payload = $event->broadcastWith();

        $this->assertSame(4, $payload['current_turn_join_order']);
    }

    public function test_game_id_is_not_included_in_broadcast_payload(): void
    {
        $event = new DiceRolled(
            gameId:               9,
            die1:                 6,
            die2:                 6,
            total:                12,
            currentTurnJoinOrder: 1,
            squareIndex:          12,
        );

        $payload = $event->broadcastWith();

        $this->assertArrayNotHasKey('game_id', $payload);
        $this->assertArrayNotHasKey('gameId', $payload);
    }

    public function test_broadcast_payload_contains_correct_square_index(): void
    {
        $event = new DiceRolled(
            gameId:               4,
            die1:                 4,
            die2:                 3,
            total:                7,
            currentTurnJoinOrder: 1,
            squareIndex:          15,
        );

        $payload = $event->broadcastWith();

        $this->assertSame(15, $payload['square_index']);
    }

    public function test_square_index_wraps_correctly_for_board_position(): void
    {
        // Rolling 12 from square 34 lands on square 6 ((34+12) % 40 = 6)
        $event = new DiceRolled(
            gameId:               6,
            die1:                 6,
            die2:                 6,
            total:                12,
            currentTurnJoinOrder: 2,
            squareIndex:          6,
        );

        $payload = $event->broadcastWith();

        $this->assertSame(6, $payload['square_index']);
    }
}
