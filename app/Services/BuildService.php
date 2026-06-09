<?php

namespace App\Services;

use App\Repositories\GamePropertyRepository;
use App\Repositories\PlayerIconRepository;
use App\Repositories\GameRepository;
use App\Repositories\GamePendingBuildRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BuildService
{
    public function __construct(
        private readonly GamePropertyRepository $propertyRepository,
        private readonly PlayerIconRepository   $playerIconRepository,
        private readonly GameRepository         $gameRepository,
        private readonly GamePendingBuildRepository $pendingBuildRepository,
    ) {}

    /**
     * Attempt to build a single house on the given property, enforcing standard
     * Monopoly rules: full colour-set ownership, even building, mortgage block,
     * max 4 houses before hotel.
     *
     * Returns an array with `success` bool and `message` or `result` payload.
     *
     * @param  int  $gameId
     * @param  int  $userId
     * @param  int  $squareIndex
     * @param  int  $pricePerHouse  Optional price per house; if provided the player's capital will be adjusted.
     * @return array<string, mixed>
     */
    public function buildHouse(int $gameId, int $userId, int $squareIndex, int $pricePerHouse = 0): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);
        if ($joinOrder === null) {
            $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $userId);
        }

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $game = $this->gameRepository->findById($gameId);
        if ($game === null) {
            throw new InvalidArgumentException('Game not found.');
        }

        // Determine colour group for square
        $group = $this->colourGroupForSquare($squareIndex);
        if ($group === null) {
            throw new InvalidArgumentException('Cannot build on this square (not a colour-group property).');
        }

        // Gather group squares
        $groupSquares = $this->squaresForGroup($group);

        // Verify ownership and mortgage state
        $owned = [];
        foreach ($groupSquares as $sq) {
            $owner = $this->propertyRepository->findOwnerBySquare($gameId, $sq);
            if ($owner === null || $owner['owner_join_order'] !== $joinOrder) {
                throw new InvalidArgumentException('You must own the entire colour group to build.');
            }
            if ($owner['is_mortgaged']) {
                throw new InvalidArgumentException('You cannot build while a property in the set is mortgaged.');
            }
            $owned[$sq] = $owner;
        }

        $buildings = $this->propertyRepository->getBuildingsForSquares($gameId, $groupSquares);

        // Include any pending builds for this game so validation considers
        // queued changes made earlier in the same turn by the same player.
        $pending = $this->pendingBuildRepository->getPendingBuildsForGame($gameId);
        $pendingBySquare = [];
        foreach ($pending as $p) {
            if ((int) $p['owner_join_order'] !== $joinOrder) continue;
            $sq = (int) $p['square_index'];
            if (!isset($pendingBySquare[$sq])) {
                $pendingBySquare[$sq] = ['houses' => 0, 'hotel' => false];
            }
            $pendingBySquare[$sq]['houses'] += (int) $p['houses_delta'];
            if ((bool) $p['has_hotel']) {
                $pendingBySquare[$sq]['hotel'] = true;
            }
        }

        // Compute current houses array and ensure even building after adding one to target
        $current = [];
        foreach ($groupSquares as $sq) {
            $h = $buildings[$sq]['houses_count'] ?? 0;
            $h += $pendingBySquare[$sq]['houses'] ?? 0;
            $current[$sq] = $h;
            $hasHotelNow = ($buildings[$sq]['has_hotel'] ?? false) || ($pendingBySquare[$sq]['hotel'] ?? false);
            if ($hasHotelNow === true) {
                throw new InvalidArgumentException('Cannot add a house to a property that already has a hotel.');
            }
        }

        if (!array_key_exists($squareIndex, $current)) {
            throw new InvalidArgumentException('Square is not part of the colour group.');
        }

        $current[$squareIndex]++;

        // Validate max 4 houses
        foreach ($current as $c) {
            if ($c > 4) {
                throw new InvalidArgumentException('A property cannot have more than 4 houses (upgrade to hotel instead).');
            }
        }

        // Validate even-building: difference between any two <= 1
        $min = min($current);
        $max = max($current);
        if ($max - $min > 1) {
            throw new InvalidArgumentException('Houses must be built evenly across the colour group.');
        }

        // Verify bank availability (account for pending builds)
        $usedHouses = $this->propertyRepository->countTotalHouses($gameId);
        $pendingHouses = $this->pendingBuildRepository->countPendingHouses($gameId);
        $availableHouses = 32 - ($usedHouses + $pendingHouses);
        if ($availableHouses <= 0) {
            throw new InvalidArgumentException('No houses available in the bank.');
        }

        // Prepare new house delta (queued)
        $ownerJoin = $owned[$squareIndex]['owner_join_order'];

        // If charging is requested, verify capital and perform atomic update of capital
        $newCapital = null;
        if ($pricePerHouse > 0) {
            $players = $this->playerIconRepository->getPlayersForGame($gameId);
            $player = collect($players)->firstWhere('join_order', $ownerJoin);
            $currentCapital = $player['capital'] ?? 0;

            if ($currentCapital < $pricePerHouse) {
                throw new InvalidArgumentException(sprintf('Insufficient capital to build a house: need $%d, have $%d.', $pricePerHouse, $currentCapital));
            }

            DB::transaction(function () use ($gameId, $squareIndex, $ownerJoin, $pricePerHouse, &$newCapital) {
                // Deduct capital first
                $newCapital = $this->playerIconRepository->adjustCapital($gameId, $ownerJoin, -$pricePerHouse);

                // Queue pending build (do not persist to property immediately)
                $this->pendingBuildRepository->addPendingBuild($gameId, $ownerJoin, $squareIndex, 1, false);
            });
        } else {
            // No charge requested: just queue pending build
            $this->pendingBuildRepository->addPendingBuild($gameId, $ownerJoin, $squareIndex, 1, false);
        }

        try {
            // Log queued pending count for diagnostics. Calculate pending houses
            // for this square (existing pending + the house we just queued).
            $pendingCount = ($pendingBySquare[$squareIndex]['houses'] ?? 0) + 1;
            Log::info('Queued house build', [
                'game_id' => $gameId,
                'square' => $squareIndex,
                'owner' => $joinOrder,
                'pending_houses' => $pendingCount,
            ]);
        } catch (\Throwable $e) {
            // Logging may be unavailable in lightweight unit tests; ignore.
        }

        return ['success' => true, 'pending_houses' => 1, 'new_capital' => $newCapital];
    }

    /**
     * Upgrade a property to a hotel. Requires every property in the group to
     * currently have 4 houses. Converts the 4 houses into a hotel and updates
     * the database. Does not implement auctioning for building shortages.
     *
     * @param  int  $gameId
     * @param  int  $userId
     * @param  int  $squareIndex
     * @param  int  $pricePerHotel
     * @return array<string,mixed>
     */
    public function buildHotel(int $gameId, int $userId, int $squareIndex, int $pricePerHotel = 0): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);
        if ($joinOrder === null) {
            $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $userId);
        }

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $group = $this->colourGroupForSquare($squareIndex);
        if ($group === null) {
            throw new InvalidArgumentException('Cannot build on this square (not a colour-group property).');
        }

        $groupSquares = $this->squaresForGroup($group);

        // Verify ownership and mortgage state
        foreach ($groupSquares as $sq) {
            $owner = $this->propertyRepository->findOwnerBySquare($gameId, $sq);
            if ($owner === null || $owner['owner_join_order'] !== $joinOrder) {
                throw new InvalidArgumentException('You must own the entire colour group to build.');
            }
            if ($owner['is_mortgaged']) {
                throw new InvalidArgumentException('You cannot build while a property in the set is mortgaged.');
            }
        }

        $buildings = $this->propertyRepository->getBuildingsForSquares($gameId, $groupSquares);

        // Include any pending builds for this game so validation considers
        // queued changes made earlier in the same turn by the same player.
        $pending = $this->pendingBuildRepository->getPendingBuildsForGame($gameId);
        $pendingBySquare = [];
        foreach ($pending as $p) {
            if ((int) $p['owner_join_order'] !== $joinOrder) continue;
            $sq = (int) $p['square_index'];
            if (!isset($pendingBySquare[$sq])) {
                $pendingBySquare[$sq] = ['houses' => 0, 'hotel' => false];
            }
            $pendingBySquare[$sq]['houses'] += (int) $p['houses_delta'];
            if ((bool) $p['has_hotel']) {
                $pendingBySquare[$sq]['hotel'] = true;
            }
        }

        // Require 4 houses on every property, or allow if the property already has a hotel
        foreach ($groupSquares as $sq) {
            $hasHotel = ($buildings[$sq]['has_hotel'] ?? false) || ($pendingBySquare[$sq]['hotel'] ?? false);
            $houses = ($buildings[$sq]['houses_count'] ?? 0) + ($pendingBySquare[$sq]['houses'] ?? 0);
            if (!$hasHotel && $houses < 4) {
                throw new InvalidArgumentException('All properties in the set must have 4 houses (or already have a hotel) before building a hotel.');
            }
        }

        // Prevent upgrading a property that already has a hotel
        if (($buildings[$squareIndex]['has_hotel'] ?? false) === true) {
            throw new InvalidArgumentException('This property already has a hotel.');
        }

        // Check bank hotels availability (include pending)
        $usedHotels = $this->propertyRepository->countTotalHotels($gameId);
        $pendingHotels = $this->pendingBuildRepository->countPendingHotels($gameId);
        $availableHotels = 12 - ($usedHotels + $pendingHotels);
        if ($availableHotels <= 0) {
            throw new InvalidArgumentException('No hotels available in the bank.');
        }

        // Queue hotel upgrade (do not persist to property immediately)
        $newCapital = null;
        if ($pricePerHotel > 0) {
            $players = $this->playerIconRepository->getPlayersForGame($gameId);
            $player = collect($players)->firstWhere('join_order', $joinOrder);
            $currentCapital = $player['capital'] ?? 0;

            if ($currentCapital < $pricePerHotel) {
                throw new InvalidArgumentException(sprintf('Insufficient capital to build a hotel: need $%d, have $%d.', $pricePerHotel, $currentCapital));
            }

            DB::transaction(function () use ($gameId, $squareIndex, $joinOrder, $pricePerHotel, &$newCapital) {
                $newCapital = $this->playerIconRepository->adjustCapital($gameId, $joinOrder, -$pricePerHotel);
                $this->pendingBuildRepository->addPendingBuild($gameId, $joinOrder, $squareIndex, 0, true);
            });
        } else {
            $this->pendingBuildRepository->addPendingBuild($gameId, $joinOrder, $squareIndex, 0, true);
        }

        try {
            Log::info('Built hotel', ['game_id' => $gameId, 'square' => $squareIndex, 'owner' => $joinOrder]);
        } catch (\Throwable $e) {
            // Logging may be unavailable in lightweight unit tests; ignore.
        }

        return ['success' => true, 'pending_hotel' => true, 'new_capital' => $newCapital];
    }

    /**
     * Sum of houses currently present in a game.
     */
    private function totalHousesInGame(int $gameId): int
    {
        return $this->propertyRepository->countTotalHouses($gameId);
    }

    /**
     * Sum of hotels currently present in a game.
     */
    private function totalHotelsInGame(int $gameId): int
    {
        return $this->propertyRepository->countTotalHotels($gameId);
    }

    private function colourGroupForSquare(int $squareIndex): ?string
    {
        $map = [
            'brown' => [1,3],
            'light_blue' => [6,8,9],
            'pink' => [11,13,14],
            'orange' => [16,18,19],
            'red' => [21,23,24],
            'yellow' => [26,27,29],
            'green' => [31,32,34],
            'dark_blue' => [37,39],
        ];

        foreach ($map as $k => $arr) {
            if (in_array($squareIndex, $arr, true)) return $k;
        }

        return null;
    }

    private function squaresForGroup(string $group): array
    {
        return match ($group) {
            'brown' => [1,3],
            'light_blue' => [6,8,9],
            'pink' => [11,13,14],
            'orange' => [16,18,19],
            'red' => [21,23,24],
            'yellow' => [26,27,29],
            'green' => [31,32,34],
            'dark_blue' => [37,39],
            default => [],
        };
    }
}
