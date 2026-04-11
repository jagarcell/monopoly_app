<?php

namespace Tests\Unit\Repositories;

use App\Enums\CommunityChestCardAction;
use App\Models\CommunityChestCard;
use App\Models\Game;
use App\Models\User;
use App\Repositories\CommunityChestCardRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityChestCardRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private CommunityChestCardRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new CommunityChestCardRepository();
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

        $this->assertSame(16, CommunityChestCard::where('game_id', $game->id)->count());
    }

    public function test_sort_order_is_a_permutation_of_1_to_16(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $orders = CommunityChestCard::where('game_id', $game->id)
            ->orderBy('sort_order')
            ->pluck('sort_order')
            ->all();

        $this->assertSame(range(1, 16), $orders);
    }

    public function test_all_required_actions_are_present_in_deck(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $actions = CommunityChestCard::where('game_id', $game->id)
            ->pluck('action')
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

    public function test_each_card_belongs_to_the_correct_game(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $foreignKeys = CommunityChestCard::where('game_id', $game->id)
            ->pluck('game_id')
            ->unique()
            ->all();

        $this->assertSame([$game->id], array_values($foreignKeys));
    }

    public function test_property_repairs_card_has_house_and_hotel_costs(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $card = CommunityChestCard::where('game_id', $game->id)
            ->where('action', CommunityChestCardAction::PropertyRepairs->value)
            ->first();

        $this->assertNotNull($card);
        $this->assertSame(40, $card->house_cost);
        $this->assertSame(115, $card->hotel_cost);
    }

    public function test_collect_from_each_player_cards_have_correct_amounts(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $amounts = CommunityChestCard::where('game_id', $game->id)
            ->where('action', CommunityChestCardAction::CollectFromEachPlayer->value)
            ->orderBy('amount')
            ->pluck('amount')
            ->all();

        $this->assertSame([10, 50], $amounts);
    }

    public function test_advance_to_card_targets_go(): void
    {
        $game = $this->makeGame();

        $this->repository->createDeckForGame($game->id);

        $card = CommunityChestCard::where('game_id', $game->id)
            ->where('action', CommunityChestCardAction::AdvanceTo->value)
            ->first();

        $this->assertNotNull($card);
        $this->assertSame('go', $card->target);
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

    public function test_decks_for_different_games_are_independent(): void
    {
        $gameA = $this->makeGame();
        $gameB = $this->makeGame();

        $this->repository->createDeckForGame($gameA->id);
        $this->repository->createDeckForGame($gameB->id);

        $this->assertSame(16, CommunityChestCard::where('game_id', $gameA->id)->count());
        $this->assertSame(16, CommunityChestCard::where('game_id', $gameB->id)->count());
        $this->assertSame(32, CommunityChestCard::count());
    }
}
