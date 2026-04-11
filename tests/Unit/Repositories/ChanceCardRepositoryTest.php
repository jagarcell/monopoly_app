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
}

