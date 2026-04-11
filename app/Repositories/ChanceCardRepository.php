<?php

namespace App\Repositories;

use App\Enums\ChanceCardAction;
use App\Models\ChanceCard;
use Illuminate\Support\Collection;
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
     * Bulk-insert a freshly shuffled Chance deck for the given game.
     *
     * Logic: Takes the canonical 16-card deck definition, shuffles it randomly,
     * assigns a sequential sort_order (1–16) to each card, then bulk-inserts all
     * rows in a single query. The sort_order represents the draw sequence for the
     * game, so drawing the top card always follows ascending sort_order.
     *
     * @param  int  $gameId  The ID of the game for which the deck is created.
     * @return void
     */
    public function createDeckForGame(int $gameId): void
    {
        $deck = collect($this->deckDefinition())->shuffle()->values();

        $now  = now();
        $rows = $deck->map(function (array $card, int $index) use ($gameId, $now): array {
            return [
                'game_id'    => $gameId,
                'action'     => $card['action']->value,
                'text'       => $card['text'],
                'amount'     => $card['amount'] ?? null,
                'house_cost' => $card['house_cost'] ?? null,
                'hotel_cost' => $card['hotel_cost'] ?? null,
                'target'     => $card['target'] ?? null,
                'spaces'     => $card['spaces'] ?? null,
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();

        ChanceCard::insert($rows);

        Log::info('Chance deck created', [
            'game_id'    => $gameId,
            'card_count' => count($rows),
        ]);
    }

    /**
     * Retrieve the ordered Chance deck for the given game.
     *
     * Logic: Fetches all chance cards for a game ordered by sort_order ascending,
     * representing the draw sequence from top (1) to bottom (16) of the deck.
     *
     * @param  int  $gameId  The ID of the game whose deck is retrieved.
     * @return Collection<int, ChanceCard>
     */
    public function getDeckForGame(int $gameId): Collection
    {
        return ChanceCard::where('game_id', $gameId)
            ->select([
                'id',
                'game_id',
                'action',
                'text',
                'amount',
                'house_cost',
                'hotel_cost',
                'target',
                'spaces',
                'sort_order',
            ])
            ->orderBy('sort_order')
            ->get();
    }
}
