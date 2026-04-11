<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the POST /api/games endpoint, specifically covering
 * the max_players field introduced with the player-count dialog.
 *
 * Verifies validation rules, persistence, and that the field is returned in
 * the API response.
 */
class GameCreationWithMaxPlayersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ChanceCardRepository::class)->seedMasterDeck();
        app(CommunityChestCardRepository::class)->seedMasterDeck();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/games', ['max_players' => 4]);

        $response->assertUnauthorized();
    }

    public function test_max_players_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/games', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['max_players']);
    }

    public function test_max_players_must_be_at_least_2(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/games', ['max_players' => 1]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['max_players']);
    }

    public function test_max_players_must_not_exceed_8(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/games', ['max_players' => 9]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['max_players']);
    }

    public function test_max_players_must_be_an_integer(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/games', ['max_players' => 'four']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['max_players']);
    }

    public function test_game_is_created_with_valid_max_players(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/games', ['max_players' => 4]);

        $response->assertCreated();
        $response->assertJsonPath('game.max_players', 4);
    }

    public function test_game_persists_max_players_in_database(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/games', ['max_players' => 6]);
        $response->assertCreated();

        $this->assertDatabaseHas('games', [
            'id'          => $response->json('game.id'),
            'max_players' => 6,
        ]);
    }

    public function test_game_can_be_created_with_minimum_players(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/games', ['max_players' => 2]);

        $response->assertCreated();
        $response->assertJsonPath('game.max_players', 2);
    }

    public function test_game_can_be_created_with_maximum_players(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/games', ['max_players' => 8]);

        $response->assertCreated();
        $response->assertJsonPath('game.max_players', 8);
    }
}
