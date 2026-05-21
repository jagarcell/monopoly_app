<?php

namespace Tests\Unit\Repositories;

use App\Enums\ChanceCardAction;
use App\Models\ChanceCard;
use App\Models\Game;
use App\Models\User;
use App\Repositories\ChanceCardRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChanceCardRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ChanceCardRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new ChanceCardRepository();
        $this->repository->seedMasterDeck();
    }

    /**
     * Create a game fixture owned by a seeded user.
     */
    private function makeGame(): Game
    {
        $user = User::factory()->create();
        return Game::factory()->create(['user_id' => $user->id]);
    }

    // ── seedMasterDeck ────────────────────────────────────────────────────────

    public function test_seed_master_deck_inserts_exactly_16_cards(): void
    {
        $this->assertSame(16, ChanceCard::count());
    }

    public function test_seed_master_deck_is_idempotent(): void
    {
        // Calling again must not insert duplicates.
        $this->repository->seedMasterDeck();

        $this->assertSame(16, ChanceCard::count());
    }

    public function test_master_deck_contains_all_required_actions(): void
    {
        $actions = ChanceCard::pluck('action')
            ->map(fn (ChanceCardAction $a) => $a->value)
            ->sort()
            ->values()
            ->all();

        $expected = collect([
            ChanceCardAction::AdvanceTo->value,         // × 4
            ChanceCardAction::AdvanceTo->value,
            ChanceCardAction::AdvanceTo->value,
            ChanceCardAction::AdvanceTo->value,
            ChanceCardAction::AdvanceToNearest->value,  // × 3
            ChanceCardAction::AdvanceToNearest->value,
            ChanceCardAction::AdvanceToNearest->value,
            ChanceCardAction::Collect->value,           // × 3
            ChanceCardAction::Collect->value,
            ChanceCardAction::Collect->value,
            ChanceCardAction::Pay->value,               // × 1
            ChanceCardAction::PayEachPlayer->value,     // × 1
            ChanceCardAction::MoveBack->value,          // × 1
            ChanceCardAction::GoToJail->value,          // × 1
            ChanceCardAction::GetOutOfJailFree->value,  // × 1
            ChanceCardAction::PropertyRepairs->value,   // × 1
        ])->sort()->values()->all();

        $this->assertSame($expected, $actions);
    }

    // ── createDeckForGame ─────────────────────────────────────────────────────

    public function test_deck_contains_exactly_16_pivot_rows(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $this->assertSame(16, DB::table('game_chance_cards')->where('game_id', $game->id)->count());
    }

    public function test_sort_order_is_a_permutation_of_1_to_16(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $orders = DB::table('game_chance_cards')
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->pluck('sort_order')
            ->all();

        $this->assertSame(range(1, 16), $orders);
    }

    public function test_pivot_references_all_master_card_ids(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $masterIds = ChanceCard::orderBy('id')->pluck('id')->all();
        $pivotIds  = DB::table('game_chance_cards')
            ->where('game_id', $game->id)
            ->orderBy('chance_card_id')
            ->pluck('chance_card_id')
            ->all();

        $this->assertSame($masterIds, $pivotIds);
    }

    public function test_two_games_have_independently_shuffled_decks(): void
    {
        $gameA = $this->makeGame();
        $gameB = $this->makeGame();

        $this->repository->createDeckForGame($gameA->id);
        $this->repository->createDeckForGame($gameB->id);

        $this->assertSame(16, DB::table('game_chance_cards')->where('game_id', $gameA->id)->count());
        $this->assertSame(16, DB::table('game_chance_cards')->where('game_id', $gameB->id)->count());
        $this->assertSame(32, DB::table('game_chance_cards')->count());
    }

    // ── getDeckForGame ────────────────────────────────────────────────────────

    public function test_get_deck_for_game_returns_ordered_collection(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $deck = $this->repository->getDeckForGame($game->id);

        $this->assertCount(16, $deck);
        $this->assertSame(range(1, 16), $deck->pluck('sort_order')->all());
    }

    public function test_get_deck_for_game_returns_chance_card_instances(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $deck = $this->repository->getDeckForGame($game->id);

        $this->assertInstanceOf(ChanceCard::class, $deck->first());
    }

    public function test_property_repairs_card_has_correct_costs(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $card = $this->repository->getDeckForGame($game->id)
            ->first(fn (ChanceCard $c) => $c->action === ChanceCardAction::PropertyRepairs);

        $this->assertNotNull($card);
        $this->assertSame(25, $card->house_cost);
        $this->assertSame(100, $card->hotel_cost);
    }

    public function test_move_back_card_has_spaces_set_to_3(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $card = $this->repository->getDeckForGame($game->id)
            ->first(fn (ChanceCard $c) => $c->action === ChanceCardAction::MoveBack);

        $this->assertNotNull($card);
        $this->assertSame(3, $card->spaces);
    }

    // ── held cards ───────────────────────────────────────────────────────────

    public function test_assign_card_to_player_sets_holder_join_order_on_pivot_row(): void
    {
        $game = $this->makeGame();
        $this->repository->createDeckForGame($game->id);

        $cardId = DB::table('game_chance_cards')
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->value('chance_card_id');

        $this->repository->assignCardToPlayer($game->id, (int) $cardId, 2);

        $holderJoinOrder = DB::table('game_chance_cards')
            ->where('game_id', $game->id)
            ->where('chance_card_id', $cardId)
            ->value('holder_join_order');

        $this->assertSame(2, $holderJoinOrder);
    }

    public function test_get_held_cards_for_game_groups_cards_by_holder_join_order(): void
    {
        $game = $this->makeGame();
        $this->repository->createDeckForGame($game->id);

        $cardIds = DB::table('game_chance_cards')
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->limit(2)
            ->pluck('chance_card_id')
            ->all();

        $this->repository->assignCardToPlayer($game->id, (int) $cardIds[0], 1);
        $this->repository->assignCardToPlayer($game->id, (int) $cardIds[1], 2);

        $heldCards = $this->repository->getHeldCardsForGame($game->id);

        $this->assertArrayHasKey(1, $heldCards);
        $this->assertArrayHasKey(2, $heldCards);
        $this->assertSame((int) $cardIds[0], $heldCards[1][0]['id']);
        $this->assertSame((int) $cardIds[1], $heldCards[2][0]['id']);
    }

    public function test_get_held_cards_for_game_excludes_unassigned_cards(): void
    {
        $game = $this->makeGame();
        $this->repository->createDeckForGame($game->id);

        $heldCards = $this->repository->getHeldCardsForGame($game->id);

        $this->assertSame([], $heldCards);
    }

    public function test_draw_top_card_skips_cards_held_by_a_player(): void
    {
        $game = $this->makeGame();
        $this->repository->createDeckForGame($game->id);

        $topCardId = DB::table('game_chance_cards')
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->value('chance_card_id');

        $secondCardId = DB::table('game_chance_cards')
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->offset(1)
            ->value('chance_card_id');

        $this->repository->assignCardToPlayer($game->id, (int) $topCardId, 2);

        $drawn = $this->repository->drawTopCard($game->id);

        $this->assertSame($secondCardId, $drawn['id']);
    }

    public function test_release_card_from_player_clears_holder_and_moves_card_to_bottom(): void
    {
        $game = $this->makeGame();
        $this->repository->createDeckForGame($game->id);

        $cardId = DB::table('game_chance_cards')
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->value('chance_card_id');

        $this->repository->assignCardToPlayer($game->id, (int) $cardId, 4);
        $this->repository->releaseHeldCardFromPlayer($game->id, 4);

        $pivotRow = DB::table('game_chance_cards')
            ->where('game_id', $game->id)
            ->where('chance_card_id', $cardId)
            ->first(['holder_join_order', 'sort_order']);

        $this->assertNull($pivotRow->holder_join_order);
        $this->assertSame(16, $pivotRow->sort_order);
    }

    // ── drawTopCard ───────────────────────────────────────────────────────────

    public function test_draw_top_card_returns_card_with_required_keys(): void
    {
        $game = $this->makeGame();
        $this->repository->createDeckForGame($game->id);

        $card = $this->repository->drawTopCard($game->id);

        $this->assertArrayHasKey('id', $card);
        $this->assertArrayHasKey('action', $card);
        $this->assertArrayHasKey('text', $card);
    }

    public function test_draw_top_card_moves_drawn_card_to_sort_order_16(): void
    {
        $game = $this->makeGame();
        $this->repository->createDeckForGame($game->id);

        $firstCardId = DB::table('game_chance_cards')
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->value('chance_card_id');

        $this->repository->drawTopCard($game->id);

        $newOrder = DB::table('game_chance_cards')
            ->where('game_id', $game->id)
            ->where('chance_card_id', $firstCardId)
            ->value('sort_order');

        $this->assertSame(16, $newOrder);
    }

    public function test_draw_top_card_keeps_sort_orders_as_permutation_of_1_to_16(): void
    {
        $game = $this->makeGame();
        $this->repository->createDeckForGame($game->id);

        $this->repository->drawTopCard($game->id);

        $orders = DB::table('game_chance_cards')
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->pluck('sort_order')
            ->all();

        $this->assertSame(range(1, 16), $orders);
    }

    public function test_draw_top_card_returns_card_at_sort_order_1(): void
    {
        $game = $this->makeGame();
        $this->repository->createDeckForGame($game->id);

        $firstCardId = DB::table('game_chance_cards')
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->value('chance_card_id');

        $drawn = $this->repository->drawTopCard($game->id);

        $this->assertSame($firstCardId, $drawn['id']);
    }

    public function test_full_cycle_of_16_draws_returns_same_sequence_twice(): void
    {
        $game = $this->makeGame();
        $this->repository->createDeckForGame($game->id);

        $firstCycle  = [];
        $secondCycle = [];

        for ($i = 0; $i < 16; $i++) {
            $firstCycle[] = $this->repository->drawTopCard($game->id)['id'];
        }

        for ($i = 0; $i < 16; $i++) {
            $secondCycle[] = $this->repository->drawTopCard($game->id)['id'];
        }

        $this->assertSame($firstCycle, $secondCycle);
    }
}

