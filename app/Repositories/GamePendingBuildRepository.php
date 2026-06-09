<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GamePendingBuildRepository
{
    public function addPendingBuild(int $gameId, int $ownerJoinOrder, int $squareIndex, int $housesDelta = 0, bool $hasHotel = false): void
    {
        DB::table('game_pending_builds')->insert([
            'game_id' => $gameId,
            'owner_join_order' => $ownerJoinOrder,
            'square_index' => $squareIndex,
            'houses_delta' => $housesDelta,
            'has_hotel' => $hasHotel,
            'created_at' => now(),
        ]);

        Log::info('Queued pending build', compact('gameId', 'ownerJoinOrder', 'squareIndex', 'housesDelta', 'hasHotel'));
    }

    public function countPendingHouses(int $gameId): int
    {
        $row = DB::table('game_pending_builds')
            ->where('game_id', $gameId)
            ->where('has_hotel', false)
            ->selectRaw('COALESCE(SUM(houses_delta),0) as total')
            ->first();

        return $row ? (int) $row->total : 0;
    }

    public function countPendingHotels(int $gameId): int
    {
        $row = DB::table('game_pending_builds')
            ->where('game_id', $gameId)
            ->where('has_hotel', true)
            ->selectRaw('COUNT(*) as total')
            ->first();

        return $row ? (int) $row->total : 0;
    }

    /**
     * Apply and clear pending builds for a specific owner in a game.
     */
    /**
     * Apply and clear pending builds for a specific owner in a game.
     *
     * Returns an array of applied build summaries: [ ['square_index'=>int, 'houses_count'=>int, 'has_hotel'=>bool], ... ]
     */
    public function applyPendingBuildsForOwner(int $gameId, int $ownerJoinOrder): array
    {
        $rows = DB::table('game_pending_builds')
            ->where('game_id', $gameId)
            ->where('owner_join_order', $ownerJoinOrder)
            ->orderBy('id')
            ->get();

        $applied = [];

        DB::transaction(function () use ($gameId, $ownerJoinOrder, $rows) {
            foreach ($rows as $r) {
                $sq = (int) $r->square_index;
                $housesDelta = (int) $r->houses_delta;
                $isHotel = (bool) $r->has_hotel;

                $current = DB::table('game_properties')
                    ->where('game_id', $gameId)
                    ->where('square_index', $sq)
                    ->select(['houses_count', 'has_hotel'])
                    ->first();

                if ($current === null) continue;

                if ($isHotel) {
                    DB::table('game_properties')
                        ->where('game_id', $gameId)
                        ->where('square_index', $sq)
                        ->update(['houses_count' => 0, 'has_hotel' => true, 'updated_at' => now()]);
                } else {
                    $newCount = ((int) ($current->houses_count ?? 0)) + $housesDelta;
                    DB::table('game_properties')
                        ->where('game_id', $gameId)
                        ->where('square_index', $sq)
                        ->update(['houses_count' => $newCount, 'updated_at' => now()]);
                }
            }

            // Remove applied rows
            DB::table('game_pending_builds')
                ->where('game_id', $gameId)
                ->where('owner_join_order', $ownerJoinOrder)
                ->delete();
        });

        // Build summary of applied builds for broadcasting
        foreach ($rows as $r) {
            $applied[] = [
                'square_index' => (int) $r->square_index,
                'houses_count' => null, // will be resolved below
                'has_hotel'    => (bool) $r->has_hotel,
            ];
        }

        // Resolve final building counts for applied squares
        $squares = array_unique(array_map(fn($r) => (int) $r->square_index, $rows->all()));
        if (!empty($squares)) {
            $final = DB::table('game_properties')
                ->where('game_id', $gameId)
                ->whereIn('square_index', $squares)
                ->select(['square_index', 'houses_count', 'has_hotel'])
                ->get()
                ->keyBy(fn($r) => (int) $r->square_index)
                ->all();

            foreach ($applied as &$item) {
                $sq = $item['square_index'];
                if (isset($final[$sq])) {
                    $item['houses_count'] = (int) ($final[$sq]->houses_count ?? 0);
                    $item['has_hotel'] = (bool) ($final[$sq]->has_hotel ?? false);
                }
            }
            unset($item);
        }

        Log::info('Applied pending builds for owner', ['game_id' => $gameId, 'owner_join_order' => $ownerJoinOrder, 'applied_count' => count($applied)]);

        return $applied;
    }

    /**
     * Get pending builds summary for a game (for diagnostics / UI if needed)
     */
    public function getPendingBuildsForGame(int $gameId): array
    {
        return DB::table('game_pending_builds')
            ->where('game_id', $gameId)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }
}
