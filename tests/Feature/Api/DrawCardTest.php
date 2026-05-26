<?php

namespace Tests\Feature\Api;

use App\Models\Game;
use App\Models\PlayerIcon;
use App\Models\User;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for the card draw endpoints.
 *
 * POST /api/games/{gameId}/chance/draw
 * POST /api/games/{gameId}/community/draw
 *
 * Both endpoints require authentication and ownership. Drawing returns the
 * next available card from the active deck; held get-out-of-jail-free cards
 * are excluded until the holder uses them and returns them to the bottom.
 */
class DrawCardTest extends TestCase
{
    use RefreshDatabase;

    /** @var int The ID of the seeded player icon used in game creation requests. */
    private int $iconId;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed master decks — required before any game creation can populate pivot tables.
        app(ChanceCardRepository::class)->seedMasterDeck();
        app(CommunityChestCardRepository::class)->seedMasterDeck();

        $icon         = PlayerIcon::create(['name' => 'Top Hat', 'image_url' => '/images/icons/top-hat.svg', 'sort_order' => 1]);
        $this->iconId = $icon->id;
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    /**
     * Create a user + game with fully seeded chance and community chest decks.
     *
     * @return array{user: User, game: Game}
     */
    private function makeUserAndGame(): array
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/games', [
            'max_players'    => 4,
            'player_icon_id' => $this->iconId,
        ]);
        $response->assertCreated();

        $game = Game::find($response->json('game.id'));

        return ['user' => $user, 'game' => $game];
    }

    // ── POST /api/games/{gameId}/chance/draw ──────────────────────────────────

    public function test_draw_chance_unauthenticated_is_rejected(): void
    {
        $response = $this->postJson('/api/games/1/chance/draw');

        $response->assertUnauthorized();
    }

    public function test_draw_chance_returns_404_for_unknown_game(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/games/99999/chance/draw');

        $response->assertNotFound();
    }

    public function test_draw_chance_returns_403_when_user_does_not_own_game(): void
    {
        ['game' => $game] = $this->makeUserAndGame();
        $otherUser        = User::factory()->create();

        $response = $this->actingAs($otherUser)->postJson("/api/games/{$game->id}/chance/draw");

        $response->assertForbidden();
    }

    public function test_draw_chance_returns_card_data(): void
    {
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();

        $response = $this->actingAs($user)->postJson("/api/games/{$game->id}/chance/draw");

        $response->assertOk();
        $response->assertJsonStructure(['card' => ['id', 'action', 'text']]);
    }

    public function test_draw_chance_moves_drawn_card_to_bottom_of_deck(): void
    {
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();

        $firstCardId = DB::table('game_chance_cards')
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->value('chance_card_id');

        $this->actingAs($user)->postJson("/api/games/{$game->id}/chance/draw");

        $newOrder = DB::table('game_chance_cards')
            ->where('game_id', $game->id)
            ->where('chance_card_id', $firstCardId)
            ->value('sort_order');

        $this->assertSame(16, $newOrder);
    }

    public function test_draw_chance_returns_card_with_lowest_sort_order(): void
    {
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();

        $firstCardId = DB::table('game_chance_cards')
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->value('chance_card_id');

        $response = $this->actingAs($user)->postJson("/api/games/{$game->id}/chance/draw");

        $this->assertSame($firstCardId, $response->json('card.id'));
    }

    public function test_draw_chance_second_draw_returns_different_card(): void
    {
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();

        $first  = $this->actingAs($user)->postJson("/api/games/{$game->id}/chance/draw")->json('card.id');
        $second = $this->actingAs($user)->postJson("/api/games/{$game->id}/chance/draw")->json('card.id');

        $this->assertNotSame($first, $second);
    }

    public function test_draw_chance_skips_card_held_by_owner_and_keeps_it_assigned_after_accept(): void
    {
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();

        $topCardId = DB::table('game_chance_cards')
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->value('chance_card_id');

        $secondCardId = DB::table('game_chance_cards')
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->offset(1)
            ->value('chance_card_id');

        app(ChanceCardRepository::class)->assignCardToPlayer($game->id, (int) $topCardId, 1);

        $drawResponse = $this->actingAs($user)->postJson("/api/games/{$game->id}/chance/draw");

        $drawResponse->assertOk();
        $this->assertSame($secondCardId, $drawResponse->json('card.id'));

        $acceptResponse = $this->actingAs($user)->postJson("/api/games/{$game->id}/card/accept");

        $acceptResponse->assertOk();

        $heldCard = DB::table('game_chance_cards')
            ->where('game_id', $game->id)
            ->where('chance_card_id', $topCardId)
            ->first(['holder_join_order', 'sort_order']);

        $this->assertSame(1, (int) $heldCard->holder_join_order);
        $this->assertSame(1, (int) $heldCard->sort_order);
    }

    // ── POST /api/games/{gameId}/community/draw ───────────────────────────────

    public function test_draw_community_unauthenticated_is_rejected(): void
    {
        $response = $this->postJson('/api/games/1/community/draw');

        $response->assertUnauthorized();
    }

    public function test_draw_community_returns_404_for_unknown_game(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/games/99999/community/draw');

        $response->assertNotFound();
    }

    public function test_draw_community_returns_403_when_user_does_not_own_game(): void
    {
        ['game' => $game] = $this->makeUserAndGame();
        $otherUser        = User::factory()->create();

        $response = $this->actingAs($otherUser)->postJson("/api/games/{$game->id}/community/draw");

        $response->assertForbidden();
    }

    public function test_draw_community_returns_card_data(): void
    {
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();

        $response = $this->actingAs($user)->postJson("/api/games/{$game->id}/community/draw");

        $response->assertOk();
        $response->assertJsonStructure(['card' => ['id', 'action', 'text']]);
    }

    public function test_draw_community_moves_drawn_card_to_bottom_of_deck(): void
    {
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();

        $firstCardId = DB::table('game_community_chest_cards')
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->value('community_chest_card_id');

        $this->actingAs($user)->postJson("/api/games/{$game->id}/community/draw");

        $newOrder = DB::table('game_community_chest_cards')
            ->where('game_id', $game->id)
            ->where('community_chest_card_id', $firstCardId)
            ->value('sort_order');

        $this->assertSame(16, $newOrder);
    }

    public function test_draw_community_returns_card_with_lowest_sort_order(): void
    {
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();

        $firstCardId = DB::table('game_community_chest_cards')
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->value('community_chest_card_id');

        $response = $this->actingAs($user)->postJson("/api/games/{$game->id}/community/draw");

        $this->assertSame($firstCardId, $response->json('card.id'));
    }

    public function test_draw_community_second_draw_returns_different_card(): void
    {
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();

        $first  = $this->actingAs($user)->postJson("/api/games/{$game->id}/community/draw")->json('card.id');
        $second = $this->actingAs($user)->postJson("/api/games/{$game->id}/community/draw")->json('card.id');

        $this->assertNotSame($first, $second);
    }

    public function test_draw_community_skips_card_held_by_owner_and_keeps_it_assigned_after_accept(): void
    {
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();

        $topCardId = DB::table('game_community_chest_cards')
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->value('community_chest_card_id');

        $secondCardId = DB::table('game_community_chest_cards')
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->offset(1)
            ->value('community_chest_card_id');

        app(CommunityChestCardRepository::class)->assignCardToPlayer($game->id, (int) $topCardId, 1);

        $drawResponse = $this->actingAs($user)->postJson("/api/games/{$game->id}/community/draw");

        $drawResponse->assertOk();
        $this->assertSame($secondCardId, $drawResponse->json('card.id'));

        $acceptResponse = $this->actingAs($user)->postJson("/api/games/{$game->id}/card/accept");

        $acceptResponse->assertOk();

        $heldCard = DB::table('game_community_chest_cards')
            ->where('game_id', $game->id)
            ->where('community_chest_card_id', $topCardId)
            ->first(['holder_join_order', 'sort_order']);

        $this->assertSame(1, (int) $heldCard->holder_join_order);
        $this->assertSame(1, (int) $heldCard->sort_order);
    }
}
