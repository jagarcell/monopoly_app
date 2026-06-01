<?php

namespace Tests\Feature\Api;

use App\Events\TokenMoved;
use App\Models\Game;
use App\Models\GameInvitation;
use App\Models\PlayerIcon;
use App\Models\User;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for token-moved notification endpoints.
 *
 * POST /api/games/{gameId}/token-moved   (authenticated)
 * POST /api/join/{token}/token-moved     (unauthenticated guest)
 *
 * Logic: Verifies each endpoint returns and broadcasts the moving player's
 * persisted jail state from game_player_icons so observer boards receive a
 * fresh incarceration flag after movement.
 */
class NotifyTokenMovedTest extends TestCase
{
    use RefreshDatabase;

    private int $ownerIconId;
    private int $guestIconId;

    protected function setUp(): void
    {
        parent::setUp();

        app(ChanceCardRepository::class)->seedMasterDeck();
        app(CommunityChestCardRepository::class)->seedMasterDeck();

        $ownerIcon = PlayerIcon::create([
            'name' => 'Top Hat',
            'image_url' => '/images/icons/top-hat.svg',
            'sort_order' => 1,
        ]);

        $guestIcon = PlayerIcon::create([
            'name' => 'Race Car',
            'image_url' => '/images/icons/race-car.svg',
            'sort_order' => 2,
        ]);

        $this->ownerIconId = $ownerIcon->id;
        $this->guestIconId = $guestIcon->id;
    }

    /**
     * Create an owner-authenticated game and return the user and model.
     *
     * @return array{user: User, game: Game}
     */
    private function makeOwnerGame(): array
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/games', [
            'max_players' => 4,
            'player_icon_id' => $this->ownerIconId,
        ]);

        $response->assertCreated();

        $game = Game::findOrFail($response->json('game.id'));

        return compact('user', 'game');
    }

    /**
     * Create an accepted guest invitation and assign guest icon row.
     *
     * @param  Game  $game
     * @return array{invitation: GameInvitation, token: string}
     */
    private function inviteAndAssignGuest(Game $game): array
    {
        $token = (string) Str::uuid();

        $invitation = GameInvitation::create([
            'game_id' => $game->id,
            'email' => 'guest@example.com',
            'token' => $token,
            'status' => 'accepted',
            'accepted_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        DB::table('game_player_icons')->insert([
            'game_id' => $game->id,
            'user_id' => null,
            'player_icon_id' => $this->guestIconId,
            'invitation_id' => $invitation->id,
            'join_order' => 2,
            'capital' => 1500,
            'square_index' => 10,
            'is_in_jail' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('invitation', 'token');
    }

    public function test_authenticated_token_moved_returns_and_broadcasts_fresh_persisted_jail_state(): void
    {
        Event::fake([TokenMoved::class]);

        ['user' => $user, 'game' => $game] = $this->makeOwnerGame();

        // Force persisted owner state to jailed before notifying movement.
        DB::table('game_player_icons')
            ->where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->update([
                'square_index' => 10,
                'is_in_jail' => true,
                'updated_at' => now(),
            ]);

        $response = $this->actingAs($user)->postJson("/api/games/{$game->id}/token-moved");

        $response->assertOk();
        $response->assertJsonPath('join_order', 1);
        $response->assertJsonPath('square_index', 10);
        $response->assertJsonPath('isInJail', true);
        $response->assertJsonPath('is_in_jail', true);

        Event::assertDispatched(TokenMoved::class, function (TokenMoved $event) use ($game): bool {
            return $event->gameId === $game->id
                && $event->joinOrder === 1
                && $event->squareIndex === 10
                && $event->isInJail === true;
        });
    }

    public function test_guest_token_moved_returns_and_broadcasts_fresh_persisted_jail_state(): void
    {
        Event::fake([TokenMoved::class]);

        ['game' => $game] = $this->makeOwnerGame();
        ['token' => $token, 'invitation' => $invitation] = $this->inviteAndAssignGuest($game);

        // Force persisted guest state to not jailed before notifying movement.
        DB::table('game_player_icons')
            ->where('game_id', $game->id)
            ->where('invitation_id', $invitation->id)
            ->update([
                'square_index' => 11,
                'is_in_jail' => false,
                'updated_at' => now(),
            ]);

        $response = $this->postJson("/api/join/{$token}/token-moved");

        $response->assertOk();
        $response->assertJsonPath('join_order', 2);
        $response->assertJsonPath('square_index', 11);
        $response->assertJsonPath('isInJail', false);
        $response->assertJsonPath('is_in_jail', false);

        Event::assertDispatched(TokenMoved::class, function (TokenMoved $event) use ($game): bool {
            return $event->gameId === $game->id
                && $event->joinOrder === 2
                && $event->squareIndex === 11
                && $event->isInJail === false;
        });
    }
}
