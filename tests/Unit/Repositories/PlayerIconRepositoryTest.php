<?php

namespace Tests\Unit\Repositories;

use App\Models\Game;
use App\Models\PlayerIcon;
use App\Models\User;
use App\Repositories\PlayerIconRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlayerIconRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private PlayerIconRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new PlayerIconRepository();
        $this->seedIcons();
    }

    /**
     * Insert a minimal set of player icon fixtures.
     */
    private function seedIcons(): void
    {
        $now = now();
        PlayerIcon::insertOrIgnore([
            ['name' => 'Top Hat',     'image_url' => '/images/icons/top-hat.svg',     'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Scottie Dog', 'image_url' => '/images/icons/scottie-dog.svg', 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Racing Car',  'image_url' => '/images/icons/racing-car.svg',  'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * Create a game fixture owned by a seeded user.
     */
    private function makeGame(): Game
    {
        $user = User::factory()->create();
        return Game::factory()->create(['user_id' => $user->id]);
    }

    // ── getAll ────────────────────────────────────────────────────────────────

    public function test_get_all_returns_all_icons(): void
    {
        $icons = $this->repository->getAll();

        $this->assertCount(3, $icons);
    }

    public function test_get_all_returns_icons_ordered_by_sort_order(): void
    {
        $icons = $this->repository->getAll();

        $sortOrders = $icons->pluck('sort_order')->all();
        $this->assertSame([1, 2, 3], $sortOrders);
    }

    public function test_get_all_selects_required_columns(): void
    {
        $icon = $this->repository->getAll()->first();

        $this->assertNotNull($icon->id);
        $this->assertNotNull($icon->name);
        $this->assertNotNull($icon->image_url);
        $this->assertNotNull($icon->sort_order);
    }

    public function test_get_all_returns_empty_collection_when_no_icons(): void
    {
        // Use DELETE (DML) instead of TRUNCATE (DDL) to stay within the
        // test transaction so RefreshDatabase can roll it back correctly.
        DB::table('game_player_icons')->delete();
        DB::table('player_icons')->delete();

        $icons = $this->repository->getAll();

        $this->assertCount(0, $icons);
    }

    // ── assignToGame ─────────────────────────────────────────────────────────

    public function test_assign_to_game_inserts_pivot_row(): void
    {
        $game = $this->makeGame();
        $icon = PlayerIcon::first();

        $this->repository->assignToGame($game->id, $game->user_id, $icon->id);

        $this->assertDatabaseHas('game_player_icons', [
            'game_id'        => $game->id,
            'user_id'        => $game->user_id,
            'player_icon_id' => $icon->id,
        ]);
    }

    public function test_assign_to_game_is_idempotent_for_same_user(): void
    {
        $game = $this->makeGame();
        $icon = PlayerIcon::first();

        $this->repository->assignToGame($game->id, $game->user_id, $icon->id);
        $this->repository->assignToGame($game->id, $game->user_id, $icon->id);

        $count = DB::table('game_player_icons')
            ->where('game_id', $game->id)
            ->where('user_id', $game->user_id)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_different_users_can_assign_different_icons_to_same_game(): void
    {
        $game  = $this->makeGame();
        $user2 = User::factory()->create();
        $icons = PlayerIcon::orderBy('sort_order')->get();

        $this->repository->assignToGame($game->id, $game->user_id, $icons[0]->id);
        $this->repository->assignToGame($game->id, $user2->id, $icons[1]->id);

        $this->assertSame(2, DB::table('game_player_icons')->where('game_id', $game->id)->count());
    }

    public function test_same_icon_cannot_be_assigned_to_two_users_in_same_game(): void
    {
        $game  = $this->makeGame();
        $user2 = User::factory()->create();
        $icon  = PlayerIcon::first();

        $this->repository->assignToGame($game->id, $game->user_id, $icon->id);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Force insert (bypass insertOrIgnore) to trigger the unique constraint
        DB::table('game_player_icons')->insert([
            'game_id'        => $game->id,
            'user_id'        => $user2->id,
            'player_icon_id' => $icon->id,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }
}
