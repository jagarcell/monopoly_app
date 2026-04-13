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
     * Return player icons not yet assigned in a given game.
     *
     * Logic: Selects only columns required by the icon-picker UI and excludes
     * any icon whose ID already appears in game_player_icons for the given
     * game_id, so the list reflects real-time availability. Ordered by
     * sort_order for consistent display.
     *
     * @param  int  $gameId  The ID of the game whose taken icons are excluded.
     * @return Collection<int, PlayerIcon>
     */
    public function getAvailableForGame(int $gameId): Collection
    {
        $taken = DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->pluck('player_icon_id');

        return PlayerIcon::select(['id', 'name', 'image_url', 'sort_order'])
            ->whereNotIn('id', $taken)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Assign a player icon to a user (or guest via invitation) within a game.
     *
     * Logic: Inserts a row into the game_player_icons pivot table. For
     * authenticated creators, user_id is set and invitation_id is null.
     * For guests invited via email, user_id is null and invitation_id links
     * back to the GameInvitation row. Uses insertOrIgnore so duplicate calls
     * for the same (game_id, user_id) pair are safe. The unique constraint on
     * (game_id, player_icon_id) prevents two players from sharing the same
     * icon — that conflict surfaces as a QueryException for the caller to handle.
     *
     * @param  int       $gameId        The ID of the game.
     * @param  int|null  $userId        The authenticated user's ID, or null for guests.
     * @param  int       $playerIconId  The ID of the chosen PlayerIcon.
     * @param  int|null  $invitationId  The GameInvitation ID for guest players, or null.
     * @return void
     */
    public function assignToGame(int $gameId, ?int $userId, int $playerIconId, ?int $invitationId = null): void
    {
        DB::table('game_player_icons')->insertOrIgnore([
            'game_id'        => $gameId,
            'user_id'        => $userId,
            'player_icon_id' => $playerIconId,
            'invitation_id'  => $invitationId,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        Log::info('Player icon assigned to game', [
            'game_id'        => $gameId,
            'user_id'        => $userId,
            'player_icon_id' => $playerIconId,
            'invitation_id'  => $invitationId,
        ]);
    }
}
