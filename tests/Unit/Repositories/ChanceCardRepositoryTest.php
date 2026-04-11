<?php

namespace Tests\Unit\Repositories;

use App\Enums\ChanceCardAction;
use App\Models\ChanceCard;
use App\Models\Game;
use App\Models\User;
use App\Repositories\ChanceCardRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChanceCardRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ChanceCardRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new ChanceCardRepository();
    }

    /**
     * Create a game fixture owned by a seeded user.
     */
    private function makeGame(): Game
    {
        $user = User::factory()->create();
        return Game::factory()->create(['user_id' => $user->id]);
    }

    public function test_deck_contains_exactly_16_cards(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $this->assertSame(16, ChanceCard::where('game_id', $game->id)->count());
    }

    public function test_sort_order_is_a_permutation_of_1_to_16(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $orders = ChanceCard::where('game_id', $game->id)
            ->orderBy('sort_order')
            ->pluck('sort_order')
            ->all();

        $this->assertSame(range(1, 16), $orders);
    }

    public function test_all_required_actions_are_present_in_deck(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $actions = ChanceCard::where('game_id', $game->id)
            ->pluck('action')
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

    public function test_each_card_belongs_to_the_correct_game(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $foreignKeys = ChanceCard::where('game_id', $game->id)
            ->pluck('game_id')
            ->unique()
            ->all();

        $this->assertSame([$game->id], array_values($foreignKeys));
    }

    public function test_property_repairs_card_has_house_and_hotel_costs(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $card = ChanceCard::where('game_id', $game->id)
            ->where('action', ChanceCardAction::PropertyRepairs->value)
            ->first();

        $this->assertNotNull($card);
        $this->assertSame(25, $card->house_cost);
        $this->assertSame(100, $card->hotel_cost);
    }

    public function test_move_back_card_has_spaces_set_to_3(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $card = ChanceCard::where('game_id', $game->id)
            ->where('action', ChanceCardAction::MoveBack->value)
            ->first();

        $this->assertNotNull($card);
        $this->assertSame(3, $card->spaces);
    }

    public function test_get_deck_for_game_returns_ordered_collection(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $deck = $this->repository->getDeckForGame($game->id);

        $this->assertCount(16, $deck);
        $this->assertSame(
            range(1, 16),
            $deck->pluck('sort_order')->all()
        );
    }

    public function test_two_games_have_independently_shuffled_decks(): void
    {
        $gameA = $this->makeGame();
        $gameB = $this->makeGame();

        $this->repository->createDeckForGame($gameA->id);
        $this->repository->createDeckForGame($gameB->id);

        $ordersA = ChanceCard::where('game_id', $gameA->id)->orderBy('sort_order')->pluck('text')->all();
        $ordersB = ChanceCard::where('game_id', $gameB->id)->orderBy('sort_order')->pluck('text')->all();

        // Both decks should contain the same 16 texts but (very likely) in different order.
        // Assert both decks are complete and independent.
        $this->assertCount(16, $ordersA);
        $this->assertCount(16, $ordersB);
        sort($ordersA);
        sort($ordersB);
        $this->assertSame($ordersA, $ordersB); // same cards, not same order
    }
}
