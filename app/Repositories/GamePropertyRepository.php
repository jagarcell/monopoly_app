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
                'gp.is_mortgaged',
                'u.name as user_name',
                'gi.email as guest_email',
            ])
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'owner_join_order' => (int) $row->owner_join_order,
            'is_mortgaged'     => (bool) $row->is_mortgaged,
            'owner_name'       => $row->user_name ?? $row->guest_email ?? 'Player',
        ];
    }

    /**
     * Return all properties owned by a player in a game.
     *
     * Logic: Reads the property's purchase price and mortgage flag from the
     * database and maps each square index back to its display name and mortgage
     * value so the frontend can render mortgage options without duplicating
     * board metadata.
     *
     * @param  int  $gameId      The ID of the game.
     * @param  int  $joinOrder   The join_order of the owning player.
     * @return array<int, array{square_index: int, name: string, purchase_price: int, mortgage_value: int, is_mortgaged: bool}>
     */
    public function findPlayerProperties(int $gameId, int $joinOrder): array
    {
        return DB::table('game_properties')
            ->where('game_id', $gameId)
            ->where('owner_join_order', $joinOrder)
            ->orderBy('square_index')
            ->select(['square_index', 'purchase_price', 'is_mortgaged'])
            ->get()
            ->map(function (object $row): array {
                $squareIndex   = (int) $row->square_index;
                $purchasePrice = (int) $row->purchase_price;

                return [
                    'square_index'   => $squareIndex,
                    'name'           => $this->squareNameByIndex($squareIndex),
                    'purchase_price' => $purchasePrice,
                    'mortgage_value' => intdiv($purchasePrice, 2),
                    'is_mortgaged'   => (bool) $row->is_mortgaged,
                ];
            })
            ->all();
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

    /**
     * Mortgage an owned property and return the raised capital.
     *
     * Logic: Validates that the row exists, belongs to the caller, and is not
     * already mortgaged. If valid, marks the property as mortgaged and returns
     * half of the purchase price rounded down.
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $squareIndex  The board square index to mortgage.
     * @param  int  $joinOrder    The join_order of the requesting player.
     * @return int
     */
    public function mortgageProperty(int $gameId, int $squareIndex, int $joinOrder): int
    {
        $row = DB::table('game_properties')
            ->where('game_id', $gameId)
            ->where('square_index', $squareIndex)
            ->select(['owner_join_order', 'purchase_price', 'is_mortgaged'])
            ->first();

        if ($row === null) {
            throw new \InvalidArgumentException('This property is not owned yet.');
        }

        if ((int) $row->owner_join_order !== $joinOrder) {
            throw new \InvalidArgumentException('You do not own this property.');
        }

        if ((bool) $row->is_mortgaged) {
            throw new \InvalidArgumentException('This property is already mortgaged.');
        }

        $mortgageValue = intdiv((int) $row->purchase_price, 2);

        DB::table('game_properties')
            ->where('game_id', $gameId)
            ->where('square_index', $squareIndex)
            ->update([
                'is_mortgaged' => true,
                'updated_at'   => now(),
            ]);

        Log::info('Property mortgaged', [
            'game_id'          => $gameId,
            'square_index'     => $squareIndex,
            'owner_join_order' => $joinOrder,
            'mortgage_value'   => $mortgageValue,
        ]);

        return $mortgageValue;
    }

    /**
     * Resolve the display name for a purchasable square index.
     *
     * Logic: Reuses the Monopoly board mapping so the mortgage list shows the
     * same property labels as the board.
     *
     * @param  int  $squareIndex  The board square index (0-39).
     * @return string
     */
    private function squareNameByIndex(int $squareIndex): string
    {
        return match ($squareIndex) {
            1  => 'Mediterranean Ave',
            3  => 'Baltic Ave',
            5  => 'Reading Railroad',
            6  => 'Oriental Ave',
            8  => 'Vermont Ave',
            9  => 'Connecticut Ave',
            11 => 'St. Charles Place',
            12 => 'Electric Company',
            13 => 'States Ave',
            14 => 'Virginia Ave',
            15 => 'Pennsylvania Railroad',
            16 => 'St. James Place',
            18 => 'Tennessee Ave',
            19 => 'New York Ave',
            21 => 'Kentucky Ave',
            23 => 'Indiana Ave',
            24 => 'Illinois Ave',
            25 => 'B&O Railroad',
            26 => 'Atlantic Ave',
            27 => 'Ventnor Ave',
            28 => 'Water Works',
            29 => 'Marvin Gardens',
            31 => 'Pacific Ave',
            32 => 'North Carolina Ave',
            34 => 'Pennsylvania Ave',
            35 => 'Short Line Railroad',
            37 => 'Park Place',
            39 => 'Boardwalk',
            default => "Property {$squareIndex}",
        };
    }
}
