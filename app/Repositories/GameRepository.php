<?php

namespace App\Repositories;

use App\Enums\GameStatus;
use App\Models\Game;
use Illuminate\Support\Facades\DB;
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
        return Game::select(['id', 'name', 'user_id', 'status', 'max_players', 'current_turn_join_order'])->find($gameId);
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

    /**
     * Return all join_order values for players in a game, sorted ascending.
     *
     * Logic: Queries game_player_icons for the given game_id, selects only the
     * join_order column, sorts ascending, and casts each value to int. Used to
     * compute the cyclic next player when advancing the turn.
     *
     * @param  int  $gameId  The ID of the game.
     * @return array<int>
     */
    public function getPlayerJoinOrders(int $gameId): array
    {
        return DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->orderBy('join_order')
            ->pluck('join_order')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Atomically advance the turn to the next player using an optimistic update.
     *
     * Logic: Issues a single UPDATE … WHERE id = ? AND current_turn_join_order = ?
     * so that only the row whose turn it still is gets updated. Returns true when
     * exactly one row was affected (i.e. the update succeeded) and false when the
     * turn had already been advanced by a concurrent request. The WHERE guard
     * prevents double-advancing without needing an explicit row lock.
     *
     * @param  int  $gameId                   The ID of the game.
     * @param  int  $expectedCurrentJoinOrder  The join_order that must still be current.
     * @param  int  $nextJoinOrder             The join_order to advance to.
     * @return bool  True when the update succeeded; false when it was a no-op.
     */
    public function advanceTurn(int $gameId, int $expectedCurrentJoinOrder, int $nextJoinOrder): bool
    {
        $affected = DB::table('games')
            ->where('id', $gameId)
            ->where('current_turn_join_order', $expectedCurrentJoinOrder)
            ->update([
                'current_turn_join_order' => $nextJoinOrder,
                'updated_at'              => now(),
            ]);

        Log::info('Turn advanced', [
            'game_id'       => $gameId,
            'from'          => $expectedCurrentJoinOrder,
            'to'            => $nextJoinOrder,
            'rows_affected' => $affected,
        ]);

        return $affected > 0;
    }
}
