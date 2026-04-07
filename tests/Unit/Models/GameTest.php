<?php

namespace Tests\Unit\Models;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure the games table name is explicitly set on the model.
     *
     * Logic: Instantiates a Game model and asserts the table property equals 'games'.
     *
     * @return void
     */
    public function test_table_name_is_games(): void
    {
        $game = new Game();
        $this->assertSame('games', $game->getTable());
    }

    /**
     * Ensure the primary key is explicitly set to 'id'.
     *
     * Logic: Instantiates a Game model and asserts the primary key property equals 'id'.
     *
     * @return void
     */
    public function test_primary_key_is_id(): void
    {
        $game = new Game();
        $this->assertSame('id', $game->getKeyName());
    }

    /**
     * Ensure all expected columns are in the fillable array.
     *
     * Logic: Checks that 'name', 'user_id', and 'status' are each present in $fillable.
     *
     * @return void
     */
    public function test_fillable_contains_expected_columns(): void
    {
        $game = new Game();
        foreach (['name', 'user_id', 'status'] as $column) {
            $this->assertContains($column, $game->getFillable());
        }
    }

    /**
     * Ensure status is cast to the GameStatus enum.
     *
     * Logic: Creates a persisted Game and reads back the status attribute,
     * asserting it is an instance of GameStatus.
     *
     * @return void
     */
    public function test_status_is_cast_to_game_status_enum(): void
    {
        $user = User::factory()->create();

        $game = Game::create([
            'name'    => 'Test Game',
            'user_id' => $user->id,
            'status'  => GameStatus::InProgress,
        ]);

        $this->assertInstanceOf(GameStatus::class, $game->fresh()->status);
        $this->assertSame(GameStatus::InProgress, $game->fresh()->status);
    }

    /**
     * Ensure a game's default status is 'in_progress'.
     *
     * Logic: Creates a game without specifying status and confirms the DB default is applied.
     *
     * @return void
     */
    public function test_default_status_is_in_progress(): void
    {
        $user = User::factory()->create();

        $game = Game::create([
            'name'    => 'Default Status Game',
            'user_id' => $user->id,
        ]);

        $this->assertSame(GameStatus::InProgress, $game->fresh()->status);
    }

    /**
     * Ensure the 'finished' status enum value persists correctly.
     *
     * Logic: Creates a game with Finished status and reads it back.
     *
     * @return void
     */
    public function test_status_finished_persists_correctly(): void
    {
        $user = User::factory()->create();

        $game = Game::create([
            'name'    => 'Ended Game',
            'user_id' => $user->id,
            'status'  => GameStatus::Finished,
        ]);

        $this->assertSame(GameStatus::Finished, $game->fresh()->status);
    }

    /**
     * Ensure the user() relationship resolves to the correct User model.
     *
     * Logic: Creates a user and a game belonging to that user, then loads
     * the relationship and asserts the IDs match.
     *
     * @return void
     */
    public function test_belongs_to_user(): void
    {
        $user = User::factory()->create();

        $game = Game::create([
            'name'    => 'Relationship Game',
            'user_id' => $user->id,
            'status'  => GameStatus::InProgress,
        ]);

        $this->assertInstanceOf(User::class, $game->user);
        $this->assertSame($user->id, $game->user->id);
    }
}
