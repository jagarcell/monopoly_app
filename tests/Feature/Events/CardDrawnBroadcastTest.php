<?php

namespace Tests\Feature\Events;

use App\Events\CardDrawn;
use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardDrawnBroadcastTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Assert that the event broadcasts on the correct game-scoped channel.
     *
     * Logic: Instantiates CardDrawn with a known gameId and verifies that
     * broadcastOn() returns a public Channel whose name matches `game.{gameId}`.
     */
    public function test_event_broadcasts_on_the_correct_game_channel(): void
    {
        $event = new CardDrawn(
            gameId:           7,
            type:             'chance',
            card:             ['id' => 1, 'action' => 'collect', 'text' => 'Bank pays you $50', 'amount' => 50],
            drawnByJoinOrder: 2,
            drawnByName:      'Alice',
        );

        $channel = $event->broadcastOn();

        $this->assertInstanceOf(Channel::class, $channel);
        $this->assertSame('game.7', $channel->name);
    }

    /**
     * Assert that the broadcast payload contains all required keys.
     *
     * Logic: Instantiates CardDrawn and calls broadcastWith(), then verifies
     * the 'type', 'card', and 'drawn_by_join_order' keys are all present.
     */
    public function test_broadcast_payload_contains_required_keys(): void
    {
        $event = new CardDrawn(
            gameId:           1,
            type:             'community',
            card:             ['id' => 3, 'action' => 'collect', 'text' => 'Advance to GO', 'amount' => null],
            drawnByJoinOrder: 1,
            drawnByName:      'Bob',
        );

        $payload = $event->broadcastWith();

        $this->assertArrayHasKey('type', $payload);
        $this->assertArrayHasKey('card', $payload);
        $this->assertArrayHasKey('drawn_by_join_order', $payload);
        $this->assertArrayHasKey('drawn_by_name', $payload);
    }

    /**
     * Assert that the broadcast payload carries the exact values provided to the constructor.
     *
     * Logic: Constructs a CardDrawn event with specific values and asserts each
     * field in broadcastWith() matches what was passed in.
     */
    public function test_broadcast_payload_contains_correct_values(): void
    {
        $card = ['id' => 5, 'action' => 'collect', 'text' => 'Collect $10', 'amount' => 10];

        $event = new CardDrawn(
            gameId:           42,
            type:             'chance',
            card:             $card,
            drawnByJoinOrder: 3,
            drawnByName:      'Carol',
        );

        $payload = $event->broadcastWith();

        $this->assertSame('chance', $payload['type']);
        $this->assertSame($card, $payload['card']);
        $this->assertSame(3, $payload['drawn_by_join_order']);
        $this->assertSame('Carol', $payload['drawn_by_name']);
    }

    /**
     * Assert that the 'type' field reflects the community-chest variant correctly.
     *
     * Logic: Verifies that a CardDrawn event constructed with type='community'
     * produces the expected 'community' value in its broadcast payload.
     */
    public function test_broadcast_payload_type_reflects_community_chest(): void
    {
        $event = new CardDrawn(
            gameId:           10,
            type:             'community',
            card:             ['id' => 2, 'action' => 'collect', 'text' => 'You have won second prize in a beauty contest', 'amount' => 10],
            drawnByJoinOrder: 1,
            drawnByName:      'Dave',
        );

        $payload = $event->broadcastWith();

        $this->assertSame('community', $payload['type']);
    }
}
