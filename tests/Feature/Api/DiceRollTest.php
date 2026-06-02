<?php

namespace Tests\Feature\Api;

use App\Events\DiceRolled;
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
 * Feature tests for the dice roll endpoints.
 *
 * POST /api/games/{gameId}/roll      (authenticated)
 * POST /api/join/{token}/roll        (unauthenticated guest)
 *
 * The creator always has join_order 1 and goes first. Turns advance cyclically.
 */
class DiceRollTest extends TestCase
{
    use RefreshDatabase;

    private int $iconId;
    private int $guestIconId;

    protected function setUp(): void
    {
        parent::setUp();

        app(ChanceCardRepository::class)->seedMasterDeck();
        app(CommunityChestCardRepository::class)->seedMasterDeck();

        $icon            = PlayerIcon::create(['name' => 'Top Hat',   'image_url' => '/icons/hat.svg',  'sort_order' => 1]);
        $guestIcon       = PlayerIcon::create(['name' => 'Race Car',  'image_url' => '/icons/car.svg',  'sort_order' => 2]);
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

    // ── Authenticated owner rolls ─────────────────────────────────────────────

    public function test_creator_can_roll_on_their_turn_and_receives_dice_values(): void
    {
        Event::fake([DiceRolled::class]);
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();

        $response = $this->actingAs($user)->postJson("/api/games/{$game->id}/roll");

        $response->assertOk();
        $response->assertJsonStructure(['die1', 'die2', 'total', 'current_turn_join_order']);

        $die1  = $response->json('die1');
        $die2  = $response->json('die2');
        $total = $response->json('total');

        $this->assertGreaterThanOrEqual(1, $die1);
        $this->assertLessThanOrEqual(6, $die1);
        $this->assertGreaterThanOrEqual(1, $die2);
        $this->assertLessThanOrEqual(6, $die2);
        $this->assertSame($die1 + $die2, $total);
    }

    public function test_creator_roll_requires_jail_payment_on_third_unpaid_turn(): void
    {
        Event::fake([DiceRolled::class]);
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();

        DB::table('game_player_icons')
            ->where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->update([
                'is_in_jail' => true,
                'square_index' => 10,
                'jail_turns' => 2,
                'has_paid_jail_release' => false,
                'updated_at' => now(),
            ]);

        $response = $this->actingAs($user)->postJson("/api/games/{$game->id}/roll");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'You must pay $50 to leave jail before rolling.');
        Event::assertNotDispatched(DiceRolled::class);
    }

    public function test_rolling_does_not_advance_current_turn_join_order(): void
    {
        Event::fake([DiceRolled::class]);
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();
        $this->inviteAndAcceptGuest($game);

        // Creator (join_order 1) rolls — turn should NOT advance yet; they must click Done.
        $response = $this->actingAs($user)->postJson("/api/games/{$game->id}/roll");
        $response->assertOk();
        $this->assertSame(1, $response->json('current_turn_join_order'));

        $this->assertSame(1, (int) Game::find($game->id)->current_turn_join_order);
    }

    public function test_roll_keeps_turn_on_last_player_until_done(): void
    {
        Event::fake([DiceRolled::class]);
        ['game' => $game] = $this->makeUserAndGame();
        ['token' => $token] = $this->inviteAndAcceptGuest($game);

        // Set current turn to the guest (join_order 2 — the last player).
        DB::table('games')->where('id', $game->id)->update(['current_turn_join_order' => 2]);

        // Guest rolls — turn should remain on join_order 2 until they click Done.
        $response = $this->postJson("/api/join/{$token}/roll");
        $response->assertOk();
        $this->assertSame(2, $response->json('current_turn_join_order'));
    }

    public function test_creator_cannot_roll_when_it_is_not_their_turn(): void
    {
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();

        // Move current turn away from the creator.
        DB::table('games')->where('id', $game->id)->update(['current_turn_join_order' => 2]);

        $response = $this->actingAs($user)->postJson("/api/games/{$game->id}/roll");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'It is not your turn to roll.');
    }

    public function test_roll_endpoint_returns_404_when_game_not_found(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/games/99999/roll');

        $response->assertNotFound();
    }

    public function test_roll_endpoint_requires_authentication(): void
    {
        $response = $this->postJson('/api/games/1/roll');

        $response->assertUnauthorized();
    }

    public function test_dice_roll_broadcasts_dice_rolled_event(): void
    {
        Event::fake([DiceRolled::class]);
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();

        $this->actingAs($user)->postJson("/api/games/{$game->id}/roll")->assertOk();

        Event::assertDispatched(DiceRolled::class, function (DiceRolled $e) use ($game) {
            return $e->gameId === $game->id;
        });
    }

    // ── Guest rolls ───────────────────────────────────────────────────────────

    public function test_guest_can_roll_on_their_turn(): void
    {
        Event::fake([DiceRolled::class]);
        ['game' => $game] = $this->makeUserAndGame();
        ['token' => $token] = $this->inviteAndAcceptGuest($game);

        // Move current turn to the guest (join_order 2).
        DB::table('games')->where('id', $game->id)->update(['current_turn_join_order' => 2]);

        $response = $this->postJson("/api/join/{$token}/roll");

        $response->assertOk();
        $response->assertJsonStructure(['die1', 'die2', 'total', 'current_turn_join_order']);
        // Turn stays on the guest until they click Done.
        $this->assertSame(2, $response->json('current_turn_join_order'));
    }

    public function test_guest_cannot_roll_when_it_is_not_their_turn(): void
    {
        ['game' => $game] = $this->makeUserAndGame();
        ['token' => $token] = $this->inviteAndAcceptGuest($game);

        // current_turn_join_order defaults to 1 (creator's turn).
        $response = $this->postJson("/api/join/{$token}/roll");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'It is not your turn to roll.');
    }

    public function test_guest_roll_with_invalid_token_returns_422(): void
    {
        $response = $this->postJson('/api/join/invalid-token/roll');

        $response->assertStatus(422);
    }
}
