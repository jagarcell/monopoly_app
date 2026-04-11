<?php

namespace Tests\Feature\Api;

use App\Models\Game;
use App\Models\PlayerIcon;
use App\Models\User;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameTest extends TestCase
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

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/games');

        $response->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_game(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/games', ['max_players' => 4, 'player_icon_id' => $this->iconId]);

        $response->assertCreated();
        $response->assertJsonStructure(['game' => ['id', 'name', 'user_id', 'status']]);
    }

    public function test_created_game_is_persisted_in_database(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/games', ['max_players' => 4, 'player_icon_id' => $this->iconId]);

        $this->assertDatabaseHas('games', [
            'user_id' => $user->id,
            'name'    => 'Game #1',
        ]);
    }

    public function test_game_is_named_sequentially(): void
    {
        $user = User::factory()->create();

        Game::factory()->create(['user_id' => $user->id, 'name' => 'Game #1']);

        $response = $this->actingAs($user)->postJson('/api/games', ['max_players' => 4, 'player_icon_id' => $this->iconId]);

        $response->assertCreated();
        $this->assertSame('Game #2', $response->json('game.name'));
    }

    public function test_game_belongs_to_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/games', ['max_players' => 4, 'player_icon_id' => $this->iconId]);

        $this->assertSame($user->id, $response->json('game.user_id'));
    }

    public function test_game_has_in_progress_status_by_default(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/games', ['max_players' => 4, 'player_icon_id' => $this->iconId]);

        $this->assertSame('in_progress', $response->json('game.status'));
    }

    public function test_different_users_games_are_numbered_independently(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        Game::factory()->create(['user_id' => $userA->id, 'name' => 'Game #1']);
        Game::factory()->create(['user_id' => $userA->id, 'name' => 'Game #2']);

        $response = $this->actingAs($userB)->postJson('/api/games', ['max_players' => 4, 'player_icon_id' => $this->iconId]);

        $this->assertSame('Game #1', $response->json('game.name'));
    }
}
