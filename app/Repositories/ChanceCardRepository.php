<?php

namespace App\Repositories;

use App\Enums\ChanceCardAction;
use App\Models\ChanceCard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChanceCardRepository
{
    /**
     * The canonical 16-card Chance deck definition.
     *
     * Logic: Each entry is an associative array describing one Chance card with
     * the fields expected by the chance_cards table. Nullable fields are omitted
     * (they default to null on insert).
     *
     * @return list<array<string, mixed>>
     */
    private function deckDefinition(): array
    {
        return [
            [
                'action' => ChanceCardAction::AdvanceTo,
                'text'   => 'Advance to GO (Collect $200)',
                'target' => 'go',
            ],
            [
                'action' => ChanceCardAction::AdvanceTo,
                'text'   => 'Advance to Illinois Avenue – If you pass GO, collect $200',
                'target' => 'illinois_avenue',
            ],
            [
                'action' => ChanceCardAction::AdvanceTo,
                'text'   => 'Advance to St. Charles Place – If you pass GO, collect $200',
                'target' => 'st_charles_place',
            ],
            [
                'action' => ChanceCardAction::AdvanceToNearest,
                'text'   => 'Advance token to nearest Utility. If unowned, you may buy it. If owned, throw dice and pay owner 10 times that amount.',
                'target' => 'utility',
            ],
            [
                'action' => ChanceCardAction::AdvanceToNearest,
                'text'   => 'Advance token to the nearest Railroad. If unowned, you may buy it. If owned, pay owner twice the rental to which they are otherwise entitled.',
                'target' => 'railroad',
            ],
            [
                'action' => ChanceCardAction::AdvanceToNearest,
                'text'   => 'Advance token to the nearest Railroad. If unowned, you may buy it. If owned, pay owner twice the rental to which they are otherwise entitled.',
                'target' => 'railroad',
            ],
            [
                'action' => ChanceCardAction::Collect,
                'text'   => 'Bank pays you a dividend of $50',
                'amount' => 50,
            ],
            [
                'action' => ChanceCardAction::GetOutOfJailFree,
                'text'   => 'Get Out of Jail Free – This card may be kept until needed',
            ],
            [
                'action' => ChanceCardAction::MoveBack,
                'text'   => 'Go Back 3 Spaces',
                'spaces' => 3,
            ],
            [
                'action' => ChanceCardAction::GoToJail,
                'text'   => 'Go to Jail – Go directly to Jail, do not pass GO, do not collect $200',
            ],
            [
                'action'     => ChanceCardAction::PropertyRepairs,
                'text'       => 'Make general repairs on all your property – For each house pay $25, for each hotel pay $100',
                'house_cost' => 25,
                'hotel_cost' => 100,
            ],
            [
                'action' => ChanceCardAction::Pay,
                'text'   => 'Speeding fine $15',
                'amount' => 15,
            ],
            [
                'action' => ChanceCardAction::AdvanceTo,
                'text'   => 'Take a trip to Reading Railroad – If you pass GO, collect $200',
                'target' => 'reading_railroad',
            ],
            [
                'action' => ChanceCardAction::PayEachPlayer,
                'text'   => 'You have been elected Chairman of the Board – Pay each player $50',
                'amount' => 50,
            ],
            [
                'action' => ChanceCardAction::Collect,
                'text'   => 'Your building loan matures – Collect $150',
                'amount' => 150,
            ],
            [
                'action' => ChanceCardAction::Collect,
                'text'   => 'You have won a crossword competition – Collect $100',
                'amount' => 100,
            ],
        ];
    }

    /**
     * Seed the canonical 16-card Chance deck into the master chance_cards table.
     *
     * Logic: Iterates over the deck definition and bulk-inserts one row per card.
     * This must be called once (e.g. from the database seeder) before any game can
     * reference cards through the pivot. If the table already contains rows the
     * method is a no-op to prevent duplicate seeding.
     *
     * @return void
     */
    public function seedMasterDeck(): void
    {
        if (ChanceCard::exists()) {
            return;
        }

        $now  = now();
        $rows = array_map(function (array $card) use ($now): array {
            return [
                'action'     => $card['action']->value,
                'text'       => $card['text'],
                'amount'     => $card['amount'] ?? null,
                'house_cost' => $card['house_cost'] ?? null,
                'hotel_cost' => $card['hotel_cost'] ?? null,
                'target'     => $card['target'] ?? null,
                'spaces'     => $card['spaces'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $this->deckDefinition());

        ChanceCard::insert($rows);

        Log::info('Chance master deck seeded', ['card_count' => count($rows)]);
    }

    /**
     * Create a freshly shuffled Chance deck for the given game via the pivot table.
     *
     * Logic: Fetches all 16 canonical chance card IDs from the master table,
     * shuffles them randomly, then bulk-inserts rows into game_chance_cards with a
     * sequential sort_order (1–16) representing the draw sequence for this game.
     *
     * @param  int  $gameId  The ID of the game for which the deck is created.
     * @return void
     */
    public function createDeckForGame(int $gameId): void
    {
        $cardIds = ChanceCard::select(['id'])->pluck('id')->shuffle()->values();

        $now  = now();
        $rows = $cardIds->map(function (int $cardId, int $index) use ($gameId, $now): array {
            return [
                'game_id'        => $gameId,
                'chance_card_id' => $cardId,
                'sort_order'     => $index + 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        })->all();

        DB::table('game_chance_cards')->insert($rows);

        Log::info('Chance deck created for game', [
            'game_id'    => $gameId,
            'card_count' => count($rows),
        ]);
    }

    /**
     * Retrieve the ordered Chance deck for the given game.
     *
     * Logic: Joins game_chance_cards → chance_cards ordered by sort_order ascending,
     * returning all card definition fields alongside sort_order so the caller knows
     * the draw sequence from top (1) to bottom (16) of the deck.
     *
     * @param  int  $gameId  The ID of the game whose deck is retrieved.
     * @return Collection<int, ChanceCard>
     */
    public function getDeckForGame(int $gameId): Collection
    {
        return ChanceCard::select([
                'chance_cards.id',
                'chance_cards.action',
                'chance_cards.text',
                'chance_cards.amount',
                'chance_cards.house_cost',
                'chance_cards.hotel_cost',
                'chance_cards.target',
                'chance_cards.spaces',
                'game_chance_cards.sort_order',
            ])
            ->join('game_chance_cards', 'game_chance_cards.chance_card_id', '=', 'chance_cards.id')
            ->where('game_chance_cards.game_id', $gameId)
            ->orderBy('game_chance_cards.sort_order')
            ->get();
    }
}
