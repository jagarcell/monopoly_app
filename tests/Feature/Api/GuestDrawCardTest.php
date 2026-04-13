<?php

namespace Tests\Feature\Api;

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
 * Feature tests for the guest card draw endpoints.
 *
 * POST /api/join/{token}/chance/draw
 * POST /api/join/{token}/community/draw
 *
 * Both endpoints require an accepted invitation token.
 * Pending or unknown tokens are rejected with 422.
 */
class GuestDrawCardTest extends TestCase
{
    use RefreshDatabase;

    /** @var int The ID of the seeded player icon used in game creation requests. */
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
     * Create a user, game, and an invitation with the given overrides.
     *
     * @param  array<string, mixed>  $overrides  Overrides applied to the invitation.
     * @return array{user: User, game: array<string, mixed>, invitation: GameInvitation, token: string}
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

    // ── Chance card – happy path ───────────────────────────────────────────────

    public function test_guest_can_draw_chance_card_with_accepted_token(): void
    {
        ['token' => $token, 'invitation' => $invitation] = $this->makeGameAndInvitation();

        $invitation->accepted_at = now();
        $invitation->save();

        $response = $this->postJson("/api/join/{$token}/chance/draw");

        $response->assertOk();
        $response->assertJsonStructure(['card' => ['id', 'action', 'text']]);
    }

    public function test_guest_chance_draw_returns_card_data(): void
    {
        ['token' => $token, 'invitation' => $invitation] = $this->makeGameAndInvitation();

        $invitation->accepted_at = now();
        $invitation->save();

        $response = $this->postJson("/api/join/{$token}/chance/draw");

        $this->assertNotNull($response->json('card.action'));
        $this->assertNotNull($response->json('card.text'));
    }

    // ── Community Chest card – happy path ─────────────────────────────────────

    public function test_guest_can_draw_community_chest_card_with_accepted_token(): void
    {
        ['token' => $token, 'invitation' => $invitation] = $this->makeGameAndInvitation();

        $invitation->accepted_at = now();
        $invitation->save();

        $response = $this->postJson("/api/join/{$token}/community/draw");

        $response->assertOk();
        $response->assertJsonStructure(['card' => ['id', 'action', 'text']]);
    }

    public function test_guest_community_draw_returns_card_data(): void
    {
        ['token' => $token, 'invitation' => $invitation] = $this->makeGameAndInvitation();

        $invitation->accepted_at = now();
        $invitation->save();

        $response = $this->postJson("/api/join/{$token}/community/draw");

        $this->assertNotNull($response->json('card.action'));
        $this->assertNotNull($response->json('card.text'));
    }

    // ── Rejection: pending token ───────────────────────────────────────────────

    public function test_pending_token_rejected_for_chance_draw(): void
    {
        ['token' => $token] = $this->makeGameAndInvitation(); // accepted_at = null

        $response = $this->postJson("/api/join/{$token}/chance/draw");

        $response->assertUnprocessable();
        $response->assertJsonPath('message', 'This invitation has not been accepted yet.');
    }

    public function test_pending_token_rejected_for_community_draw(): void
    {
        ['token' => $token] = $this->makeGameAndInvitation(); // accepted_at = null

        $response = $this->postJson("/api/join/{$token}/community/draw");

        $response->assertUnprocessable();
        $response->assertJsonPath('message', 'This invitation has not been accepted yet.');
    }

    // ── Rejection: unknown token ───────────────────────────────────────────────

    public function test_unknown_token_rejected_for_chance_draw(): void
    {
        $response = $this->postJson('/api/join/00000000-0000-4000-8000-000000000000/chance/draw');

        $response->assertUnprocessable();
        $response->assertJsonPath('message', 'Invitation not found.');
    }

    public function test_unknown_token_rejected_for_community_draw(): void
    {
        $response = $this->postJson('/api/join/00000000-0000-4000-8000-000000000000/community/draw');

        $response->assertUnprocessable();
        $response->assertJsonPath('message', 'Invitation not found.');
    }
}
