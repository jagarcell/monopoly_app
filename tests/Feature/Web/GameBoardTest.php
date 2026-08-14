<?php

namespace Tests\Feature\Web;

use App\Models\PlayerIcon;
use App\Models\User;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the GET /games/{gameId} route.
 *
 * Verifies that only the authenticated creator of a game can access the board
 * page and that the response includes the expected Inertia props.
 */
class GameBoardTest extends TestCase
{
    use RefreshDatabase;

    /** @var int The ID of the seeded player icon used in requests. */
    private int $iconId;

    protected function setUp(): void
    {
        parent::setUp();

        app(ChanceCardRepository::class)->seedMasterDeck();
        app(CommunityChestCardRepository::class)->seedMasterDeck();

        $icon         = PlayerIcon::create(['name' => 'Top Hat', 'image_url' => '/images/icons/top-hat.svg', 'sort_order' => 1]);
        $this->iconId = $icon->id;
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_creator_can_access_game_board(): void
    {
        $user = User::factory()->create();

        $gameData = $this->actingAs($user)
            ->postJson('/api/games', ['max_players' => 4, 'player_icon_id' => $this->iconId])
            ->json('game');

        $response = $this->actingAs($user)->get("/games/{$gameData['id']}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Game')
            ->has('game')
            ->has('players')
        );
    }

    public function test_game_board_returns_correct_game_id(): void
    {
        $user = User::factory()->create();

        $gameData = $this->actingAs($user)
            ->postJson('/api/games', ['max_players' => 4, 'player_icon_id' => $this->iconId])
            ->json('game');

        $response = $this->actingAs($user)->get("/games/{$gameData['id']}");

        $response->assertInertia(fn ($page) => $page
            ->component('Game')
            ->where('game.id', $gameData['id'])
        );
    }

    public function test_game_board_returns_players_ordered_by_join_order(): void
    {
        $user = User::factory()->create();

        $gameData = $this->actingAs($user)
            ->postJson('/api/games', ['max_players' => 4, 'player_icon_id' => $this->iconId])
            ->json('game');

        $response = $this->actingAs($user)->get("/games/{$gameData['id']}");

        $response->assertInertia(fn ($page) => $page
            ->component('Game')
            ->has('players', 1)
            ->where('players.0.join_order', 1)
            ->where('players.0.is_creator', true)
        );
    }

    // ── Auth / ownership guards ───────────────────────────────────────────────

    public function test_unauthenticated_request_redirects_to_login(): void
    {
        $user = User::factory()->create();

        $gameData = $this->actingAs($user)
            ->postJson('/api/games', ['max_players' => 4, 'player_icon_id' => $this->iconId])
            ->json('game');

        // Reset the auth guard so the next request is truly unauthenticated.
        $this->app['auth']->forgetGuards();

        $response = $this->get("/games/{$gameData['id']}");

        $response->assertRedirect('/login');
    }

    public function test_non_creator_receives_403(): void
    {
        $creator  = User::factory()->create();
        $outsider = User::factory()->create();

        $gameData = $this->actingAs($creator)
            ->postJson('/api/games', ['max_players' => 4, 'player_icon_id' => $this->iconId])
            ->json('game');

        $response = $this->actingAs($outsider)->get("/games/{$gameData['id']}");

        $response->assertForbidden();
    }

    public function test_non_existent_game_returns_404(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/games/999999');

        $response->assertNotFound();
    }

    public function test_game_board_returns_pending_invitations_prop(): void
    {
        $user = User::factory()->create();

        $gameData = $this->actingAs($user)
            ->postJson('/api/games', ['max_players' => 4, 'player_icon_id' => $this->iconId])
            ->json('game');

        // Create a pending invitation for the game.
        \App\Models\GameInvitation::create([
            'game_id'    => $gameData['id'],
            'email'      => 'pending@example.com',
            'token'      => (string) \Illuminate\Support\Str::uuid(),
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->actingAs($user)->get("/games/{$gameData['id']}");

        $response->assertInertia(fn ($page) => $page
            ->component('Game')
            ->has('pendingInvitations', 1)
            ->where('pendingInvitations.0.email', 'pending@example.com')
        );
    }

    public function test_game_board_returns_turn_phase_and_last_dice_in_game_prop(): void
    {
        $user = User::factory()->create();

        $gameData = $this->actingAs($user)
            ->postJson('/api/games', ['max_players' => 4, 'player_icon_id' => $this->iconId])
            ->json('game');

        $response = $this->actingAs($user)->get("/games/{$gameData['id']}");

        // A freshly created game has turn_phase='roll' and null dice.
        $response->assertInertia(fn ($page) => $page
            ->component('Game')
            ->where('game.turn_phase', 'roll')
            ->where('game.last_die1', null)
            ->where('game.last_die2', null)
        );
    }

    public function test_in_progress_games_page_lists_only_the_authenticated_users_active_games(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $myGame = $this->actingAs($user)
            ->postJson('/api/games', ['max_players' => 4, 'player_icon_id' => $this->iconId])
            ->json('game');

        $this->actingAs($otherUser)
            ->postJson('/api/games', ['max_players' => 4, 'player_icon_id' => $this->iconId]);

        $response = $this->actingAs($user)->get('/games/in-progress');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('GamesInProgress')
            ->has('games', 1)
            ->where('games.0.id', $myGame['id'])
        );
    }
}
