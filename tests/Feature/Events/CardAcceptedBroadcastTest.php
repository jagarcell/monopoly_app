<?php

namespace Tests\Feature\Events;

use App\Events\CardAccepted;
use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardAcceptedBroadcastTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Assert that the event broadcasts on the correct game-scoped channel.
     *
     * Logic: Instantiates CardAccepted with a known gameId and verifies that
     * broadcastOn() returns a public Channel whose name matches `game.{gameId}`.
     */
    public function test_event_broadcasts_on_the_correct_game_channel(): void
    {
        $event = new CardAccepted(gameId: 9);

        $channel = $event->broadcastOn();

        $this->assertInstanceOf(Channel::class, $channel);
        $this->assertSame('game.9', $channel->name);
    }

    /**
     * Assert that the broadcast payload is an empty array.
     *
     * Logic: The CardAccepted event is a pure dismiss signal; it carries no
     * card data or player identity, so broadcastWith() must return [].
     */
    public function test_broadcast_payload_is_empty(): void
    {
        $event = new CardAccepted(gameId: 1);

        $payload = $event->broadcastWith();

        $this->assertSame([], $payload);
    }
}
