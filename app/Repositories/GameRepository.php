<?php

namespace App\Repositories;

use App\Enums\GameStatus;
use App\Models\Game;
use Illuminate\Support\Facades\Log;

class GameRepository
{
    /**
     * Count the number of games created by a given user.
     *
     * Logic: Queries the games table filtering by user_id and returns the total
     * count, used for auto-naming sequential games per user.
     *
     * @param  int  $userId  The ID of the user whose games are counted.
     * @return int
     */
    public function countByUser(int $userId): int
    {
        return Game::where('user_id', $userId)->count();
    }

    /**
     * Find a single game by its primary key.
     *
     * Logic: Queries the games table for a row matching the given ID and returns
     * the hydrated Game model, or null if no row exists. Only the columns needed
     * for ownership checks and downstream logic are selected.
     *
     * @param  int  $gameId  The primary key of the game to retrieve.
     * @return Game|null
     */
    public function findById(int $gameId): ?Game
    {
        return Game::select(['id', 'name', 'user_id', 'status', 'max_players'])->find($gameId);
    }

    /**
     * Persist a new game record linked to the given user.
     *
     * Logic: Creates a Game model row with the provided name, user_id, max_players,
     * and the default InProgress status, then refreshes the instance from the database
     * so all DB-defaulted columns (e.g. status, timestamps) are present before
     * the caller serialises the model to JSON.
     *
     * @param  int     $userId      The ID of the user who owns the game.
     * @param  string  $name        The display name for the game.
     * @param  int     $maxPlayers  The maximum number of players allowed (2–8).
     * @return Game
     */
    public function create(int $userId, string $name, int $maxPlayers): Game
    {
        $game = Game::create([
            'user_id'     => $userId,
            'name'        => $name,
            'status'      => GameStatus::InProgress,
            'max_players' => $maxPlayers,
        ]);

        return $game->refresh();
    }
}
