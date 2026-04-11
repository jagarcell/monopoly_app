<?php

namespace Tests\Unit\Repositories;

use App\Enums\CommunityChestCardAction;
use App\Models\CommunityChestCard;
use App\Models\Game;
use App\Models\User;
use App\Repositories\CommunityChestCardRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommunityChestCardRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private CommunityChestCardRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new CommunityChestCardRepository();
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
        $this->assertSame(16, CommunityChestCard::count());
    }

    public function test_seed_master_deck_is_idempotent(): void
    {
        // Calling again must not insert duplicates.
        $this->repository->seedMasterDeck();

        $this->assertSame(16, CommunityChestCard::count());
    }

    public function test_master_deck_contains_all_required_actions(): void
    {
        $actions = CommunityChestCard::pluck('action')
            ->map(fn (CommunityChestCardAction $a) => $a->value)
            ->sort()
            ->values()
            ->all();

        $expected = collect([
            CommunityChestCardAction::AdvanceTo->value,             // × 1
            CommunityChestCardAction::Collect->value,               // × 7
            CommunityChestCardAction::Collect->value,
            CommunityChestCardAction::Collect->value,
            CommunityChestCardAction::Collect->value,
            CommunityChestCardAction::Collect->value,
            CommunityChestCardAction::Collect->value,
            CommunityChestCardAction::Collect->value,
            CommunityChestCardAction::Pay->value,                   // × 3
            CommunityChestCardAction::Pay->value,
            CommunityChestCardAction::Pay->value,
            CommunityChestCardAction::GoToJail->value,              // × 1
            CommunityChestCardAction::GetOutOfJailFree->value,      // × 1
            CommunityChestCardAction::CollectFromEachPlayer->value, // × 2
            CommunityChestCardAction::CollectFromEachPlayer->value,
            CommunityChestCardAction::PropertyRepairs->value,       // × 1
        ])->sort()->values()->all();

        $this->assertSame($expected, $actions);
    }

    // ── createDeckForGame ─────────────────────────────────────────────────────

    public function test_deck_contains_exactly_16_pivot_rows(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $this->assertSame(
            16,
            DB::table('game_community_chest_cards')->where('game_id', $game->id)->count()
        );
    }

    public function test_sort_order_is_a_permutation_of_1_to_16(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $orders = DB::table('game_community_chest_cards')
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

        $masterIds = CommunityChestCard::orderBy('id')->pluck('id')->all();
        $pivotIds  = DB::table('game_community_chest_cards')
            ->where('game_id', $game->id)
            ->orderBy('community_chest_card_id')
            ->pluck('community_chest_card_id')
            ->all();

        $this->assertSame($masterIds, $pivotIds);
    }

    public function test_two_games_have_independently_shuffled_decks(): void
    {
        $gameA = $this->makeGame();
        $gameB = $this->makeGame();

        $this->repository->createDeckForGame($gameA->id);
        $this->repository->createDeckForGame($gameB->id);

        $this->assertSame(
            16,
            DB::table('game_community_chest_cards')->where('game_id', $gameA->id)->count()
        );
        $this->assertSame(
            16,
            DB::table('game_community_chest_cards')->where('game_id', $gameB->id)->count()
        );
        $this->assertSame(32, DB::table('game_community_chest_cards')->count());
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

    public function test_get_deck_for_game_returns_community_chest_card_instances(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $deck = $this->repository->getDeckForGame($game->id);

        $this->assertInstanceOf(CommunityChestCard::class, $deck->first());
    }

    public function test_property_repairs_card_has_correct_costs(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $card = $this->repository->getDeckForGame($game->id)
            ->first(fn (CommunityChestCard $c) => $c->action === CommunityChestCardAction::PropertyRepairs);

        $this->assertNotNull($card);
        $this->assertSame(40, $card->house_cost);
        $this->assertSame(115, $card->hotel_cost);
    }

    public function test_collect_from_each_player_cards_have_correct_amounts(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $amounts = $this->repository->getDeckForGame($game->id)
            ->filter(fn (CommunityChestCard $c) => $c->action === CommunityChestCardAction::CollectFromEachPlayer)
            ->pluck('amount')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([10, 50], $amounts);
    }

    public function test_advance_to_card_targets_go(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $card = $this->repository->getDeckForGame($game->id)
            ->first(fn (CommunityChestCard $c) => $c->action === CommunityChestCardAction::AdvanceTo);

        $this->assertNotNull($card);
        $this->assertSame('go', $card->target);
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

        $firstCardId = DB::table('game_community_chest_cards')
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->value('community_chest_card_id');

        $this->repository->drawTopCard($game->id);

        $newOrder = DB::table('game_community_chest_cards')
            ->where('game_id', $game->id)
            ->where('community_chest_card_id', $firstCardId)
            ->value('sort_order');

        $this->assertSame(16, $newOrder);
    }

    public function test_draw_top_card_keeps_sort_orders_as_permutation_of_1_to_16(): void
    {
        $game = $this->makeGame();
        $this->repository->createDeckForGame($game->id);

        $this->repository->drawTopCard($game->id);

        $orders = DB::table('game_community_chest_cards')
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

        $firstCardId = DB::table('game_community_chest_cards')
            ->where('game_id', $game->id)
            ->orderBy('sort_order')
            ->value('community_chest_card_id');

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

