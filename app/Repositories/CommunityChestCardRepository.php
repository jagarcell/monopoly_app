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

    /**
     * Assign a game Community Chest card to a player as a held card.
     *
     * Logic: Updates the pivot row for the given game/card pair by setting
     * holder_join_order, allowing get-out-of-jail-free cards to persist in
     * player hands across page refreshes and later reads.
     *
     * @param  int  $gameId           The ID of the game.
     * @param  int  $cardId           The community_chest_cards.id being assigned.
     * @param  int  $holderJoinOrder  The join_order of the player holding the card.
     * @return void
     */
    public function assignCardToPlayer(int $gameId, int $cardId, int $holderJoinOrder): void
    {
        DB::table('game_community_chest_cards')
            ->where('game_id', $gameId)
            ->where('community_chest_card_id', $cardId)
            ->update([
                'holder_join_order' => $holderJoinOrder,
                'updated_at'        => now(),
            ]);

        Log::info('Community Chest card assigned to player hand', [
            'game_id'           => $gameId,
            'card_id'           => $cardId,
            'holder_join_order' => $holderJoinOrder,
        ]);
    }

    /**
     * Release a held Community Chest card from a player's hand and return it to the bottom of the deck.
     *
     * Logic: Looks up the held pivot row for the given player under a row lock.
     * When found, clears holder_join_order, shifts every currently unheld card
     * up by one sort position, and places the released card at the maximum sort
     * order so it re-enters the active deck at the bottom. Returns false when
     * the player does not currently hold a Community Chest card.
     *
     * @param  int  $gameId           The ID of the game.
     * @param  int  $holderJoinOrder  The join_order of the player using the card.
     * @return bool
     */
    public function releaseHeldCardFromPlayer(int $gameId, int $holderJoinOrder): bool
    {
        return DB::transaction(function () use ($gameId, $holderJoinOrder): bool {
            $pivot = DB::table('game_community_chest_cards')
                ->where('game_id', $gameId)
                ->where('holder_join_order', $holderJoinOrder)
                ->lockForUpdate()
                ->first(['community_chest_card_id']);

            if ($pivot === null) {
                return false;
            }

            DB::table('game_community_chest_cards')
                ->where('game_id', $gameId)
                ->whereNull('holder_join_order')
                ->decrement('sort_order');

            DB::table('game_community_chest_cards')
                ->where('game_id', $gameId)
                ->where('community_chest_card_id', $pivot->community_chest_card_id)
                ->update([
                    'holder_join_order' => null,
                    'sort_order'        => 16,
                    'updated_at'        => now(),
                ]);

            Log::info('Community Chest card returned to deck bottom', [
                'game_id'           => $gameId,
                'card_id'           => $pivot->community_chest_card_id,
                'holder_join_order' => $holderJoinOrder,
            ]);

            return true;
        });
    }

    /**
     * Get all held Community Chest cards in a game grouped by holder join_order.
     *
     * Logic: Reads pivot rows where holder_join_order is present, joins with
     * community_chest_cards to fetch card metadata, and groups into an
     * associative array keyed by holder join_order so PlayerIconRepository can
     * hydrate player hands.
     *
     * @param  int  $gameId  The ID of the game.
     * @return array<int, array<int, array{id: int, action: string, text: string}>>
     */
    public function getHeldCardsForGame(int $gameId): array
    {
        $rows = DB::table('game_community_chest_cards as gccc')
            ->join('community_chest_cards as ccc', 'ccc.id', '=', 'gccc.community_chest_card_id')
            ->where('gccc.game_id', $gameId)
            ->whereNotNull('gccc.holder_join_order')
            ->orderBy('gccc.holder_join_order')
            ->orderBy('ccc.id')
            ->select([
                'gccc.holder_join_order',
                'ccc.id',
                'ccc.action',
                'ccc.text',
            ])
            ->get();

        $cardsByHolder = [];

        foreach ($rows as $row) {
            $holderJoinOrder = (int) $row->holder_join_order;

            if (!isset($cardsByHolder[$holderJoinOrder])) {
                $cardsByHolder[$holderJoinOrder] = [];
            }

            $cardsByHolder[$holderJoinOrder][] = [
                'id'     => (int) $row->id,
                'action' => (string) $row->action,
                'text'   => (string) $row->text,
            ];
        }

        return $cardsByHolder;
    }

    /**
    * Draw the top available Community Chest card for the given game and send it to the bottom.
     *
     * Logic:
     *   1. Opens a DB transaction and acquires a row-level lock on all pivot rows
     *      for the game to prevent concurrent draws returning the same card.
    *   2. Picks the lowest sort_order row that is not currently held by a player.
     *   3. Decrements sort_order by 1 for every remaining card (sort_order > 1),
     *      collapsing the sequence to 1..15.
     *   4. Sets the drawn card's sort_order to 16 (bottom of the deck).
     *   5. Returns the full card definition as a plain array suitable for JSON serialisation.
     *
     * @param  int  $gameId  The ID of the game whose deck is drawn from.
     * @return array<string, mixed>
     *
     * @throws \RuntimeException When no community chest cards exist for the given game.
     */
    public function drawTopCard(int $gameId): array
    {
        return DB::transaction(function () use ($gameId): array {
            $pivot = DB::table('game_community_chest_cards')
                ->where('game_id', $gameId)
                ->whereNull('holder_join_order')
                ->orderBy('sort_order')
                ->lockForUpdate()
                ->first(['community_chest_card_id', 'sort_order']);

            if ($pivot === null) {
                throw new \RuntimeException("No community chest cards found for game {$gameId}");
            }

            $drawnCardId = $pivot->community_chest_card_id;

            DB::table('game_community_chest_cards')
                ->where('game_id', $gameId)
                ->whereNull('holder_join_order')
                ->where('sort_order', '>', 1)
                ->decrement('sort_order');

            DB::table('game_community_chest_cards')
                ->where('game_id', $gameId)
                ->where('community_chest_card_id', $drawnCardId)
                ->update(['sort_order' => 16, 'updated_at' => now()]);

            $card = CommunityChestCard::select([
                    'id', 'action', 'text', 'amount', 'house_cost', 'hotel_cost', 'target',
                ])
                ->where('id', $drawnCardId)
                ->first();

            Log::info('Community Chest card drawn', [
                'game_id' => $gameId,
                'card_id' => $drawnCardId,
                'action'  => $card->action->value,
            ]);

            return [
                'id'         => $card->id,
                'action'     => $card->action->value,
                'text'       => $card->text,
                'amount'     => $card->amount,
                'house_cost' => $card->house_cost,
                'hotel_cost' => $card->hotel_cost,
                'target'     => $card->target,
            ];
        });
    }

    /**
     * Move a specific Community Chest card to the bottom of the deck for a game.
     *
     * @param int $gameId
     * @param int $cardId
     * @return void
     */
    public function moveCardToBottom(int $gameId, int $cardId): void
    {
        DB::transaction(function () use ($gameId, $cardId): void {
            $pivot = DB::table('game_community_chest_cards')
                ->where('game_id', $gameId)
                ->where('community_chest_card_id', $cardId)
                ->lockForUpdate()
                ->first(['community_chest_card_id', 'sort_order', 'holder_join_order']);

            if ($pivot === null) {
                throw new \RuntimeException("Community Chest card {$cardId} not found for game {$gameId}");
            }

            DB::table('game_community_chest_cards')
                ->where('game_id', $gameId)
                ->whereNull('holder_join_order')
                ->where('sort_order', '>', $pivot->sort_order)
                ->decrement('sort_order');

            DB::table('game_community_chest_cards')
                ->where('game_id', $gameId)
                ->where('community_chest_card_id', $cardId)
                ->update(['sort_order' => 16, 'updated_at' => now(), 'holder_join_order' => null]);

            Log::info('Community Chest card moved to deck bottom (debug emulate)', [
                'game_id' => $gameId,
                'card_id' => $cardId,
            ]);
        });
    }
}
