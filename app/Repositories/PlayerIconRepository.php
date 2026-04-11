<?php

namespace App\Repositories;

use App\Models\PlayerIcon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlayerIconRepository
{
    /**
     * Return all player icons ordered by sort_order ascending.
     *
     * Logic: Selects only the columns needed for the icon picker UI and orders
     * the result by sort_order so the display sequence matches the catalogue
     * definition without requiring the caller to sort.
     *
     * @return Collection<int, PlayerIcon>
     */
    public function getAll(): Collection
    {
        return PlayerIcon::select(['id', 'name', 'image_url', 'sort_order'])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Assign a player icon to a user within a game.
     *
     * Logic: Inserts a row into the game_player_icons pivot table linking the
     * game, user, and chosen icon. Uses insertOrIgnore so calling this more
     * than once for the same (game_id, user_id) pair is safe (no duplicate
     * exception). The unique constraint on (game_id, player_icon_id) prevents
     * two players from sharing the same icon in the same game — that conflict
     * bubbles up as a QueryException for the caller to handle.
     *
     * @param  int  $gameId        The ID of the game.
     * @param  int  $userId        The ID of the user selecting the icon.
     * @param  int  $playerIconId  The ID of the chosen PlayerIcon.
     * @return void
     */
    public function assignToGame(int $gameId, int $userId, int $playerIconId): void
    {
        DB::table('game_player_icons')->insertOrIgnore([
            'game_id'        => $gameId,
            'user_id'        => $userId,
            'player_icon_id' => $playerIconId,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        Log::info('Player icon assigned to game', [
            'game_id'        => $gameId,
            'user_id'        => $userId,
            'player_icon_id' => $playerIconId,
        ]);
    }
}
