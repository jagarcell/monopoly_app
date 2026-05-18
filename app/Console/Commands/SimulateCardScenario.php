<?php

namespace App\Console\Commands;

use App\Events\CardAccepted;
use App\Events\CardDrawn;
use App\Events\DiceRolled;
use App\Events\TokenMoved;
use App\Events\TurnAdvanced;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Artisan command that drives a single card-scenario step of the live board
 * simulation by dispatching real WebSocket broadcast events so all three
 * open browser tabs update visually without requiring manual dice rolls.
 *
 * The command reads the persistent state file at STATE_FILE to determine the
 * next scenario index, hydrates the authoritative player positions/capitals and
 * current turn from the database, positions the active player on the draw
 * square in the DB, fires DiceRolled → CardDrawn → CardAccepted → TokenMoved
 * (if movement) → TurnAdvanced in sequence with brief delays so the boards can
 * process each event before the next arrives, then writes the updated state
 * file and prints a split-board terminal summary.
 *
 * Usage (from host):
 *   docker compose exec laravel.test php artisan sim:card-scenario
 */
class SimulateCardScenario extends Command
{
    protected $signature   = 'sim:card-scenario';
    protected $description = 'Run the next card simulation scenario and broadcast live WebSocket events to all open boards.';

    private const GAME_ID    = 61;
    private const STATE_FILE = 'sim_state.json'; // relative to storage/app/ inside the container

    /**
     * Execute the console command.
     *
     * Logic:
     *   1. Builds the full Chance + Community deck via reflection (no DB needed).
    *   2. Reads the persistent state file for the current scenario index.
    *   3. Hydrates the authoritative current turn, player positions, and capitals from DB.
     *   3. Positions the active player at the card draw square in DB and dispatches DiceRolled.
     *   4. Computes the card effect, updates DB (capital + square_index), then dispatches CardDrawn.
     *   5. Dispatches CardAccepted so observer boards auto-close their notification.
     *   6. Dispatches TokenMoved when the card caused position change.
     *   7. Advances the turn in DB and dispatches TurnAdvanced.
     *   8. Writes the updated state file and prints the split-board summary.
     *
     * @return int  Exit code (0 = success).
     */
    public function handle(): int
    {
        // --- 1. Build full deck via reflection (no DB) ----------------------------
        $allCards       = $this->buildFullDeck();
        $totalScenarios = count($allCards);

        // --- 2. Load persistent state ---------------------------------------------
        $statePath = storage_path('app/' . self::STATE_FILE);
        if (!file_exists($statePath)) {
            $this->error('State file not found: ' . $statePath);
            return 1;
        }

        $state = json_decode(file_get_contents($statePath), true);
        if (!$state) {
            $this->error('Could not parse state file.');
            return 1;
        }

        $scenarioNum = (int) $state['scenario'];

        if ($scenarioNum > $totalScenarios) {
            $this->info("All {$totalScenarios} scenarios completed.");
            return 0;
        }

        $card       = $allCards[$scenarioNum - 1];
        $drawSquare = $card['deck'] === 'chance' ? 7 : 2;
        $gameId     = self::GAME_ID;

        // Snapshot the authoritative live state before applying effects.
        $activeJoinOrder = $this->fetchCurrentTurnJoinOrder($gameId, (int) ($state['turn'] ?? 1));
        $players         = $this->fetchPlayerStates($gameId);
        $playersBefore   = array_map(fn ($player) => $player, $players);

        // Fetch the player name for CardDrawn payload
        $playerName = $this->fetchPlayerName($gameId, $activeJoinOrder);

        // --- 3. Position active player at draw square in DB -----------------------
        // Set current_turn_join_order so the game state matches
        DB::table('games')
            ->where('id', $gameId)
            ->update(['current_turn_join_order' => $activeJoinOrder, 'turn_phase' => 'roll']);

        $prevSquare = $players[$activeJoinOrder]['square_index'];

        // Compute fake dice: minimum steps from prevSquare to drawSquare
        $steps = ($drawSquare - $prevSquare + 40) % 40;
        if ($steps === 0) {
            // Already on draw square: need a full lap (simulate rolling 40 - but dice max is 12,
            // so use 10 as a safe "not landing on anything interesting" value first)
            $steps = 40;
        }
        $die1 = (int) ceil($steps / 2);
        $die2 = $steps - $die1;

        DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('join_order', $activeJoinOrder)
            ->update(['square_index' => $drawSquare]);

        $players[$activeJoinOrder]['square_index'] = $drawSquare;

        // Dispatch DiceRolled so all boards animate dice and register the move
        DiceRolled::dispatch($gameId, $die1, $die2, $steps, $activeJoinOrder, $drawSquare);

        $this->pauseForBoards(900_000); // 0.9 s — let boards animate token to draw square

        // --- 4. Compute card effect and apply to DB --------------------------------
        $effect    = $this->computeEffect($card, $drawSquare, $players, $activeJoinOrder, $gameId);
        $cardArray = [
            'id'     => $card['deck_index'],
            'text'   => $card['text'],
            'action' => $card['action'],
        ];

        // Dispatch CardDrawn (all boards show capital update; observer boards show notification)
        CardDrawn::dispatch(
            $gameId,
            $card['deck'],
            $cardArray,
            $activeJoinOrder,
            $playerName,
            $effect,
        );

        $this->pauseForBoards(2_200_000); // 2.2 s — let boards display the card notification

        // --- 5. Dispatch CardAccepted (observer boards auto-close notification) ----
        CardAccepted::dispatch($gameId);

        $this->pauseForBoards(500_000); // 0.5 s

        // --- 6. Dispatch TokenMoved for movement cards ----------------------------
        $movementActions = ['advance_to', 'advance_to_nearest', 'go_to_jail', 'move_back'];
        if (in_array($card['action'], $movementActions, true)) {
            $finalSquare = $players[$activeJoinOrder]['square_index'];
            $backward    = $card['action'] === 'move_back';
            TokenMoved::dispatch($gameId, $activeJoinOrder, $finalSquare, $backward);
            $this->pauseForBoards(700_000); // 0.7 s — let boards animate token to final square
        }

        // --- 7. Advance turn in DB and dispatch TurnAdvanced ----------------------
        $nextJoinOrder = $activeJoinOrder === 3 ? 1 : $activeJoinOrder + 1;
        DB::table('games')
            ->where('id', $gameId)
            ->update(['current_turn_join_order' => $nextJoinOrder, 'turn_phase' => 'roll']);

        TurnAdvanced::dispatch($gameId, $nextJoinOrder);

        // --- 8. Persist updated state and print summary ---------------------------
        $state['players']  = $players;
        $state['turn']     = $nextJoinOrder;
        $state['scenario'] = $scenarioNum + 1;
        file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT));

        $this->printBoard($scenarioNum, $totalScenarios, $card, $effect, $activeJoinOrder, $nextJoinOrder, $playersBefore, $players);

        // Log to session log
        $this->appendSessionLog($scenarioNum, $card, $effect, $activeJoinOrder, $nextJoinOrder, $playersBefore, $players);

        return 0;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the combined Chance + Community Chest deck using reflection so no
     * DB connection is needed at build time.
     *
     * Logic: Invokes the private deckDefinition() method on each repository via
     * ReflectionMethod, maps the result to plain arrays with string action values,
     * and merges both decks in order (Chance cards 1-16 first, Community 1-16 second).
     *
     * @return list<array<string, mixed>>  Flat array of card descriptors ordered 1-32.
     */
    private function buildFullDeck(): array
    {
        $chanceRef = new \ReflectionMethod(ChanceCardRepository::class, 'deckDefinition');
        $chanceRef->setAccessible(true);
        $chanceDeck = $chanceRef->invoke(new ChanceCardRepository());
        $chanceDeck = array_map(function ($c, $i) {
            $c['action']     = $c['action']->value;
            $c['deck']       = 'chance';
            $c['deck_index'] = $i + 1;
            return $c;
        }, $chanceDeck, array_keys($chanceDeck));

        $communityRef = new \ReflectionMethod(CommunityChestCardRepository::class, 'deckDefinition');
        $communityRef->setAccessible(true);
        $communityDeck = $communityRef->invoke(new CommunityChestCardRepository());
        $communityDeck = array_map(function ($c, $i) {
            $c['action']     = $c['action']->value;
            $c['deck']       = 'community';
            $c['deck_index'] = $i + 1;
            return $c;
        }, $communityDeck, array_keys($communityDeck));

        return array_merge($chanceDeck, $communityDeck);
    }

    /**
     * Return the authoritative current turn join_order for the live game.
     *
     * Logic: Reads games.current_turn_join_order from the database so the
     * simulator follows the live game rather than stale state-file metadata.
     * Falls back to the provided value when the game row is missing or the
     * column is null.
     *
     * @param  int  $gameId    Game primary key.
     * @param  int  $fallback  Join order to use when the DB has no current turn.
     * @return int
     */
    private function fetchCurrentTurnJoinOrder(int $gameId, int $fallback = 1): int
    {
        $row = DB::table('games')
            ->where('id', $gameId)
            ->select(['current_turn_join_order'])
            ->first();

        return $row?->current_turn_join_order !== null
            ? (int) $row->current_turn_join_order
            : $fallback;
    }

    /**
     * Return the authoritative live player capitals and positions keyed by join_order.
     *
     * Logic: Reads game_player_icons directly from the database so command runs
     * always start from the current live board state instead of any stale values
     * stored in the simulator state file.
     *
     * @param  int  $gameId  Game primary key.
     * @return array<int, array{capital: int, square_index: int}>
     */
    private function fetchPlayerStates(int $gameId): array
    {
        return DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->orderBy('join_order')
            ->get(['join_order', 'capital', 'square_index'])
            ->mapWithKeys(function (object $row): array {
                return [
                    (int) $row->join_order => [
                        'capital'      => (int) $row->capital,
                        'square_index' => (int) $row->square_index,
                    ],
                ];
            })
            ->all();
    }

    /**
     * Pause briefly between broadcast events unless the app is running tests.
     *
     * Logic: Preserves the human-visible pacing of live simulations in normal
     * runs, but skips the sleeps during PHPUnit so command tests remain fast.
     *
     * @param  int  $microseconds  Delay duration in microseconds.
     * @return void
     */
    private function pauseForBoards(int $microseconds): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        usleep($microseconds);
    }

    /**
     * Compute the card effect, apply all DB changes (capital and/or position),
     * and return the effect descriptor array used in the CardDrawn broadcast.
     *
     * Logic: Switches on card action. Capital mutations use absolute values
     * persisted immediately. The $players array is mutated in-place so the
     * caller can read updated values for downstream events and state file writes.
     *
     * @param  array<string, mixed>  $card              Card descriptor from deck.
     * @param  int                   $drawSquare        Square the player landed on to draw.
     * @param  array<int, array>     &$players          Mutable player state keyed by join_order.
     * @param  int                   $activeJoinOrder   The drawing player's join_order.
     * @param  int                   $gameId            Game primary key.
     * @return array<string, mixed>  Effect descriptor for CardDrawn broadcast.
     */
    private function computeEffect(array $card, int $drawSquare, array &$players, int $activeJoinOrder, int $gameId): array
    {
        switch ($card['action']) {
            case 'collect':
                $amount = (int) ($card['amount'] ?? 0);
                $players[$activeJoinOrder]['capital'] += $amount;
                DB::table('game_player_icons')
                    ->where('game_id', $gameId)->where('join_order', $activeJoinOrder)
                    ->update(['capital' => $players[$activeJoinOrder]['capital']]);
                return ['type' => 'collect', 'amount' => $amount, 'new_capital' => $players[$activeJoinOrder]['capital']];

            case 'pay':
                $amount = (int) ($card['amount'] ?? 0);
                $players[$activeJoinOrder]['capital'] -= $amount;
                DB::table('game_player_icons')
                    ->where('game_id', $gameId)->where('join_order', $activeJoinOrder)
                    ->update(['capital' => $players[$activeJoinOrder]['capital']]);
                return ['type' => 'pay', 'amount' => $amount, 'new_capital' => $players[$activeJoinOrder]['capital']];

            case 'advance_to':
                $target = $this->targetSquareForCard($card['target'] ?? 'go');
                $steps  = ($target - $drawSquare + 40) % 40;
                $pg     = ($drawSquare + $steps) >= 40;
                if ($pg) {
                    $players[$activeJoinOrder]['capital'] += 200;
                    DB::table('game_player_icons')
                        ->where('game_id', $gameId)->where('join_order', $activeJoinOrder)
                        ->update(['capital' => $players[$activeJoinOrder]['capital']]);
                }
                $players[$activeJoinOrder]['square_index'] = $target;
                DB::table('game_player_icons')
                    ->where('game_id', $gameId)->where('join_order', $activeJoinOrder)
                    ->update(['square_index' => $target]);
                return [
                    'type'             => 'advance_to',
                    'new_square_index' => $target,
                    'passed_go'        => $pg,
                    'go_bonus'         => $pg ? 200 : 0,
                    'new_capital'      => $pg ? $players[$activeJoinOrder]['capital'] : null,
                ];

            case 'advance_to_nearest':
                $targetType = $card['target'] ?? 'railroad';
                $candidates = $targetType === 'railroad' ? [5, 15, 25, 35] : [12, 28];
                $target     = $this->nearestSquare($candidates, $drawSquare);
                $steps      = ($target - $drawSquare + 40) % 40;
                $pg         = ($drawSquare + $steps) >= 40;
                if ($pg) {
                    $players[$activeJoinOrder]['capital'] += 200;
                    DB::table('game_player_icons')
                        ->where('game_id', $gameId)->where('join_order', $activeJoinOrder)
                        ->update(['capital' => $players[$activeJoinOrder]['capital']]);
                }
                $players[$activeJoinOrder]['square_index'] = $target;
                DB::table('game_player_icons')
                    ->where('game_id', $gameId)->where('join_order', $activeJoinOrder)
                    ->update(['square_index' => $target]);
                return [
                    'type'             => 'advance_to_nearest',
                    'target'           => $targetType,
                    'new_square_index' => $target,
                    'passed_go'        => $pg,
                    'go_bonus'         => $pg ? 200 : 0,
                    'new_capital'      => $pg ? $players[$activeJoinOrder]['capital'] : null,
                ];

            case 'go_to_jail':
                $players[$activeJoinOrder]['square_index'] = 10;
                DB::table('game_player_icons')
                    ->where('game_id', $gameId)->where('join_order', $activeJoinOrder)
                    ->update(['square_index' => 10]);
                return ['type' => 'go_to_jail', 'new_square_index' => 10];

            case 'get_out_of_jail_free':
                return ['type' => 'get_out_of_jail_free'];

            case 'move_back':
                $spaces = (int) ($card['spaces'] ?? 3);
                $ns     = ($drawSquare - $spaces + 40) % 40;
                $players[$activeJoinOrder]['square_index'] = $ns;
                DB::table('game_player_icons')
                    ->where('game_id', $gameId)->where('join_order', $activeJoinOrder)
                    ->update(['square_index' => $ns]);
                return ['type' => 'move_back', 'spaces' => $spaces, 'new_square_index' => $ns];

            case 'pay_each_player':
                $amount = (int) ($card['amount'] ?? 0);
                $others = array_values(array_filter([1, 2, 3], fn ($j) => $j !== $activeJoinOrder));
                $players[$activeJoinOrder]['capital'] -= $amount * count($others);
                DB::table('game_player_icons')
                    ->where('game_id', $gameId)->where('join_order', $activeJoinOrder)
                    ->update(['capital' => $players[$activeJoinOrder]['capital']]);
                $otherCapitals = [];
                foreach ($others as $j) {
                    $players[$j]['capital'] += $amount;
                    DB::table('game_player_icons')
                        ->where('game_id', $gameId)->where('join_order', $j)
                        ->update(['capital' => $players[$j]['capital']]);
                    $otherCapitals[] = ['join_order' => $j, 'capital' => $players[$j]['capital']];
                }
                return [
                    'type'                  => 'pay_each_player',
                    'amount'                => $amount,
                    'new_capital'           => $players[$activeJoinOrder]['capital'],
                    'other_player_capitals' => $otherCapitals,
                ];

            case 'collect_from_each_player':
                $amount = (int) ($card['amount'] ?? 0);
                $others = array_values(array_filter([1, 2, 3], fn ($j) => $j !== $activeJoinOrder));
                $players[$activeJoinOrder]['capital'] += $amount * count($others);
                DB::table('game_player_icons')
                    ->where('game_id', $gameId)->where('join_order', $activeJoinOrder)
                    ->update(['capital' => $players[$activeJoinOrder]['capital']]);
                $otherCapitals = [];
                foreach ($others as $j) {
                    $players[$j]['capital'] -= $amount;
                    DB::table('game_player_icons')
                        ->where('game_id', $gameId)->where('join_order', $j)
                        ->update(['capital' => $players[$j]['capital']]);
                    $otherCapitals[] = ['join_order' => $j, 'capital' => $players[$j]['capital']];
                }
                return [
                    'type'                  => 'collect_from_each_player',
                    'amount'                => $amount,
                    'new_capital'           => $players[$activeJoinOrder]['capital'],
                    'other_player_capitals' => $otherCapitals,
                ];

            case 'property_repairs':
                return ['type' => 'property_repairs', 'amount' => 0, 'new_capital' => null];

            default:
                return [];
        }
    }

    /**
     * Resolve the canonical board square index for a named advance_to target.
     *
     * @param  string  $target  Target name from card descriptor.
     * @return int  Board square index (0-based, 0 = GO).
     */
    private function targetSquareForCard(string $target): int
    {
        return match ($target) {
            'illinois_avenue'  => 24,
            'st_charles_place' => 11,
            'reading_railroad' => 5,
            default            => 0, // GO
        };
    }

    /**
     * Return the nearest square index from $candidates that is ahead of $from
     * (wrapping at 40).
     *
     * Logic: For each candidate, computes the forward distance modulo 40.
     * Distances of zero (already on that square) are excluded. Returns the
     * candidate with the smallest positive forward distance.
     *
     * @param  list<int>  $squares  Candidate square indices.
     * @param  int        $from     Current square index.
     * @return int  Nearest forward square index.
     */
    private function nearestSquare(array $squares, int $from): int
    {
        $best     = $squares[0];
        $bestDist = PHP_INT_MAX;
        foreach ($squares as $sq) {
            $dist = ($sq - $from + 40) % 40;
            if ($dist > 0 && $dist < $bestDist) {
                $bestDist = $dist;
                $best     = $sq;
            }
        }
        return $best;
    }

    /**
     * Fetch the display name for a player (email for guests, user name for auth users).
     *
     * @param  int  $gameId     Game primary key.
     * @param  int  $joinOrder  The player's join_order.
     * @return string
     */
    private function fetchPlayerName(int $gameId, int $joinOrder): string
    {
        $row = DB::table('game_player_icons as gpi')
            ->leftJoin('users as u', 'u.id', '=', 'gpi.user_id')
            ->leftJoin('game_invitations as gi', 'gi.id', '=', 'gpi.invitation_id')
            ->where('gpi.game_id', $gameId)
            ->where('gpi.join_order', $joinOrder)
            ->select(['u.name as user_name', 'gi.email as guest_email'])
            ->first();

        if ($row === null) {
            return "Player {$joinOrder}";
        }

        return $row->user_name ?? $row->guest_email ?? "Player {$joinOrder}";
    }

    /**
     * Print the three-column split-board summary to the terminal.
     *
     * @param  int                   $scenarioNum   Current scenario number.
     * @param  int                   $total         Total number of scenarios.
     * @param  array<string, mixed>  $card          Card descriptor.
     * @param  array<string, mixed>  $effect        Computed card effect.
     * @param  int                   $active        Active player join_order.
     * @param  int                   $next          Next turn join_order.
     * @param  array<int, array>     $before        Player state before this scenario.
     * @param  array<int, array>     $after         Player state after this scenario.
     * @return void
     */
    private function printBoard(int $scenarioNum, int $total, array $card, array $effect, int $active, int $next, array $before, array $after): void
    {
        $cols = [];
        for ($p = 1; $p <= 3; $p++) {
            $cols[] = [
                $p === $active ? "Board P{$p} <<< ACTIVE" : "Board P{$p}",
                'Before: $' . $before[$p]['capital'] . ' sq=' . $before[$p]['square_index'],
                'After : $' . $after[$p]['capital'] . ' sq=' . $after[$p]['square_index'],
            ];
        }

        $w   = 44;
        $sep = str_repeat('-', $w);

        $this->line('==================================================');
        $this->line("SCENARIO {$scenarioNum}/{$total}  |  " . strtoupper($card['deck']) . " CARD #{$card['deck_index']}");
        $this->line("Active: P{$active}  ->  Next turn: P{$next}");
        $this->line('Card:   ' . $card['text']);
        $this->line('Effect: ' . json_encode($effect, JSON_UNESCAPED_SLASHES));
        $this->line('==================================================');
        $this->line("+{$sep}+{$sep}+{$sep}+");
        $this->line(sprintf('|%-' . $w . 's|%-' . $w . 's|%-' . $w . 's|', $cols[0][0], $cols[1][0], $cols[2][0]));
        $this->line("+{$sep}+{$sep}+{$sep}+");
        for ($i = 1; $i <= 2; $i++) {
            $this->line(sprintf('|%-' . $w . 's|%-' . $w . 's|%-' . $w . 's|', $cols[0][$i], $cols[1][$i], $cols[2][$i]));
        }
        $this->line("+{$sep}+{$sep}+{$sep}+");
    }

    /**
     * Append a one-line entry to the agent session log.
     *
     * @param  int                   $scenarioNum   Current scenario number.
     * @param  array<string, mixed>  $card          Card descriptor.
     * @param  array<string, mixed>  $effect        Computed card effect.
     * @param  int                   $active        Active player join_order.
     * @param  int                   $next          Next turn join_order.
     * @param  array<int, array>     $before        Player state before this scenario.
     * @param  array<int, array>     $after         Player state after this scenario.
     * @return void
     */
    private function appendSessionLog(int $scenarioNum, array $card, array $effect, int $active, int $next, array $before, array $after): void
    { 
        $logDir = base_path('logs');

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/agent-session.md';
        $entry   = sprintf(
            "- Scenario %d/32 [LIVE]: %s CARD #%d \"%s\" | P%d active | P%d cap: %d→%d sq: %d→%d | Next: P%d\n",
            $scenarioNum,
            strtoupper($card['deck']),
            $card['deck_index'],
            $card['text'],
            $active,
            $active,
            $before[$active]['capital'],
            $after[$active]['capital'],
            $before[$active]['square_index'],
            $after[$active]['square_index'],
            $next,
        );

        file_put_contents($logFile, $entry, FILE_APPEND);
    }
}
