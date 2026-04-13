<?php

namespace Tests\Feature\Web;

use App\Models\Game;
use App\Models\GameInvitation;
use App\Models\PlayerIcon;
use App\Models\User;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for the GET /join/{token}/game route.
 *
 * Verifies that only holders of an accepted invitation token can access the
 * guest game board page and that invalid/pending tokens are rejected gracefully.
 */
class GuestGameTest extends TestCase
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

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create a user, game, and a game invitation with the given overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{user: User, game: Game, invitation: GameInvitation, token: string}
     */
    private function makeGameAndInvitation(array $overrides = []): array
    {
        $user = User::factory()->create();

        $game = $this->actingAs($user)
            ->postJson('/api/games', ['max_players' => 4, 'player_icon_id' => $this->iconId])
            ->json('game');

        $token = (string) Str::uuid();

        $invitation = GameInvitation::create(array_merge([
            'game_id'    => $game['id'],
            'email'      => 'guest@example.com',
            'token'      => $token,
            'expires_at' => now()->addDays(7),
        ], $overrides));

        return [
            'user'       => $user,
            'game'       => $game,
            'invitation' => $invitation,
            'token'      => $token,
        ];
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_accepted_token_renders_guest_game_page(): void
    {
        ['token' => $token, 'invitation' => $invitation] = $this->makeGameAndInvitation();

        $invitation->accepted_at = now();
        $invitation->save();

        $response = $this->get("/join/{$token}/game");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('GuestGame')
            ->has('game')
            ->has('token')
            ->where('error', null)
        );
    }

    public function test_accepted_token_passes_correct_game_id(): void
    {
        ['token' => $token, 'invitation' => $invitation, 'game' => $game] = $this->makeGameAndInvitation();

        $invitation->accepted_at = now();
        $invitation->save();

        $response = $this->get("/join/{$token}/game");

        $response->assertInertia(fn ($page) => $page
            ->component('GuestGame')
            ->where('game.id', $game['id'])
        );
    }

    // ── Rejection cases ───────────────────────────────────────────────────────

    public function test_pending_token_returns_error_prop(): void
    {
        ['token' => $token] = $this->makeGameAndInvitation();

        $response = $this->get("/join/{$token}/game");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('GuestGame')
            ->where('game', null)
            ->where('token', null)
            ->has('error')
        );
    }

    public function test_unknown_token_returns_error_prop(): void
    {
        $response = $this->get('/join/00000000-0000-4000-8000-000000000000/game');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('GuestGame')
            ->has('error')
        );
    }
}
