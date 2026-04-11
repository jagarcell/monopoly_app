<?php

namespace App\Repositories;

use App\Enums\CommunityChestCardAction;
use App\Models\CommunityChestCard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommunityChestCardRepository
{
    /**
     * The canonical 16-card Community Chest deck definition.
     *
     * Logic: Each entry is an associative array describing one Community Chest card
     * with the fields expected by the community_chest_cards table. Nullable fields
     * are omitted (they default to null on insert).
     *
     * @return list<array<string, mixed>>
     */
    private function deckDefinition(): array
    {
        return [
            [
                'action' => CommunityChestCardAction::AdvanceTo,
                'text'   => 'Advance to GO (Collect $200)',
                'target' => 'go',
            ],
            [
                'action' => CommunityChestCardAction::Collect,
                'text'   => 'Bank error in your favour – Collect $200',
                'amount' => 200,
            ],
            [
                'action' => CommunityChestCardAction::Pay,
                'text'   => "Doctor's fees – Pay $50",
                'amount' => 50,
            ],
            [
                'action' => CommunityChestCardAction::Collect,
                'text'   => 'From sale of stock you get $50',
                'amount' => 50,
            ],
            [
                'action' => CommunityChestCardAction::GetOutOfJailFree,
                'text'   => 'Get Out of Jail Free – This card may be kept until needed',
            ],
            [
                'action' => CommunityChestCardAction::GoToJail,
                'text'   => 'Go to Jail – Go directly to Jail, do not pass GO, do not collect $200',
            ],
            [
                'action' => CommunityChestCardAction::CollectFromEachPlayer,
                'text'   => 'Grand Opera Night – Collect $50 from every player for opening night seats',
                'amount' => 50,
            ],
            [
                'action' => CommunityChestCardAction::Collect,
                'text'   => 'Holiday Fund matures – Receive $100',
                'amount' => 100,
            ],
            [
                'action' => CommunityChestCardAction::Collect,
                'text'   => 'Income tax refund – Collect $20',
                'amount' => 20,
            ],
            [
                'action' => CommunityChestCardAction::CollectFromEachPlayer,
                'text'   => 'It is your birthday – Collect $10 from every player',
                'amount' => 10,
            ],
            [
                'action' => CommunityChestCardAction::Collect,
                'text'   => 'Life insurance matures – Collect $100',
                'amount' => 100,
            ],
            [
                'action' => CommunityChestCardAction::Pay,
                'text'   => 'Pay hospital fees of $100',
                'amount' => 100,
            ],
            [
                'action' => CommunityChestCardAction::Pay,
                'text'   => 'Pay school fees of $150',
                'amount' => 150,
            ],
            [
                'action' => CommunityChestCardAction::Collect,
                'text'   => 'Receive $25 consultancy fee',
                'amount' => 25,
            ],
            [
                'action'     => CommunityChestCardAction::PropertyRepairs,
                'text'       => 'You are assessed for street repairs – $40 per house, $115 per hotel',
                'house_cost' => 40,
                'hotel_cost' => 115,
            ],
            [
                'action' => CommunityChestCardAction::Collect,
                'text'   => 'You have won second prize in a beauty contest – Collect $10',
                'amount' => 10,
            ],
        ];
    }

    /**
     * Seed the canonical 16-card Community Chest deck into the master table.
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
        if (CommunityChestCard::exists()) {
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
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $this->deckDefinition());

        CommunityChestCard::insert($rows);

        Log::info('Community Chest master deck seeded', ['card_count' => count($rows)]);
    }

    /**
     * Create a freshly shuffled Community Chest deck for the given game via the pivot table.
     *
     * Logic: Fetches all 16 canonical community chest card IDs from the master table,
     * shuffles them randomly, then bulk-inserts rows into game_community_chest_cards
     * with a sequential sort_order (1–16) representing the draw sequence for this game.
     *
     * @param  int  $gameId  The ID of the game for which the deck is created.
     * @return void
     */
    public function createDeckForGame(int $gameId): void
    {
        $cardIds = CommunityChestCard::select(['id'])->pluck('id')->shuffle()->values();

        $now  = now();
        $rows = $cardIds->map(function (int $cardId, int $index) use ($gameId, $now): array {
            return [
                'game_id'                  => $gameId,
                'community_chest_card_id'  => $cardId,
                'sort_order'               => $index + 1,
                'created_at'               => $now,
                'updated_at'               => $now,
            ];
        })->all();

        DB::table('game_community_chest_cards')->insert($rows);

        Log::info('Community Chest deck created for game', [
            'game_id'    => $gameId,
            'card_count' => count($rows),
        ]);
    }

    /**
     * Retrieve the ordered Community Chest deck for the given game.
     *
     * Logic: Joins game_community_chest_cards → community_chest_cards ordered by
     * sort_order ascending, returning all card definition fields alongside sort_order
     * so the caller knows the draw sequence from top (1) to bottom (16).
     *
     * @param  int  $gameId  The ID of the game whose deck is retrieved.
     * @return Collection<int, CommunityChestCard>
     */
    public function getDeckForGame(int $gameId): Collection
    {
        return CommunityChestCard::select([
                'community_chest_cards.id',
                'community_chest_cards.action',
                'community_chest_cards.text',
                'community_chest_cards.amount',
                'community_chest_cards.house_cost',
                'community_chest_cards.hotel_cost',
                'community_chest_cards.target',
                'game_community_chest_cards.sort_order',
            ])
            ->join(
                'game_community_chest_cards',
                'game_community_chest_cards.community_chest_card_id',
                '=',
                'community_chest_cards.id'
            )
            ->where('game_community_chest_cards.game_id', $gameId)
            ->orderBy('game_community_chest_cards.sort_order')
            ->get();
    }
}
