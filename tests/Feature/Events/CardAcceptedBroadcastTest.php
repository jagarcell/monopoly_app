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
     * Assert that the broadcast payload defaults to an empty array.
     *
     * Logic: A plain CardAccepted event still functions as a dismiss signal and
     * should not carry any payment payload unless the service explicitly adds
     * one.
     */
    public function test_broadcast_payload_defaults_to_empty(): void
    {
        $event = new CardAccepted(gameId: 1);

        $payload = $event->broadcastWith();

        $this->assertSame([], $payload);
    }

    /**
     * Assert that the broadcast payload is preserved when supplied.
     *
     * Logic: When a deferred card payment is resolved, the service layer passes
     * the updated balances through the event payload so observer boards can
     * merge the same state reactively.
     */
    public function test_broadcast_payload_includes_payment_result_when_supplied(): void
    {
        $payload = ['payer' => ['join_order' => 1, 'capital' => 1200]];

        $event = new CardAccepted(gameId: 1, payload: $payload);

        $this->assertSame($payload, $event->broadcastWith());
    }
}
