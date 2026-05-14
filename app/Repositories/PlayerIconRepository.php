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
     * Logic: Computes the next join_order value as MAX(join_order) + 1 for the
     * given game (defaulting to 1 when no rows exist yet) so that each player
     * receives a unique, monotonically increasing position. Inserts a row into
     * the game_player_icons pivot table with the computed join_order. For
     * authenticated creators, user_id is set and invitation_id is null.
     * For guests, user_id is null and invitation_id links back to the
     * GameInvitation row. Uses insertOrIgnore so duplicate calls for the same
     * (game_id, user_id) pair are safe. The unique constraint on
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
        $nextOrder = (int) DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->max('join_order') + 1;

        DB::table('game_player_icons')->insertOrIgnore([
            'game_id'        => $gameId,
            'user_id'        => $userId,
            'player_icon_id' => $playerIconId,
            'invitation_id'  => $invitationId,
            'join_order'     => $nextOrder,
            'capital'        => 1500,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        Log::info('Player icon assigned to game', [
            'game_id'        => $gameId,
            'user_id'        => $userId,
            'player_icon_id' => $playerIconId,
            'invitation_id'  => $invitationId,
            'join_order'     => $nextOrder,
        ]);
    }

    /**
     * Return all players that have joined a game, ordered by join_order.
     *
     * Logic: Queries game_player_icons joined with player_icons to get the icon
     * shape, and left-joined with users (for authenticated players) and
     * game_invitations (for guests). The display name is the user's name for
     * authenticated players or the invitation email for guests. The is_creator
     * flag is true for the row whose user_id matches games.user_id. Returns a
     * plain array of associative arrays ready for JSON serialisation; empty
     * arrays are used as placeholders for properties, chance_cards, and
     * community_chest_cards so the frontend shape is consistent from game
     * creation through the full game lifecycle.
     *
     * @param  int  $gameId  The ID of the game whose player list is requested.
     * @return array<int, array{
     *     user_id: int|null,
     *     invitation_id: int|null,
     *     name: string,
     *     is_creator: bool,
     *     join_order: int,
     *     capital: int,
     *     icon: array{id: int, name: string, image_url: string},
     *     properties: array,
     *     chance_cards: array,
     *     community_chest_cards: array,
     * }>
     */
    public function getPlayersForGame(int $gameId): array
    {
        $rows = DB::table('game_player_icons as gpi')
            ->join('player_icons as pi', 'pi.id', '=', 'gpi.player_icon_id')
            ->join('games as g', 'g.id', '=', 'gpi.game_id')
            ->leftJoin('users as u', 'u.id', '=', 'gpi.user_id')
            ->leftJoin('game_invitations as gi', 'gi.id', '=', 'gpi.invitation_id')
            ->where('gpi.game_id', $gameId)
            ->orderBy('gpi.join_order')
            ->select([
                'gpi.user_id',
                'gpi.invitation_id',
                'gpi.join_order',
                'gpi.capital',
                'gpi.square_index',
                'g.user_id as creator_user_id',
                'u.name as user_name',
                'gi.email as guest_email',
                'pi.id as icon_id',
                'pi.name as icon_name',
                'pi.image_url as icon_image_url',
            ])
            ->get();

        return $rows->map(function (object $row): array {
            $name = $row->user_name ?? $row->guest_email ?? 'Player';

            return [
                'user_id'               => $row->user_id,
                'invitation_id'         => $row->invitation_id,
                'name'                  => $name,
                'is_creator'            => $row->user_id !== null && (int) $row->user_id === (int) $row->creator_user_id,
                'join_order'            => (int) $row->join_order,
                'capital'               => (int) $row->capital,
                'square_index'          => (int) $row->square_index,
                'icon'                  => [
                    'id'        => $row->icon_id,
                    'name'      => $row->icon_name,
                    'image_url' => $row->icon_image_url,
                ],
                'properties'            => [],
                'chance_cards'          => [],
                'community_chest_cards' => [],
            ];
        })->values()->all();
    }

    /**
     * Return the join_order for an authenticated user within a game.
     *
     * Logic: Queries game_player_icons for the row matching both game_id and
     * user_id, selecting only join_order. Returns null when the user is not a
     * participant of the given game.
     *
     * @param  int  $gameId  The ID of the game.
     * @param  int  $userId  The authenticated user's ID.
     * @return int|null
     */
    public function getJoinOrderForUser(int $gameId, int $userId): ?int
    {
        $row = DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('user_id', $userId)
            ->select(['join_order'])
            ->first();

        return $row ? (int) $row->join_order : null;
    }

    /**
     * Return the join_order for a guest player identified by their invitation ID.
     *
     * Logic: Queries game_player_icons for the row matching both game_id and
     * invitation_id, selecting only join_order. Returns null when no matching row
     * exists (e.g. the invitation was not yet accepted or belongs to a different
     * game).
     *
     * @param  int  $gameId        The ID of the game.
     * @param  int  $invitationId  The GameInvitation primary key for the guest.
     * @return int|null
     */
    public function getJoinOrderForGuest(int $gameId, int $invitationId): ?int
    {
        $row = DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('invitation_id', $invitationId)
            ->select(['join_order'])
            ->first();

        return $row ? (int) $row->join_order : null;
    }

    /**
     * Return the current board square index for a player in a game.
     *
     * Logic: Queries game_player_icons for the row matching game_id and
     * join_order, selecting only square_index. Returns 0 (GO) when no
     * matching row exists, which is safe as a fallback.
     *
     * @param  int  $gameId     The ID of the game.
     * @param  int  $joinOrder  The join_order of the player.
     * @return int  The current square index (0–39).
     */
    public function getSquareIndexForPlayer(int $gameId, int $joinOrder): int
    {
        $row = DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('join_order', $joinOrder)
            ->select(['square_index'])
            ->first();

        return $row ? (int) $row->square_index : 0;
    }

    /**
     * Persist the new board square index for a player.
     *
     * Logic: Updates the square_index column in game_player_icons for the row
     * matching game_id and join_order, and bumps updated_at so change-tracking
     * is accurate. Logs the update with enough context to audit token movement.
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $joinOrder    The join_order of the player whose position changed.
     * @param  int  $squareIndex  The new board square index (0–39).
     * @return void
     */
    public function updateSquareIndex(int $gameId, int $joinOrder, int $squareIndex): void
    {
        DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('join_order', $joinOrder)
            ->update([
                'square_index' => $squareIndex,
                'updated_at'   => now(),
            ]);

        Log::info('Player square index updated', [
            'game_id'      => $gameId,
            'join_order'   => $joinOrder,
            'square_index' => $squareIndex,
        ]);
    }

    /**
     * Atomically adjust a player's capital by a signed delta and return the new balance.
     *
     * Logic: Issues a single UPDATE with an inline expression (capital + delta)
     * so the adjustment is race-condition-safe without a separate SELECT. After
     * updating, fetches the new capital value via a SELECT so the caller can
     * return the updated balance to the client. Logs the adjustment for
     * auditing. A negative delta deducts capital (e.g. purchase or rent
     * payment); a positive delta adds capital (e.g. receiving rent).
     *
     * @param  int  $gameId     The ID of the game.
     * @param  int  $joinOrder  The join_order of the player whose capital changes.
     * @param  int  $delta      The signed amount to add (positive) or deduct (negative).
     * @return int  The new capital balance after the adjustment.
     */
    public function adjustCapital(int $gameId, int $joinOrder, int $delta): int
    {
        DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('join_order', $joinOrder)
            ->update([
                'capital'    => DB::raw("capital + ({$delta})"),
                'updated_at' => now(),
            ]);

        $row = DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('join_order', $joinOrder)
            ->select(['capital'])
            ->first();

        $newCapital = $row ? (int) $row->capital : 0;

        Log::info('Player capital adjusted', [
            'game_id'     => $gameId,
            'join_order'  => $joinOrder,
            'delta'       => $delta,
            'new_capital' => $newCapital,
        ]);

        return $newCapital;
    }

    /**
     * Return the display name for a player identified by join_order within a game.
     *
     * Logic: Queries game_player_icons joined with users and game_invitations
     * to resolve the display name. Returns the authenticated user's name when
     * available, falling back to the guest invitation email, and ultimately to
     * the literal string 'Player' when neither is present.
     *
     * @param  int  $gameId     The ID of the game.
     * @param  int  $joinOrder  The join_order of the player.
     * @return string  The player's display name.
     */
    public function getNameByJoinOrder(int $gameId, int $joinOrder): string
    {
        $row = DB::table('game_player_icons as gpi')
            ->leftJoin('users as u', 'u.id', '=', 'gpi.user_id')
            ->leftJoin('game_invitations as gi', 'gi.id', '=', 'gpi.invitation_id')
            ->where('gpi.game_id', $gameId)
            ->where('gpi.join_order', $joinOrder)
            ->select(['u.name as user_name', 'gi.email as guest_email'])
            ->first();

        if ($row === null) {
            return 'Player';
        }

        return $row->user_name ?? $row->guest_email ?? 'Player';
    }
}
