<?php

namespace Tests\Feature\Api;

use App\Events\TurnAdvanced;
use App\Models\Game;
use App\Models\GameInvitation;
use App\Models\PlayerIcon;
use App\Models\User;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Feature tests for the end-turn endpoints.
 *
 * POST /api/games/{gameId}/turn/end   (authenticated)
 * POST /api/join/{token}/turn/end     (unauthenticated guest)
 *
 * The creator always has join_order 1 and goes first. Turns advance cyclically
 * only when the active player explicitly signals they are done.
 */
class EndTurnTest extends TestCase
{
    use RefreshDatabase;

    private int $iconId;
    private int $guestIconId;

    protected function setUp(): void
    {
        parent::setUp();

        app(ChanceCardRepository::class)->seedMasterDeck();
        app(CommunityChestCardRepository::class)->seedMasterDeck();

        $icon            = PlayerIcon::create(['name' => 'Top Hat',  'image_url' => '/icons/hat.svg', 'sort_order' => 1]);
        $guestIcon       = PlayerIcon::create(['name' => 'Race Car', 'image_url' => '/icons/car.svg', 'sort_order' => 2]);
        $this->iconId      = $icon->id;
        $this->guestIconId = $guestIcon->id;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUserAndGame(): array
    {
        $user     = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/games', [
            'max_players'    => 4,
            'player_icon_id' => $this->iconId,
        ]);
        $response->assertCreated();

        $game = Game::find($response->json('game.id'));
        return compact('user', 'game');
    }

    private function inviteAndAcceptGuest(Game $game): array
    {
        $token      = (string) \Illuminate\Support\Str::uuid();
        $invitation = GameInvitation::create([
            'game_id'     => $game->id,
            'email'       => 'guest@example.com',
            'token'       => $token,
            'status'      => 'accepted',
            'accepted_at' => now(),
            'expires_at'  => now()->addDay(),
        ]);

        DB::table('game_player_icons')->insert([
            'game_id'        => $game->id,
            'user_id'        => null,
            'player_icon_id' => $this->guestIconId,
            'invitation_id'  => $invitation->id,
            'join_order'     => 2,
            'capital'        => 1500,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return compact('invitation', 'token');
    }

    // ── Authenticated owner ends turn ─────────────────────────────────────────

    public function test_creator_can_end_turn_and_receives_next_join_order(): void
    {
        Event::fake([TurnAdvanced::class]);
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();
        $this->inviteAndAcceptGuest($game);

        $response = $this->actingAs($user)->postJson("/api/games/{$game->id}/turn/end");

        $response->assertOk();
        $response->assertJsonStructure(['current_turn_join_order']);
    }

    public function test_ending_turn_advances_current_turn_join_order_cyclically(): void
    {
        Event::fake([TurnAdvanced::class]);
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();
        $this->inviteAndAcceptGuest($game);

        // Creator (join_order 1) ends turn — should advance to guest (join_order 2).
        $response = $this->actingAs($user)->postJson("/api/games/{$game->id}/turn/end");
        $response->assertOk();
        $this->assertSame(2, $response->json('current_turn_join_order'));

        $this->assertSame(2, (int) Game::find($game->id)->current_turn_join_order);
    }

    public function test_end_turn_wraps_around_to_first_player_after_last(): void
    {
        Event::fake([TurnAdvanced::class]);
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();
        ['token' => $token] = $this->inviteAndAcceptGuest($game);

        // Set current turn to the guest (join_order 2 — the last player).
        DB::table('games')->where('id', $game->id)->update(['current_turn_join_order' => 2]);

        // Guest ends turn — should wrap back to join_order 1 (creator).
        $response = $this->postJson("/api/join/{$token}/turn/end");
        $response->assertOk();
        $this->assertSame(1, $response->json('current_turn_join_order'));

        $this->assertSame(1, (int) Game::find($game->id)->current_turn_join_order);
    }

    public function test_creator_cannot_end_turn_when_it_is_not_their_turn(): void
    {
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();

        // Move current turn away from the creator.
        DB::table('games')->where('id', $game->id)->update(['current_turn_join_order' => 2]);

        $response = $this->actingAs($user)->postJson("/api/games/{$game->id}/turn/end");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'It is not your turn.');
    }

    public function test_end_turn_endpoint_returns_404_when_game_not_found(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/games/99999/turn/end');

        $response->assertNotFound();
    }

    public function test_end_turn_endpoint_requires_authentication(): void
    {
        $response = $this->postJson('/api/games/1/turn/end');

        $response->assertUnauthorized();
    }

    public function test_end_turn_dispatches_turn_advanced_event(): void
    {
        Event::fake([TurnAdvanced::class]);
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();
        $this->inviteAndAcceptGuest($game);

        $this->actingAs($user)->postJson("/api/games/{$game->id}/turn/end")->assertOk();

        Event::assertDispatched(TurnAdvanced::class, function (TurnAdvanced $e) use ($game) {
            return $e->gameId === $game->id;
        });
    }

    // ── Guest ends turn ───────────────────────────────────────────────────────

    public function test_guest_can_end_turn_on_their_turn(): void
    {
        Event::fake([TurnAdvanced::class]);
        ['game' => $game] = $this->makeUserAndGame();
        ['token' => $token] = $this->inviteAndAcceptGuest($game);

        // Move current turn to the guest (join_order 2).
        DB::table('games')->where('id', $game->id)->update(['current_turn_join_order' => 2]);

        $response = $this->postJson("/api/join/{$token}/turn/end");

        $response->assertOk();
        $response->assertJsonStructure(['current_turn_join_order']);
        // Wraps back to creator (join_order 1).
        $this->assertSame(1, $response->json('current_turn_join_order'));
    }

    public function test_guest_cannot_end_turn_when_it_is_not_their_turn(): void
    {
        ['game' => $game] = $this->makeUserAndGame();
        ['token' => $token] = $this->inviteAndAcceptGuest($game);

        // current_turn_join_order defaults to 1 (creator's turn).
        $response = $this->postJson("/api/join/{$token}/turn/end");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'It is not your turn.');
    }

    public function test_guest_end_turn_with_invalid_token_returns_422(): void
    {
        $response = $this->postJson('/api/join/invalid-token/turn/end');

        $response->assertStatus(422);
    }

    public function test_guest_end_turn_dispatches_turn_advanced_event(): void
    {
        Event::fake([TurnAdvanced::class]);
        ['game' => $game] = $this->makeUserAndGame();
        ['token' => $token] = $this->inviteAndAcceptGuest($game);

        DB::table('games')->where('id', $game->id)->update(['current_turn_join_order' => 2]);

        $this->postJson("/api/join/{$token}/turn/end")->assertOk();

        Event::assertDispatched(TurnAdvanced::class, function (TurnAdvanced $e) use ($game) {
            return $e->gameId === $game->id;
        });
    }
}
