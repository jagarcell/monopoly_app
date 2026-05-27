<?php

namespace Tests\Unit\Repositories;

use App\Models\Game;
use App\Models\PlayerIcon;
use App\Models\User;
use App\Repositories\GamePropertyRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GamePropertyRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private GamePropertyRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new GamePropertyRepository();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * Insert a minimal player icon fixture required by the pivot table FK.
     */
    private function seedIcon(): int
    {
        return DB::table('player_icons')->insertGetId([
            'name'       => 'Top Hat',
            'image_url'  => '/images/icons/top-hat.svg',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Create a game and seed two players into game_player_icons.
     *
     * @return array{game_id: int, user_id: int, join_order_1: int, join_order_2: int}
     */
    private function seedGameWithTwoPlayers(): array
    {
        $iconId = $this->seedIcon();
        $iconId2 = DB::table('player_icons')->insertGetId([
            'name'       => 'Scottie Dog',
            'image_url'  => '/images/icons/scottie-dog.svg',
            'sort_order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user1 = User::factory()->create(['name' => 'Alice']);
        $user2 = User::factory()->create(['name' => 'Bob']);
        $game  = Game::factory()->create(['user_id' => $user1->id]);

        DB::table('game_player_icons')->insert([
            [
                'game_id'        => $game->id,
                'user_id'        => $user1->id,
                'player_icon_id' => $iconId,
                'invitation_id'  => null,
                'join_order'     => 1,
                'capital'        => 1500,
                'square_index'   => 0,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [
                'game_id'        => $game->id,
                'user_id'        => $user2->id,
                'player_icon_id' => $iconId2,
                'invitation_id'  => null,
                'join_order'     => 2,
                'capital'        => 1500,
                'square_index'   => 0,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
        ]);

        return [
            'game_id'      => $game->id,
            'user1_id'     => $user1->id,
            'user2_id'     => $user2->id,
            'join_order_1' => 1,
            'join_order_2' => 2,
        ];
    }

    // ── findOwnerBySquare ─────────────────────────────────────────────────────

    public function test_find_owner_returns_null_for_unowned_square(): void
    {
        $data   = $this->seedGameWithTwoPlayers();
        $result = $this->repository->findOwnerBySquare($data['game_id'], 39);

        $this->assertNull($result);
    }

    public function test_find_owner_returns_owner_data_after_purchase(): void
    {
        $data = $this->seedGameWithTwoPlayers();

        $this->repository->createOwnership($data['game_id'], 39, $data['join_order_1'], 400);

        $result = $this->repository->findOwnerBySquare($data['game_id'], 39);

        $this->assertNotNull($result);
        $this->assertSame($data['join_order_1'], $result['owner_join_order']);
        $this->assertSame('Alice', $result['owner_name']);
        $this->assertFalse($result['is_mortgaged']);
    }

    public function test_find_owner_does_not_leak_across_games(): void
    {
        $data1 = $this->seedGameWithTwoPlayers();

        $icon = DB::table('player_icons')->insertGetId([
            'name'       => 'Iron',
            'image_url'  => '/images/icons/iron.svg',
            'sort_order' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user3 = User::factory()->create();
        $game2 = Game::factory()->create(['user_id' => $user3->id]);
        DB::table('game_player_icons')->insert([
            'game_id'        => $game2->id,
            'user_id'        => $user3->id,
            'player_icon_id' => $icon,
            'invitation_id'  => null,
            'join_order'     => 1,
            'capital'        => 1500,
            'square_index'   => 0,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Own square 39 in game1 — should NOT be visible in game2.
        $this->repository->createOwnership($data1['game_id'], 39, 1, 400);

        $result = $this->repository->findOwnerBySquare($game2->id, 39);
        $this->assertNull($result);
    }

    // ── createOwnership ───────────────────────────────────────────────────────

    public function test_create_ownership_inserts_row(): void
    {
        $data = $this->seedGameWithTwoPlayers();

        $this->repository->createOwnership($data['game_id'], 1, $data['join_order_1'], 60);

        $this->assertDatabaseHas('game_properties', [
            'game_id'          => $data['game_id'],
            'square_index'     => 1,
            'owner_join_order' => $data['join_order_1'],
            'purchase_price'   => 60,
        ]);
    }

    public function test_create_ownership_second_call_throws_due_to_unique_constraint(): void
    {
        $data = $this->seedGameWithTwoPlayers();
        $this->repository->createOwnership($data['game_id'], 1, $data['join_order_1'], 60);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->repository->createOwnership($data['game_id'], 1, $data['join_order_2'], 60);
    }

    public function test_find_player_properties_returns_owned_properties_with_mortgage_data(): void
    {
        $data = $this->seedGameWithTwoPlayers();
        $this->repository->createOwnership($data['game_id'], 1, $data['join_order_1'], 60);
        $this->repository->createOwnership($data['game_id'], 39, $data['join_order_1'], 400);

        $properties = $this->repository->findPlayerProperties($data['game_id'], $data['join_order_1']);

        $this->assertCount(2, $properties);
        $this->assertSame([
            'square_index'   => 1,
            'name'           => 'Mediterranean Ave',
            'color'          => '#955436',
            'purchase_price' => 60,
            'mortgage_value' => 30,
            'unmortgage_cost'=> 33,
            'is_mortgaged'   => false,
        ], $properties[0]);
        $this->assertSame([
            'square_index'   => 39,
            'name'           => 'Boardwalk',
            'color'          => '#0072bb',
            'purchase_price' => 400,
            'mortgage_value' => 200,
            'unmortgage_cost'=> 220,
            'is_mortgaged'   => false,
        ], $properties[1]);
    }

    public function test_mortgage_property_marks_row_and_returns_value(): void
    {
        $data = $this->seedGameWithTwoPlayers();
        $this->repository->createOwnership($data['game_id'], 39, $data['join_order_1'], 400);

        $mortgageValue = $this->repository->mortgageProperty($data['game_id'], 39, $data['join_order_1']);

        $this->assertSame(200, $mortgageValue);
        $this->assertDatabaseHas('game_properties', [
            'game_id'          => $data['game_id'],
            'square_index'     => 39,
            'owner_join_order' => $data['join_order_1'],
            'is_mortgaged'     => 1,
        ]);
    }

    public function test_unmortgage_property_marks_row_and_returns_cost(): void
    {
        $data = $this->seedGameWithTwoPlayers();
        $this->repository->createOwnership($data['game_id'], 39, $data['join_order_1'], 400);
        $this->repository->mortgageProperty($data['game_id'], 39, $data['join_order_1']);

        $unmortgageCost = $this->repository->unmortgageProperty($data['game_id'], 39, $data['join_order_1']);

        $this->assertSame(220, $unmortgageCost);
        $this->assertDatabaseHas('game_properties', [
            'game_id'          => $data['game_id'],
            'square_index'     => 39,
            'owner_join_order' => $data['join_order_1'],
            'is_mortgaged'     => 0,
        ]);
    }

    public function test_unmortgage_property_throws_when_property_is_not_mortgaged(): void
    {
        $data = $this->seedGameWithTwoPlayers();
        $this->repository->createOwnership($data['game_id'], 39, $data['join_order_1'], 400);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This property is not mortgaged.');

        $this->repository->unmortgageProperty($data['game_id'], 39, $data['join_order_1']);
    }
}
