<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GamePropertyRepository
{
    /**
     * Find the current owner of a board square in a game.
     *
     * Logic: Joins game_properties with game_player_icons to resolve the
     * owner's display name (user's name for authenticated players, invitation
     * email for guests). Returns an associative array with owner_join_order and
     * owner_name when ownership exists, or null when the square is unowned.
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $squareIndex  The board square index (0–39).
     * @return array{owner_join_order: int, owner_name: string}|null
     */
    public function findOwnerBySquare(int $gameId, int $squareIndex): ?array
    {
        $row = DB::table('game_properties as gp')
            ->join('game_player_icons as gpi', function ($join) {
                $join->on('gpi.game_id', '=', 'gp.game_id')
                    ->on('gpi.join_order', '=', 'gp.owner_join_order');
            })
            ->leftJoin('users as u', 'u.id', '=', 'gpi.user_id')
            ->leftJoin('game_invitations as gi', 'gi.id', '=', 'gpi.invitation_id')
            ->where('gp.game_id', $gameId)
            ->where('gp.square_index', $squareIndex)
            ->select([
                'gp.owner_join_order',
                'u.name as user_name',
                'gi.email as guest_email',
            ])
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'owner_join_order' => (int) $row->owner_join_order,
            'owner_name'       => $row->user_name ?? $row->guest_email ?? 'Player',
        ];
    }

    /**
     * Record a player as the new owner of a board square.
     *
     * Logic: Inserts a row into game_properties for the given game, square, and
     * owning player. The unique constraint on (game_id, square_index) prevents
     * double-purchases at the database level. Logs the ownership creation.
     *
     * @param  int  $gameId         The ID of the game.
     * @param  int  $squareIndex    The board square index (0–39).
     * @param  int  $ownerJoinOrder The join_order of the purchasing player.
     * @param  int  $purchasePrice  The price paid for the property.
     * @return void
     */
    public function createOwnership(
        int $gameId,
        int $squareIndex,
        int $ownerJoinOrder,
        int $purchasePrice
    ): void {
        DB::table('game_properties')->insert([
            'game_id'          => $gameId,
            'square_index'     => $squareIndex,
            'owner_join_order' => $ownerJoinOrder,
            'purchase_price'   => $purchasePrice,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        Log::info('Property purchased', [
            'game_id'          => $gameId,
            'square_index'     => $squareIndex,
            'owner_join_order' => $ownerJoinOrder,
            'purchase_price'   => $purchasePrice,
        ]);
    }
}
