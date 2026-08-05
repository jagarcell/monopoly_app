<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckPlayerAssets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monopoly:check-player-assets {gameId : The game id} {joinOrder : The player join_order}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compute capital, properties, total assets and percent tax amount for a player';

    /**
     * Execute the console command.
     *
     * Logic: Reads the player's `capital` from `game_player_icons` and
     * the player's properties from `game_properties`, then mirrors the
     * server-side `computePlayerTotalAssets` logic: mortgaged properties
     * count as their mortgage value, unmortgaged properties count as
     * purchase price + building value. Building unit = intdiv(purchasePrice, 2).
     * Finally prints a detailed breakdown and the 10% percent_amount.
     *
     * @return int
     */
    public function handle(): int
    {
        $gameId = (int) $this->argument('gameId');
        $joinOrder = (int) $this->argument('joinOrder');

        $capital = (int) (DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('join_order', $joinOrder)
            ->value('capital') ?? 0);

        $this->info("Game: $gameId  Join order: $joinOrder");
        $this->info("Capital: $capital");

        $props = DB::table('game_properties')
            ->where('game_id', $gameId)
            ->where('owner_join_order', $joinOrder)
            ->orderBy('square_index')
            ->get();

        $rows = [];
        $propertiesTotal = 0;

        foreach ($props as $p) {
            $purchasePrice = (int) ($p->purchase_price ?? 0);
            $mortgageValue = intdiv($purchasePrice, 2);
            $houses = (int) ($p->houses_count ?? 0);
            $hasHotel = (bool) ($p->has_hotel ?? false);
            $buildUnit = intdiv($purchasePrice, 2);
            $buildingValue = $hasHotel ? (5 * $buildUnit) : ($houses * $buildUnit);

            if ((bool) ($p->is_mortgaged ?? false)) {
                $contrib = $mortgageValue;
            } else {
                $contrib = $purchasePrice + $buildingValue;
            }

            $propertiesTotal += $contrib;

            $rows[] = [
                'square' => $p->square_index,
                'price' => $purchasePrice,
                'mortgaged' => (bool) ($p->is_mortgaged ?? false),
                'houses' => $houses,
                'hotel' => $hasHotel,
                'contrib' => $contrib,
            ];
        }

        if (!empty($rows)) {
            $this->line('Properties:');
            $this->table(['square','price','mortgaged','houses','hotel','contrib'], $rows);
        } else {
            $this->line('No properties for this player.');
        }

        $totalAssets = $capital + $propertiesTotal;
        $percentAmount = (int) floor($totalAssets * 0.10);

        $this->line('');
        $this->info("Properties total contribution: $propertiesTotal");
        $this->info("Total assets (capital + properties): $totalAssets");
        $this->info("10% percent amount (floor): $percentAmount");

        return 0;
    }
}
