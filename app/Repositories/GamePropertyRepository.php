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
                'gp.houses_count',
                'gp.has_hotel',
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
            'houses_count'     => isset($row->houses_count) ? (int) $row->houses_count : 0,
            'has_hotel'        => isset($row->has_hotel) ? (bool) $row->has_hotel : false,
            'owner_name'       => $row->user_name ?? $row->guest_email ?? 'Player',
        ];
    }

    /**
     * Return building data for a list of squares in a game.
     *
     * @param  int    $gameId
     * @param  int[]  $squareIndices
     * @return array<int, array{square_index:int, houses_count:int, has_hotel:bool}>
     */
    public function getBuildingsForSquares(int $gameId, array $squareIndices): array
    {
        $rows = DB::table('game_properties')
            ->where('game_id', $gameId)
            ->whereIn('square_index', $squareIndices)
            ->select(['square_index', 'houses_count', 'has_hotel'])
            ->get();

        $result = [];

        foreach ($rows as $r) {
            $result[(int) $r->square_index] = [
                'square_index' => (int) $r->square_index,
                'houses_count' => isset($r->houses_count) ? (int) $r->houses_count : 0,
                'has_hotel'    => isset($r->has_hotel) ? (bool) $r->has_hotel : false,
            ];
        }

        return $result;
    }

    /**
     * Persist building counts for a single square.
     *
     * @param  int  $gameId
     * @param  int  $squareIndex
     * @param  int  $housesCount
     * @param  bool $hasHotel
     * @return void
     */
    public function setBuildingsForSquare(int $gameId, int $squareIndex, int $housesCount, bool $hasHotel): void
    {
        DB::table('game_properties')
            ->where('game_id', $gameId)
            ->where('square_index', $squareIndex)
            ->update([
                'houses_count' => $housesCount,
                'has_hotel'    => $hasHotel,
                'updated_at'   => now(),
            ]);

        Log::info('Updated buildings on property', [
            'game_id'      => $gameId,
            'square_index' => $squareIndex,
            'houses_count' => $housesCount,
            'has_hotel'    => $hasHotel,
        ]);
    }

    /**
     * Return the total number of houses currently placed in a game.
     *
     * @param int $gameId
     * @return int
     */
    public function countTotalHouses(int $gameId): int
    {
        $row = DB::table('game_properties')
            ->where('game_id', $gameId)
            ->selectRaw('SUM(houses_count) as total')
            ->first();

        return $row ? (int) $row->total : 0;
    }

    /**
     * Return the total number of hotels currently placed in a game.
     *
     * @param int $gameId
     * @return int
     */
    public function countTotalHotels(int $gameId): int
    {
        $row = DB::table('game_properties')
            ->where('game_id', $gameId)
            ->selectRaw('SUM(CASE WHEN has_hotel THEN 1 ELSE 0 END) as total')
            ->first();

        return $row ? (int) $row->total : 0;
    }

    /**
     * Return all properties owned by a player in a game.
     *
     * Logic: Reads the property's purchase price and mortgage flag from the
     * database and maps each square index back to its display name, color, and
     * mortgage value so the frontend can render mortgage options without
     * duplicating board metadata.
     *
     * @param  int  $gameId      The ID of the game.
     * @param  int  $joinOrder   The join_order of the owning player.
    * @return array<int, array{square_index: int, name: string, color: string|null, purchase_price: int, mortgage_value: int, unmortgage_cost: int, is_mortgaged: bool}>
     */
    public function findPlayerProperties(int $gameId, int $joinOrder): array
    {
        return DB::table('game_properties')
            ->where('game_id', $gameId)
            ->where('owner_join_order', $joinOrder)
            ->orderBy('square_index')
            ->select(['square_index', 'purchase_price', 'is_mortgaged', 'houses_count', 'has_hotel'])
            ->get()
            ->map(function (object $row): array {
                $squareIndex   = (int) $row->square_index;
                $purchasePrice = (int) $row->purchase_price;

                return [
                    'square_index'   => $squareIndex,
                    'name'           => $this->squareNameByIndex($squareIndex),
                    'color'          => $this->squareColorByIndex($squareIndex),
                    'purchase_price' => $purchasePrice,
                    'mortgage_value' => intdiv($purchasePrice, 2),
                    'unmortgage_cost'=> $this->calculateUnmortgageCost(intdiv($purchasePrice, 2)),
                    'is_mortgaged'   => (bool) $row->is_mortgaged,
                    'houses_count'   => isset($row->houses_count) ? (int) $row->houses_count : 0,
                    'has_hotel'      => isset($row->has_hotel) ? (bool) $row->has_hotel : false,
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
     * Resolve the unmortgage cost for an owned mortgaged property.
     *
     * Logic: Validates that the property exists, is owned by the requesting
     * player, and is currently mortgaged; then returns the mortgage value plus
     * 10 percent.
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $squareIndex  The board square index to unmortgage.
     * @param  int  $joinOrder    The join_order of the requesting player.
     * @return int
     */
    public function getUnmortgageCost(int $gameId, int $squareIndex, int $joinOrder): int
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

        if (!(bool) $row->is_mortgaged) {
            throw new \InvalidArgumentException('This property is not mortgaged.');
        }

        $mortgageValue = intdiv((int) $row->purchase_price, 2);

        return $this->calculateUnmortgageCost($mortgageValue);
    }

    /**
     * Unmortgage an owned property and return the required payment.
     *
     * Logic: Validates ownership and mortgaged state, marks the property as
     * unmortgaged, logs the operation, and returns the required unmortgage cost
     * so callers can deduct player capital.
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $squareIndex  The board square index to unmortgage.
     * @param  int  $joinOrder    The join_order of the requesting player.
     * @return int
     */
    public function unmortgageProperty(int $gameId, int $squareIndex, int $joinOrder): int
    {
        $unmortgageCost = $this->getUnmortgageCost($gameId, $squareIndex, $joinOrder);

        DB::table('game_properties')
            ->where('game_id', $gameId)
            ->where('square_index', $squareIndex)
            ->update([
                'is_mortgaged' => false,
                'updated_at'   => now(),
            ]);

        Log::info('Property unmortgaged', [
            'game_id'          => $gameId,
            'square_index'     => $squareIndex,
            'owner_join_order' => $joinOrder,
            'unmortgage_cost'  => $unmortgageCost,
        ]);

        return $unmortgageCost;
    }

    /**
     * Calculate the unmortgage cost from a mortgage value.
     *
     * Logic: Applies a 10 percent fee to the mortgage value and rounds up to
     * ensure the repayment is never undercharged.
     *
     * @param  int  $mortgageValue  The base mortgage value.
     * @return int
     */
    private function calculateUnmortgageCost(int $mortgageValue): int
    {
        return intdiv(($mortgageValue * 110) + 99, 100);
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

    /**
     * Return the hex color code for a board square, or null for non-colour-group squares.
     *
     * Logic: Maps each purchasable colour-group square index to its canonical
     * hex colour string. Railroads and utilities are not members of a colour
     * group, so they return null.
     *
     * @param  int  $squareIndex  The board square index (0-39).
     * @return string|null
     */
    private function squareColorByIndex(int $squareIndex): ?string
    {
        return match ($squareIndex) {
            1, 3         => '#955436',
            6, 8, 9      => '#aae0fa',
            11, 13, 14   => '#d93a96',
            16, 18, 19   => '#f7941d',
            21, 23, 24   => '#ed1b24',
            26, 27, 29   => '#fef200',
            31, 32, 34   => '#1fb25a',
            37, 39       => '#0072bb',
            default      => null,
        };
    }
}
