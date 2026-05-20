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

    // ── capital ───────────────────────────────────────────────────────────────

    public function test_assign_to_game_sets_initial_capital_to_1500(): void
    {
        $game = $this->makeGame();
        $icon = PlayerIcon::first();

        $this->repository->assignToGame($game->id, $game->user_id, $icon->id);

        $row = DB::table('game_player_icons')
            ->where('game_id', $game->id)
            ->where('user_id', $game->user_id)
            ->first();

        $this->assertSame(1500, (int) $row->capital);
    }

    public function test_get_players_for_game_returns_capital_field(): void
    {
        $game = $this->makeGame();
        $icon = PlayerIcon::first();

        $this->repository->assignToGame($game->id, $game->user_id, $icon->id);

        $players = $this->repository->getPlayersForGame($game->id);

        $this->assertArrayHasKey('capital', $players[0]);
        $this->assertSame(1500, $players[0]['capital']);
    }

    public function test_get_players_for_game_returns_invitation_id_field(): void
    {
        $game = $this->makeGame();
        $icon = PlayerIcon::first();

        $this->repository->assignToGame($game->id, $game->user_id, $icon->id);

        $players = $this->repository->getPlayersForGame($game->id);

        $this->assertArrayHasKey('invitation_id', $players[0]);
        $this->assertNull($players[0]['invitation_id']);
    }

    public function test_get_players_for_game_returns_square_index_field(): void
    {
        $game = $this->makeGame();
        $icon = PlayerIcon::first();

        $this->repository->assignToGame($game->id, $game->user_id, $icon->id);

        $players = $this->repository->getPlayersForGame($game->id);

        $this->assertArrayHasKey('square_index', $players[0]);
        $this->assertSame(0, $players[0]['square_index']);
    }

    public function test_get_players_for_game_hydrates_owned_properties_from_game_properties(): void
    {
        $game = $this->makeGame();
        $icons = PlayerIcon::orderBy('sort_order')->get();
        $user2 = User::factory()->create();

        $this->repository->assignToGame($game->id, $game->user_id, $icons[0]->id);
        $this->repository->assignToGame($game->id, $user2->id, $icons[1]->id);

        DB::table('game_properties')->insert([
            'game_id'          => $game->id,
            'square_index'     => 39,
            'owner_join_order' => 2,
            'purchase_price'   => 400,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $players = $this->repository->getPlayersForGame($game->id);
        $owner = collect($players)->firstWhere('join_order', 2);

        $this->assertNotNull($owner);
        $this->assertSame([
            ['square_index' => 39, 'name' => 'Boardwalk', 'color' => '#0072bb'],
        ], $owner['properties']);
    }

    // ── getSquareIndexForPlayer ───────────────────────────────────────────────

    public function test_get_square_index_returns_zero_for_new_player(): void
    {
        $game = $this->makeGame();
        $icon = PlayerIcon::first();

        $this->repository->assignToGame($game->id, $game->user_id, $icon->id);

        $joinOrder = DB::table('game_player_icons')
            ->where('game_id', $game->id)
            ->value('join_order');

        $result = $this->repository->getSquareIndexForPlayer($game->id, (int) $joinOrder);

        $this->assertSame(0, $result);
    }

    public function test_get_square_index_returns_zero_when_player_not_found(): void
    {
        $game   = $this->makeGame();
        $result = $this->repository->getSquareIndexForPlayer($game->id, 999);

        $this->assertSame(0, $result);
    }

    public function test_get_square_index_reflects_updated_value(): void
    {
        $game = $this->makeGame();
        $icon = PlayerIcon::first();

        $this->repository->assignToGame($game->id, $game->user_id, $icon->id);

        $joinOrder = (int) DB::table('game_player_icons')
            ->where('game_id', $game->id)
            ->value('join_order');

        // Manually set square_index to a non-zero value.
        DB::table('game_player_icons')
            ->where('game_id', $game->id)
            ->where('join_order', $joinOrder)
            ->update(['square_index' => 15]);

        $result = $this->repository->getSquareIndexForPlayer($game->id, $joinOrder);

        $this->assertSame(15, $result);
    }

    // ── updateSquareIndex ─────────────────────────────────────────────────────

    public function test_update_square_index_persists_the_new_value(): void
    {
        $game = $this->makeGame();
        $icon = PlayerIcon::first();

        $this->repository->assignToGame($game->id, $game->user_id, $icon->id);

        $joinOrder = (int) DB::table('game_player_icons')
            ->where('game_id', $game->id)
            ->value('join_order');

        $this->repository->updateSquareIndex($game->id, $joinOrder, 22);

        $stored = (int) DB::table('game_player_icons')
            ->where('game_id', $game->id)
            ->where('join_order', $joinOrder)
            ->value('square_index');

        $this->assertSame(22, $stored);
    }

    public function test_update_square_index_is_reflected_by_get_square_index(): void
    {
        $game = $this->makeGame();
        $icon = PlayerIcon::first();

        $this->repository->assignToGame($game->id, $game->user_id, $icon->id);

        $joinOrder = (int) DB::table('game_player_icons')
            ->where('game_id', $game->id)
            ->value('join_order');

        $this->repository->updateSquareIndex($game->id, $joinOrder, 39);
        $result = $this->repository->getSquareIndexForPlayer($game->id, $joinOrder);

        $this->assertSame(39, $result);
    }

    public function test_update_square_index_does_not_affect_other_players(): void
    {
        $game  = $this->makeGame();
        $user2 = User::factory()->create();
        $icons = PlayerIcon::orderBy('sort_order')->get();

        $this->repository->assignToGame($game->id, $game->user_id, $icons[0]->id);
        $this->repository->assignToGame($game->id, $user2->id,      $icons[1]->id);

        $rows = DB::table('game_player_icons')
            ->where('game_id', $game->id)
            ->orderBy('join_order')
            ->select(['join_order'])
            ->get();

        $joinOrder1 = (int) $rows[0]->join_order;
        $joinOrder2 = (int) $rows[1]->join_order;

        $this->repository->updateSquareIndex($game->id, $joinOrder1, 18);

        $idx1 = $this->repository->getSquareIndexForPlayer($game->id, $joinOrder1);
        $idx2 = $this->repository->getSquareIndexForPlayer($game->id, $joinOrder2);

        $this->assertSame(18, $idx1);
        $this->assertSame(0,  $idx2);
    }

    // ── getNameByJoinOrder ────────────────────────────────────────────────────

    public function test_get_name_by_join_order_returns_user_name_for_authenticated_player(): void
    {
        $game  = $this->makeGame();
        $icons = PlayerIcon::orderBy('sort_order')->get();
        $this->repository->assignToGame($game->id, $game->user_id, $icons[0]->id);

        $joinOrder = (int) DB::table('game_player_icons')
            ->where('game_id', $game->id)
            ->value('join_order');

        $expectedName = User::find($game->user_id)->name;

        $name = $this->repository->getNameByJoinOrder($game->id, $joinOrder);

        $this->assertSame($expectedName, $name);
    }

    public function test_get_name_by_join_order_returns_guest_email_when_no_user(): void
    {
        $game  = $this->makeGame();
        $icons = PlayerIcon::orderBy('sort_order')->get();

        // Insert an invitation-based player row directly (no user_id).
        $invitationId = DB::table('game_invitations')->insertGetId([
            'game_id'    => $game->id,
            'email'      => 'guest@example.com',
            'token'      => \Illuminate\Support\Str::uuid(),
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('game_player_icons')->insert([
            'game_id'        => $game->id,
            'player_icon_id' => $icons[0]->id,
            'user_id'        => null,
            'invitation_id'  => $invitationId,
            'join_order'     => 5,
            'capital'        => 1500,
            'square_index'   => 0,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $name = $this->repository->getNameByJoinOrder($game->id, 5);

        $this->assertSame('guest@example.com', $name);
    }

    public function test_get_name_by_join_order_returns_player_fallback_when_row_not_found(): void
    {
        $game = $this->makeGame();

        $name = $this->repository->getNameByJoinOrder($game->id, 99);

        $this->assertSame('Player', $name);
    }
}
