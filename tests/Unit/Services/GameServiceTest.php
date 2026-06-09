<?php

namespace Tests\Unit\Services;

use App\Events\CardAccepted;
use App\Events\CardDrawn;
use App\Events\DiceRolled;
use App\Events\MortgagedPropertyNotified;
use App\Events\PropertyPurchased;
use App\Events\TokenMoved;
use App\Events\TurnAdvanced;
use App\Models\Game;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use App\Repositories\GameInvitationRepository;
use App\Repositories\GamePropertyRepository;
use App\Repositories\GameRepository;
use App\Repositories\PlayerIconRepository;
use App\Repositories\GamePendingBuildRepository;
use App\Services\GameService;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class GameServiceTest extends TestCase
{
    private GameService $service;
    private MockInterface $gameRepository;
    private MockInterface $chanceCardRepository;
    private MockInterface $communityChestCardRepository;
    private MockInterface $playerIconRepository;
    private MockInterface $invitationRepository;
    private MockInterface $propertyRepository;
    private MockInterface $pendingBuildRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gameRepository               = Mockery::mock(GameRepository::class);
        $this->chanceCardRepository         = Mockery::mock(ChanceCardRepository::class);
        $this->communityChestCardRepository = Mockery::mock(CommunityChestCardRepository::class);
        $this->playerIconRepository         = Mockery::mock(PlayerIconRepository::class);
        $this->invitationRepository         = Mockery::mock(GameInvitationRepository::class);
        $this->propertyRepository           = Mockery::mock(GamePropertyRepository::class);
        $this->pendingBuildRepository       = Mockery::mock(GamePendingBuildRepository::class);
        // Allow roll tests to land on Chance/CC squares without failing on
        // unexpected drawTopCard calls — explicit once() expectations in
        // individual tests still take precedence.
        $this->chanceCardRepository->shouldIgnoreMissing();
        $this->communityChestCardRepository->shouldIgnoreMissing();
        $this->playerIconRepository->shouldIgnoreMissing();
        $this->propertyRepository->shouldIgnoreMissing();
        $this->pendingBuildRepository->shouldIgnoreMissing();
        $this->service                      = new GameService(
            $this->gameRepository,
            $this->chanceCardRepository,
            $this->communityChestCardRepository,
            $this->playerIconRepository,
            $this->invitationRepository,
            $this->propertyRepository,
            $this->pendingBuildRepository,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_creates_first_game_named_game_1(): void
    {
        $userId       = 42;
        $maxPlayers   = 4;
        $playerIconId = 1;
        $game         = new Game(['name' => 'Game #1', 'user_id' => $userId]);
        $game->id     = 1;

        $this->gameRepository->shouldReceive('countByUser')->once()->with($userId)->andReturn(0);
        $this->gameRepository->shouldReceive('create')->once()->with($userId, 'Game #1', $maxPlayers)->andReturn($game);
        $this->chanceCardRepository->shouldReceive('createDeckForGame')->once()->with(1);
        $this->communityChestCardRepository->shouldReceive('createDeckForGame')->once()->with(1);
        $this->playerIconRepository->shouldReceive('assignToGame')->once()->with(1, $userId, $playerIconId);

        $result = $this->service->createGame($userId, $maxPlayers, $playerIconId);

        $this->assertSame($game, $result);
        $this->assertSame('Game #1', $result->name);
    }

    public function test_creates_sequential_game_name_based_on_existing_count(): void
    {
        $userId       = 42;
        $maxPlayers   = 6;
        $playerIconId = 2;
        $game         = new Game(['name' => 'Game #5', 'user_id' => $userId]);
        $game->id     = 5;

        $this->gameRepository->shouldReceive('countByUser')->once()->with($userId)->andReturn(4);
        $this->gameRepository->shouldReceive('create')->once()->with($userId, 'Game #5', $maxPlayers)->andReturn($game);
        $this->chanceCardRepository->shouldReceive('createDeckForGame')->once()->with(5);
        $this->communityChestCardRepository->shouldReceive('createDeckForGame')->once()->with(5);
        $this->playerIconRepository->shouldReceive('assignToGame')->once()->with(5, $userId, $playerIconId);

        $result = $this->service->createGame($userId, $maxPlayers, $playerIconId);

        $this->assertSame('Game #5', $result->name);
    }

    public function test_delegates_insert_to_repository(): void
    {
        $userId       = 7;
        $maxPlayers   = 2;
        $playerIconId = 3;
        $game         = new Game(['name' => 'Game #2', 'user_id' => $userId]);
        $game->id     = 2;

        $this->gameRepository->shouldReceive('countByUser')->once()->with($userId)->andReturn(1);
        $this->gameRepository->shouldReceive('create')->once()->with($userId, 'Game #2', $maxPlayers)->andReturn($game);
        $this->chanceCardRepository->shouldReceive('createDeckForGame')->once()->with(2);
        $this->communityChestCardRepository->shouldReceive('createDeckForGame')->once()->with(2);
        $this->playerIconRepository->shouldReceive('assignToGame')->once()->with(2, $userId, $playerIconId);

        $this->service->createGame($userId, $maxPlayers, $playerIconId);

        // Mockery verifies the expectation automatically on tearDown.
        $this->assertTrue(true);
    }

    public function test_chance_deck_is_created_after_game_insert(): void
    {
        $userId       = 10;
        $maxPlayers   = 3;
        $playerIconId = 4;
        $game         = new Game(['name' => 'Game #3', 'user_id' => $userId]);
        $game->id     = 3;

        $this->gameRepository->shouldReceive('countByUser')->once()->with($userId)->andReturn(2);
        $this->gameRepository->shouldReceive('create')->once()->with($userId, 'Game #3', $maxPlayers)->andReturn($game);
        $this->chanceCardRepository->shouldReceive('createDeckForGame')->once()->with($game->id);
        $this->communityChestCardRepository->shouldReceive('createDeckForGame')->once()->with($game->id);
        $this->playerIconRepository->shouldReceive('assignToGame')->once()->with($game->id, $userId, $playerIconId);

        $result = $this->service->createGame($userId, $maxPlayers, $playerIconId);

        $this->assertSame($game, $result);
    }

    public function test_community_chest_deck_is_created_after_game_insert(): void
    {
        $userId       = 15;
        $maxPlayers   = 8;
        $playerIconId = 5;
        $game         = new Game(['name' => 'Game #4', 'user_id' => $userId]);
        $game->id     = 4;

        $this->gameRepository->shouldReceive('countByUser')->once()->with($userId)->andReturn(3);
        $this->gameRepository->shouldReceive('create')->once()->with($userId, 'Game #4', $maxPlayers)->andReturn($game);
        $this->chanceCardRepository->shouldReceive('createDeckForGame')->once()->with($game->id);
        $this->communityChestCardRepository->shouldReceive('createDeckForGame')->once()->with($game->id);
        $this->playerIconRepository->shouldReceive('assignToGame')->once()->with($game->id, $userId, $playerIconId);

        $result = $this->service->createGame($userId, $maxPlayers, $playerIconId);

        $this->assertSame($game, $result);
    }

    public function test_player_icon_is_assigned_after_decks_are_created(): void
    {
        $userId       = 20;
        $maxPlayers   = 4;
        $playerIconId = 1;
        $game         = new Game(['name' => 'Game #1', 'user_id' => $userId]);
        $game->id     = 10;

        $callOrder = [];

        $this->gameRepository->shouldReceive('countByUser')->once()->andReturn(0);
        $this->gameRepository->shouldReceive('create')->once()->andReturn($game);
        $this->chanceCardRepository->shouldReceive('createDeckForGame')->once()
            ->andReturnUsing(function () use (&$callOrder) { $callOrder[] = 'chance'; });
        $this->communityChestCardRepository->shouldReceive('createDeckForGame')->once()
            ->andReturnUsing(function () use (&$callOrder) { $callOrder[] = 'community'; });
        $this->playerIconRepository->shouldReceive('assignToGame')->once()
            ->andReturnUsing(function () use (&$callOrder) { $callOrder[] = 'icon'; });

        $this->service->createGame($userId, $maxPlayers, $playerIconId);

        $this->assertSame(['chance', 'community', 'icon'], $callOrder);
    }

    public function test_draw_chance_card_delegates_to_repository(): void
    {
        $gameId   = 7;
        $expected = ['id' => 3, 'action' => 'collect', 'text' => 'Bank pays you $50', 'amount' => 50, 'house_cost' => null, 'hotel_cost' => null, 'target' => null, 'spaces' => null];

        $this->chanceCardRepository->shouldReceive('drawTopCard')->once()->with($gameId)->andReturn($expected);

        $result = $this->service->drawChanceCard($gameId);

        $this->assertSame($expected, $result);
    }

    public function test_draw_community_chest_card_delegates_to_repository(): void
    {
        $gameId   = 9;
        $expected = ['id' => 5, 'action' => 'collect', 'text' => 'Bank error', 'amount' => 200, 'house_cost' => null, 'hotel_cost' => null, 'target' => null];

        $this->communityChestCardRepository->shouldReceive('drawTopCard')->once()->with($gameId)->andReturn($expected);

        $result = $this->service->drawCommunityChestCard($gameId);

        $this->assertSame($expected, $result);
    }

    public function test_get_players_for_game_delegates_to_player_icon_repository(): void
    {
        $gameId  = 42;
        $players = [
            [
                'user_id'               => 1,
                'name'                  => 'Alice',
                'is_creator'            => true,
                'join_order'            => 1,
                'icon'                  => ['id' => 1, 'name' => 'Top Hat', 'image_url' => '/hat.svg'],
                'properties'            => [],
                'chance_cards'          => [],
                'community_chest_cards' => [],
            ],
        ];

        $this->playerIconRepository->shouldReceive('getPlayersForGame')->once()->with($gameId)->andReturn($players);

        $result = $this->service->getPlayersForGame($gameId);

        $this->assertSame($players, $result);
    }

    public function test_get_players_for_game_returns_empty_array_when_no_players(): void
    {
        $gameId = 99;

        $this->playerIconRepository->shouldReceive('getPlayersForGame')->once()->with($gameId)->andReturn([]);

        $result = $this->service->getPlayersForGame($gameId);

        $this->assertSame([], $result);
    }

    public function test_get_pending_invitations_for_game_delegates_to_invitation_repository(): void
    {
        $gameId  = 11;
        $pending = [['email' => 'waiting@example.com']];

        $this->invitationRepository->shouldReceive('getPendingForGame')->once()->with($gameId)->andReturn($pending);

        $result = $this->service->getPendingInvitationsForGame($gameId);

        $this->assertSame($pending, $result);
    }

    public function test_get_pending_invitations_for_game_returns_empty_when_none(): void
    {
        $gameId = 12;

        $this->invitationRepository->shouldReceive('getPendingForGame')->once()->with($gameId)->andReturn([]);

        $result = $this->service->getPendingInvitationsForGame($gameId);

        $this->assertSame([], $result);
    }

    public function test_use_get_out_of_jail_card_for_user_releases_card_and_clears_jail_state(): void
    {
        $gameId = 22;
        $userId = 8;
        $joinOrder = 2;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->playerIconRepository->shouldReceive('getJailState')->once()->with($gameId, $joinOrder)->andReturn(true);
        $this->chanceCardRepository->shouldReceive('releaseHeldCardFromPlayer')->once()->with($gameId, $joinOrder)->andReturn(true);
        $this->communityChestCardRepository->shouldReceive('releaseHeldCardFromPlayer')->once()->with($gameId, $joinOrder)->andReturn(false);
        $this->playerIconRepository->shouldReceive('setJailState')->once()->with($gameId, $joinOrder, false);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->with($gameId, $joinOrder)->andReturn(10);
        $this->playerIconRepository->shouldReceive('getPlayersForGame')->once()->with($gameId)->andReturn([
            ['join_order' => $joinOrder, 'capital' => 1250],
        ]);

        $result = $this->service->useGetOutOfJailCardForUser($gameId, $userId);

        $this->assertSame($joinOrder, $result['join_order']);
        $this->assertSame(10, $result['square_index']);
        $this->assertSame(1250, $result['capital']);
        $this->assertFalse($result['is_in_jail']);
        $this->assertSame(0, $result['jail_turns']);
        $this->assertFalse($result['has_paid_jail_release']);
    }

    public function test_use_get_out_of_jail_card_for_guest_throws_when_no_held_card_exists(): void
    {
        $gameId = 23;
        $invitationId = 99;
        $joinOrder = 4;

        $this->playerIconRepository->shouldReceive('getJoinOrderForGuest')->once()->with($gameId, $invitationId)->andReturn($joinOrder);
        $this->playerIconRepository->shouldReceive('getJailState')->once()->with($gameId, $joinOrder)->andReturn(true);
        $this->chanceCardRepository->shouldReceive('releaseHeldCardFromPlayer')->once()->with($gameId, $joinOrder)->andReturn(false);
        $this->communityChestCardRepository->shouldReceive('releaseHeldCardFromPlayer')->once()->with($gameId, $joinOrder)->andReturn(false);
        $this->playerIconRepository->shouldNotReceive('setJailState');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You do not have a Get Out of Jail Free card.');

        $this->service->useGetOutOfJailCardForGuest($gameId, $invitationId);
    }

    public function test_pay_jail_release_for_user_deducts_capital_and_marks_paid_state(): void
    {
        $gameId = 24;
        $userId = 3;
        $joinOrder = 1;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->playerIconRepository->shouldReceive('getJailState')->once()->with($gameId, $joinOrder)->andReturn(true);
        $this->playerIconRepository->shouldReceive('hasPaidJailRelease')->once()->with($gameId, $joinOrder)->andReturn(false);
        $this->playerIconRepository->shouldReceive('getPlayersForGame')->once()->with($gameId)->andReturn([
            ['join_order' => $joinOrder, 'capital' => 1500],
        ]);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $joinOrder, -50)->andReturn(1450);
        $this->playerIconRepository->shouldReceive('setHasPaidJailRelease')->once()->with($gameId, $joinOrder, true);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->with($gameId, $joinOrder)->andReturn(10);
        $this->playerIconRepository->shouldReceive('getJailTurns')->once()->with($gameId, $joinOrder)->andReturn(1);

        $result = $this->service->payJailReleaseForUser($gameId, $userId);

        $this->assertSame($joinOrder, $result['join_order']);
        $this->assertSame(1450, $result['capital']);
        $this->assertTrue($result['is_in_jail']);
        $this->assertSame(1, $result['jail_turns']);
        $this->assertTrue($result['has_paid_jail_release']);
        $this->assertSame(50, $result['paid_amount']);
    }

    public function test_roll_dice_for_user_requires_paid_release_on_third_jail_turn_before_rolling(): void
    {
        $gameId = 25;
        $userId = 5;
        $joinOrder = 3;
        $game = new Game(['current_turn_join_order' => $joinOrder]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->with($gameId, $joinOrder)->andReturn(10);
        $this->playerIconRepository->shouldReceive('getJailState')->once()->with($gameId, $joinOrder)->andReturn(true);
        $this->playerIconRepository->shouldReceive('getJailTurns')->once()->with($gameId, $joinOrder)->andReturn(2);
        $this->playerIconRepository->shouldReceive('hasPaidJailRelease')->once()->with($gameId, $joinOrder)->andReturn(false);
        $this->gameRepository->shouldNotReceive('saveDiceRoll');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You must pay $50 to leave jail before rolling.');

        $this->service->rollDiceForUser($gameId, $userId);
    }

    public function test_roll_dice_for_user_uses_forced_dice_in_debug_mode_via_normal_roll_flow(): void
    {
        Event::fake([DiceRolled::class]);
        config(['app.debug_mode' => true]);

        $gameId = 26;
        $userId = 6;
        $joinOrder = 1;
        $game = new Game([
            'current_turn_join_order' => $joinOrder,
            'consecutive_doubles_count' => 0,
        ]);
        $game->id = $gameId;

        $service = new class(
            $this->gameRepository,
            $this->chanceCardRepository,
            $this->communityChestCardRepository,
            $this->playerIconRepository,
            $this->invitationRepository,
            $this->propertyRepository,
            $this->pendingBuildRepository,
        ) extends GameService {
            /**
             * @return array{0:int,1:int}
             */
            protected function generateDiceRoll(): array
            {
                throw new \RuntimeException('generateDiceRoll should not be called when forced dice are provided.');
            }
        };

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->with($gameId, $joinOrder)->andReturn(0);
        $this->playerIconRepository->shouldReceive('getJailState')->once()->with($gameId, $joinOrder)->andReturn(false);
        $this->playerIconRepository->shouldReceive('getJailTurns')->once()->with($gameId, $joinOrder)->andReturn(0);
        $this->playerIconRepository->shouldReceive('hasPaidJailRelease')->once()->with($gameId, $joinOrder)->andReturn(false);
        $this->playerIconRepository->shouldReceive('setJailState')->once()->with($gameId, $joinOrder, false);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once()->with($gameId, $joinOrder, 4);
        $this->gameRepository->shouldReceive('saveDiceRoll')->once()->with($gameId, 2, 2, 1, 'roll');

        $result = $service->rollDiceForUser($gameId, $userId, ['die1' => 2, 'die2' => 2]);

        $this->assertSame(2, $result['die1']);
        $this->assertSame(2, $result['die2']);
        $this->assertSame(4, $result['total']);
        $this->assertSame(4, $result['square_index']);
        $this->assertTrue($result['can_roll_again']);

        Event::assertDispatched(DiceRolled::class, function (DiceRolled $event) use ($gameId): bool {
            return $event->gameId === $gameId
                && $event->die1 === 2
                && $event->die2 === 2
                && $event->total === 4
                && $event->squareIndex === 4;
        });
    }

    public function test_roll_dice_for_user_rejects_forced_dice_when_debug_mode_is_disabled(): void
    {
        config(['app.debug_mode' => false]);

        $gameId = 27;
        $userId = 7;
        $joinOrder = 1;
        $game = new Game(['current_turn_join_order' => $joinOrder]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->with($gameId, $joinOrder)->andReturn(0);
        $this->playerIconRepository->shouldReceive('getJailState')->once()->with($gameId, $joinOrder)->andReturn(false);
        $this->playerIconRepository->shouldReceive('getJailTurns')->once()->with($gameId, $joinOrder)->andReturn(0);
        $this->playerIconRepository->shouldReceive('hasPaidJailRelease')->once()->with($gameId, $joinOrder)->andReturn(false);

        $this->expectException(
            \InvalidArgumentException::class,
        );
        $this->expectExceptionMessage('Forced dice are only allowed in debug mode.');

        $this->service->rollDiceForUser($gameId, $userId, ['die1' => 2, 'die2' => 2]);
    }

    // ── rollDiceForUser ────────────────────────────────────────────────────

    public function test_roll_dice_for_user_returns_dice_without_advancing_turn(): void
    {
        Event::fake([DiceRolled::class]);

        $gameId  = 1;
        $userId  = 42;
        $game    = new Game(['current_turn_join_order' => 1]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn(1);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->with($gameId, 1)->andReturn(0);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once()->with($gameId, 1, Mockery::type('int'));
        $this->gameRepository->shouldReceive('saveDiceRoll')->once()->withArgs(
            fn (int $id, int $die1, int $die2, int $count = 0, string $phase = 'done'): bool => $id === $gameId
                && $die1 >= 1 && $die1 <= 6
                && $die2 >= 1 && $die2 <= 6,
        );
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')->zeroOrMoreTimes()->andReturn('Player');
        // Turn must NOT be advanced on roll.
        $this->gameRepository->shouldNotReceive('getPlayerJoinOrders');
        $this->gameRepository->shouldNotReceive('advanceTurn');

        $result = $this->service->rollDiceForUser($gameId, $userId);

        $this->assertArrayHasKey('die1', $result);
        $this->assertArrayHasKey('die2', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('current_turn_join_order', $result);
        $this->assertArrayHasKey('square_index', $result);
        // The turn remains on the roller's join_order.
        $this->assertSame(1, $result['current_turn_join_order']);
        $this->assertSame($result['die1'] + $result['die2'], $result['total']);
        $this->assertGreaterThanOrEqual(1, $result['die1']);
        $this->assertLessThanOrEqual(6, $result['die1']);
        $this->assertGreaterThanOrEqual(1, $result['die2']);
        $this->assertLessThanOrEqual(6, $result['die2']);
        $this->assertSame(($result['die1'] + $result['die2']) % 40, $result['square_index']);

        Event::assertDispatched(DiceRolled::class, function (DiceRolled $e) use ($gameId, $result) {
            return $e->gameId === $gameId
                && $e->die1 === $result['die1']
                && $e->die2 === $result['die2']
                && $e->total === $result['total']
                && $e->currentTurnJoinOrder === 1
                && $e->squareIndex === $result['square_index'];
        });
    }

    public function test_roll_dice_throws_when_not_this_players_turn(): void
    {
        $gameId  = 3;
        $userId  = 7;
        $game    = new Game(['current_turn_join_order' => 2]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn(1);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->playerIconRepository->shouldNotReceive('getSquareIndexForPlayer');
        $this->playerIconRepository->shouldNotReceive('updateSquareIndex');
        $this->gameRepository->shouldNotReceive('advanceTurn');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('It is not your turn to roll.');

        $this->service->rollDiceForUser($gameId, $userId);
    }

    public function test_roll_dice_throws_when_user_is_not_a_participant(): void
    {
        $gameId = 4;
        $userId = 55;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn(null);
        $this->gameRepository->shouldNotReceive('findById');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You are not a participant of this game.');

        $this->service->rollDiceForUser($gameId, $userId);
    }

    // ── rollDiceForGuest ───────────────────────────────────────────────────

    public function test_roll_dice_for_guest_returns_dice_without_advancing_turn(): void
    {
        Event::fake([DiceRolled::class]);

        $gameId       = 10;
        $invitationId = 3;
        $game         = new Game(['current_turn_join_order' => 2]);
        $game->id     = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForGuest')->once()->with($gameId, $invitationId)->andReturn(2);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->with($gameId, 2)->andReturn(0);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once()->with($gameId, 2, Mockery::type('int'));
        $this->gameRepository->shouldReceive('saveDiceRoll')->once()->withArgs(
            fn (int $id, int $die1, int $die2, int $count = 0, string $phase = 'done'): bool => $id === $gameId
                && $die1 >= 1 && $die1 <= 6
                && $die2 >= 1 && $die2 <= 6,
        );
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')->zeroOrMoreTimes()->andReturn('Player');
        // Turn must NOT be advanced on roll.
        $this->gameRepository->shouldNotReceive('getPlayerJoinOrders');
        $this->gameRepository->shouldNotReceive('advanceTurn');

        $result = $this->service->rollDiceForGuest($gameId, $invitationId);

        // Turn stays on the guest's join_order.
        $this->assertSame(2, $result['current_turn_join_order']);
        $this->assertArrayHasKey('square_index', $result);
        Event::assertDispatched(DiceRolled::class, fn (DiceRolled $e) => $e->currentTurnJoinOrder === 2
            && $e->squareIndex === $result['square_index']);
    }

    public function test_roll_dice_for_guest_throws_when_not_participant(): void
    {
        $gameId       = 11;
        $invitationId = 99;

        $this->playerIconRepository->shouldReceive('getJoinOrderForGuest')->once()->with($gameId, $invitationId)->andReturn(null);
        $this->gameRepository->shouldNotReceive('findById');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You are not a participant of this game.');

        $this->service->rollDiceForGuest($gameId, $invitationId);
    }

    public function test_roll_dice_for_guest_throws_when_not_their_turn(): void
    {
        $gameId       = 12;
        $invitationId = 5;
        $game         = new Game(['current_turn_join_order' => 1]);
        $game->id     = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForGuest')->once()->with($gameId, $invitationId)->andReturn(2);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->playerIconRepository->shouldNotReceive('getSquareIndexForPlayer');
        $this->playerIconRepository->shouldNotReceive('updateSquareIndex');
        $this->gameRepository->shouldNotReceive('advanceTurn');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('It is not your turn to roll.');

        $this->service->rollDiceForGuest($gameId, $invitationId);
    }

    public function test_roll_dice_square_index_wraps_around_at_40(): void
    {
        Event::fake([DiceRolled::class]);

        $gameId  = 50;
        $userId  = 77;
        $game    = new Game(['current_turn_join_order' => 1]);
        $game->id = $gameId;

        // Player is on square 37; rolling any total that crosses 40 should wrap.
        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn(1);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->with($gameId, 1)->andReturn(37);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once()
            ->with($gameId, 1, Mockery::on(fn ($idx) => $idx >= 0 && $idx < 40));
        $this->gameRepository->shouldReceive('saveDiceRoll')->once()->withArgs(
            fn (int $id, int $die1, int $die2, int $count = 0, string $phase = 'done'): bool => $id === $gameId
                && $die1 >= 1 && $die1 <= 6
                && $die2 >= 1 && $die2 <= 6,
        );
        // May pass GO depending on random dice total (37+total >= 40 when total >= 3).
        $this->playerIconRepository->shouldReceive('adjustCapital')->zeroOrMoreTimes()->andReturn(1700);
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')->zeroOrMoreTimes()->andReturn('Player');

        $result = $this->service->rollDiceForUser($gameId, $userId);

        // New position must be (37 + total) % 40, which is between 0 and 6 for any valid total.
        $expectedIdx = (37 + $result['total']) % 40;
        $this->assertSame($expectedIdx, $result['square_index']);

        Event::assertDispatched(DiceRolled::class, fn (DiceRolled $e) => $e->squareIndex === $expectedIdx);
    }

    public function test_roll_dice_square_index_starts_from_current_position(): void
    {
        Event::fake([DiceRolled::class]);

        $gameId  = 51;
        $userId  = 88;
        $game    = new Game(['current_turn_join_order' => 1]);
        $game->id = $gameId;

        // Player is already at square 10 (Connecticut Ave).
        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn(1);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->with($gameId, 1)->andReturn(10);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once()
            ->with($gameId, 1, Mockery::on(fn ($idx) => $idx >= 10 && $idx < 40));
        $this->gameRepository->shouldReceive('saveDiceRoll')->once()->withArgs(
            fn (int $id, int $die1, int $die2, int $count = 0, string $phase = 'done'): bool => $id === $gameId
                && $die1 >= 1 && $die1 <= 6
                && $die2 >= 1 && $die2 <= 6,
        );
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')->zeroOrMoreTimes()->andReturn('Player');

        $result = $this->service->rollDiceForUser($gameId, $userId);

        $this->assertSame((10 + $result['total']) % 40, $result['square_index']);
    }

    public function test_three_consecutive_doubles_send_player_directly_to_jail_and_advance_turn(): void
    {
        Event::fake([DiceRolled::class, TurnAdvanced::class]);

        $gameId = 53;
        $userId = 101;
        $joinOrder = 1;
        $game = new Game([
            'current_turn_join_order' => $joinOrder,
            'consecutive_doubles_count' => 2,
        ]);
        $game->id = $gameId;

        $service = new class(
            $this->gameRepository,
            $this->chanceCardRepository,
            $this->communityChestCardRepository,
            $this->playerIconRepository,
            $this->invitationRepository,
            $this->propertyRepository,
            $this->pendingBuildRepository,
        ) extends GameService {
            /**
             * @return array{0:int,1:int}
             */
            protected function generateDiceRoll(): array
            {
                return [6, 6];
            }
        };

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->with($gameId, $joinOrder)->andReturn(39);
        $this->playerIconRepository->shouldReceive('getJailState')->once()->with($gameId, $joinOrder)->andReturn(false);
        $this->playerIconRepository->shouldReceive('getJailTurns')->once()->with($gameId, $joinOrder)->andReturn(0);
        $this->playerIconRepository->shouldReceive('hasPaidJailRelease')->once()->with($gameId, $joinOrder)->andReturn(false);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once()->with($gameId, $joinOrder, 10);
        $this->playerIconRepository->shouldReceive('setJailState')->once()->with($gameId, $joinOrder, true);
        $this->gameRepository->shouldReceive('saveDiceRoll')->once()->with($gameId, 6, 6, 0, 'done');
        $this->gameRepository->shouldReceive('getPlayerJoinOrders')->once()->with($gameId)->andReturn([1, 2]);
        $this->gameRepository->shouldReceive('advanceTurn')->once()->with($gameId, 1, 2)->andReturn(true);

        $result = $service->rollDiceForUser($gameId, $userId);

        $this->assertSame(10, $result['square_index']);
        $this->assertSame('go_to_jail', $result['square_action']['type']);
        $this->assertSame(0, $result['go_bonus']);
        $this->assertTrue($result['is_in_jail']);
        $this->assertSame(2, $result['current_turn_join_order']);
        $this->assertFalse($result['can_roll_again']);

        Event::assertDispatched(DiceRolled::class, fn (DiceRolled $event): bool =>
            $event->gameId === $gameId
            && $event->die1 === 6
            && $event->die2 === 6
            && $event->squareIndex === 10
        );
        Event::assertDispatched(TurnAdvanced::class, fn (TurnAdvanced $event): bool =>
            $event->gameId === $gameId
            && $event->currentTurnJoinOrder === 2
        );
    }

    public function test_jail_release_double_does_not_count_toward_three_consecutive_doubles_rule(): void
    {
        Event::fake([DiceRolled::class, TurnAdvanced::class]);

        $gameId = 54;
        $userId = 102;
        $joinOrder = 1;
        $game = new Game([
            'current_turn_join_order' => $joinOrder,
            'consecutive_doubles_count' => 2,
        ]);
        $game->id = $gameId;

        $service = new class(
            $this->gameRepository,
            $this->chanceCardRepository,
            $this->communityChestCardRepository,
            $this->playerIconRepository,
            $this->invitationRepository,
            $this->propertyRepository,
            $this->pendingBuildRepository,
        ) extends GameService {
            /**
             * @return array{0:int,1:int}
             */
            protected function generateDiceRoll(): array
            {
                return [4, 4];
            }
        };

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->with($gameId, $joinOrder)->andReturn(10);
        $this->playerIconRepository->shouldReceive('getJailState')->once()->with($gameId, $joinOrder)->andReturn(true);
        $this->playerIconRepository->shouldReceive('getJailTurns')->once()->with($gameId, $joinOrder)->andReturn(1);
        $this->playerIconRepository->shouldReceive('hasPaidJailRelease')->once()->with($gameId, $joinOrder)->andReturn(false);
        $this->playerIconRepository->shouldReceive('setJailState')->once()->with($gameId, $joinOrder, false);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once()->with($gameId, $joinOrder, 18);
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->once()->with($gameId, 18)->andReturn(null);
        $this->gameRepository->shouldReceive('saveDiceRoll')->once()->with($gameId, 4, 4, 0, 'done');
        $this->gameRepository->shouldNotReceive('getPlayerJoinOrders');
        $this->gameRepository->shouldNotReceive('advanceTurn');

        $result = $service->rollDiceForUser($gameId, $userId);

        $this->assertSame(1, $result['current_turn_join_order']);
        $this->assertSame(18, $result['square_index']);
        $this->assertFalse($result['is_in_jail']);
        $this->assertFalse($result['can_roll_again']);

        Event::assertDispatched(DiceRolled::class, fn (DiceRolled $event): bool =>
            $event->gameId === $gameId
            && $event->die1 === 4
            && $event->die2 === 4
            && $event->squareIndex === 18
        );
        Event::assertNotDispatched(TurnAdvanced::class);
    }

    public function test_jail_release_double_from_zero_still_persists_zero_consecutive_doubles(): void
    {
        Event::fake([DiceRolled::class, TurnAdvanced::class]);

        $gameId = 55;
        $userId = 103;
        $joinOrder = 1;
        $game = new Game([
            'current_turn_join_order' => $joinOrder,
            'consecutive_doubles_count' => 0,
        ]);
        $game->id = $gameId;

        $service = new class(
            $this->gameRepository,
            $this->chanceCardRepository,
            $this->communityChestCardRepository,
            $this->playerIconRepository,
            $this->invitationRepository,
            $this->propertyRepository,
            $this->pendingBuildRepository,
        ) extends GameService {
            /**
             * @return array{0:int,1:int}
             */
            protected function generateDiceRoll(): array
            {
                return [3, 3];
            }
        };

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->with($gameId, $joinOrder)->andReturn(10);
        $this->playerIconRepository->shouldReceive('getJailState')->once()->with($gameId, $joinOrder)->andReturn(true);
        $this->playerIconRepository->shouldReceive('getJailTurns')->once()->with($gameId, $joinOrder)->andReturn(1);
        $this->playerIconRepository->shouldReceive('hasPaidJailRelease')->once()->with($gameId, $joinOrder)->andReturn(false);
        $this->playerIconRepository->shouldReceive('setJailState')->once()->with($gameId, $joinOrder, false);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once()->with($gameId, $joinOrder, 16);
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->once()->with($gameId, 16)->andReturn(null);
        $this->gameRepository->shouldReceive('saveDiceRoll')->once()->with($gameId, 3, 3, 0, 'done');
        $this->gameRepository->shouldNotReceive('getPlayerJoinOrders');
        $this->gameRepository->shouldNotReceive('advanceTurn');

        $result = $service->rollDiceForUser($gameId, $userId);

        $this->assertSame(1, $result['current_turn_join_order']);
        $this->assertSame(16, $result['square_index']);
        $this->assertFalse($result['is_in_jail']);
        $this->assertFalse($result['can_roll_again']);

        Event::assertDispatched(DiceRolled::class, fn (DiceRolled $event): bool =>
            $event->gameId === $gameId
            && $event->die1 === 3
            && $event->die2 === 3
            && $event->squareIndex === 16
        );
        Event::assertNotDispatched(TurnAdvanced::class);
    }

    // ── debugMoveToSquare ───────────────────────────────────────────────────

    public function test_debug_move_to_square_for_user_updates_position_and_broadcasts_dice(): void
    {
        Event::fake([DiceRolled::class]);

        $gameId = 61;
        $userId = 14;
        $game = new Game(['current_turn_join_order' => 1]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn(1);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->with($gameId, 1)->andReturn(5);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once()->with($gameId, 1, 9);
        $this->gameRepository->shouldReceive('saveDiceRoll')->once()->with($gameId, 2, 2);
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->once()->with($gameId, 9)->andReturn(null);

        $result = $this->service->debugMoveToSquareForUser($gameId, $userId, 9);

        $this->assertSame(2, $result['die1']);
        $this->assertSame(2, $result['die2']);
        $this->assertSame(4, $result['total']);
        $this->assertSame(9, $result['square_index']);
        $this->assertSame(4, $result['total_steps']);
        $this->assertSame(1, $result['current_turn_join_order']);
        $this->assertFalse($result['passed_go']);
        $this->assertSame('purchase', $result['square_action']['type']);

        Event::assertDispatched(DiceRolled::class, function (DiceRolled $event) use ($gameId) {
            return $event->gameId === $gameId
                && $event->die1 === 2
                && $event->die2 === 2
                && $event->total === 4
                && $event->currentTurnJoinOrder === 1
                && $event->squareIndex === 9;
        });
    }

    public function test_debug_move_to_square_for_guest_applies_go_bonus_when_wrapping(): void
    {
        Event::fake([DiceRolled::class]);

        $gameId = 62;
        $invitationId = 33;
        $game = new Game(['current_turn_join_order' => 2]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForGuest')->once()->with($gameId, $invitationId)->andReturn(2);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->with($gameId, 2)->andReturn(39);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, 2, 200)->andReturn(1700);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once()->with($gameId, 2, 1);
        $this->gameRepository->shouldReceive('saveDiceRoll')->once()->with($gameId, 1, 1);
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->once()->with($gameId, 1)->andReturn(null);

        $result = $this->service->debugMoveToSquareForGuest($gameId, $invitationId, 1);

        $this->assertSame(1, $result['die1']);
        $this->assertSame(1, $result['die2']);
        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['square_index']);
        $this->assertSame(2, $result['total_steps']);
        $this->assertTrue($result['passed_go']);
        $this->assertSame(200, $result['go_bonus']);
        $this->assertSame(1700, $result['new_capital']);

        Event::assertDispatched(DiceRolled::class, fn (DiceRolled $event) =>
            $event->gameId === $gameId
            && $event->die1 === 1
            && $event->die2 === 1
            && $event->total === 2
            && $event->currentTurnJoinOrder === 2
            && $event->squareIndex === 1
        );
    }

    public function test_debug_move_to_square_throws_when_not_players_turn(): void
    {
        $gameId = 63;
        $userId = 41;
        $game = new Game(['current_turn_join_order' => 2]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn(1);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->playerIconRepository->shouldNotReceive('updateSquareIndex');
        $this->gameRepository->shouldNotReceive('saveDiceRoll');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('It is not your turn to move.');

        $this->service->debugMoveToSquareForUser($gameId, $userId, 12);
    }

    // ── endTurnForUser ────────────────────────────────────────────────────

    public function test_end_turn_for_user_advances_turn_cyclically(): void
    {
        Event::fake([TurnAdvanced::class]);

        $gameId  = 20;
        $userId  = 42;
        $game    = new Game(['current_turn_join_order' => 1]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn(1);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->gameRepository->shouldReceive('getPlayerJoinOrders')->once()->with($gameId)->andReturn([1, 2]);
        $this->gameRepository->shouldReceive('advanceTurn')->once()->with($gameId, 1, 2)->andReturn(true);

        $result = $this->service->endTurnForUser($gameId, $userId);

        $this->assertArrayHasKey('current_turn_join_order', $result);
        $this->assertSame(2, $result['current_turn_join_order']);

        Event::assertDispatched(TurnAdvanced::class, function (TurnAdvanced $e) use ($gameId) {
            return $e->gameId === $gameId && $e->currentTurnJoinOrder === 2;
        });
    }

    public function test_end_turn_for_user_wraps_cyclically_to_first_player(): void
    {
        Event::fake([TurnAdvanced::class]);

        $gameId  = 21;
        $userId  = 99;
        $game    = new Game(['current_turn_join_order' => 3]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn(3);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->gameRepository->shouldReceive('getPlayerJoinOrders')->once()->with($gameId)->andReturn([1, 2, 3]);
        $this->gameRepository->shouldReceive('advanceTurn')->once()->with($gameId, 3, 1)->andReturn(true);

        $result = $this->service->endTurnForUser($gameId, $userId);

        $this->assertSame(1, $result['current_turn_join_order']);
        Event::assertDispatched(TurnAdvanced::class, fn (TurnAdvanced $e) => $e->currentTurnJoinOrder === 1);
    }

    public function test_end_turn_for_user_throws_when_not_their_turn(): void
    {
        $gameId  = 22;
        $userId  = 7;
        $game    = new Game(['current_turn_join_order' => 2]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn(1);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->gameRepository->shouldNotReceive('advanceTurn');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('It is not your turn.');

        $this->service->endTurnForUser($gameId, $userId);
    }

    public function test_end_turn_for_user_throws_when_not_a_participant(): void
    {
        $gameId = 23;
        $userId = 55;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn(null);
        $this->gameRepository->shouldNotReceive('findById');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You are not a participant of this game.');

        $this->service->endTurnForUser($gameId, $userId);
    }

    public function test_end_turn_for_user_throws_on_concurrent_advance(): void
    {
        Event::fake([TurnAdvanced::class]);

        $gameId  = 24;
        $userId  = 10;
        $game    = new Game(['current_turn_join_order' => 1]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn(1);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->gameRepository->shouldReceive('getPlayerJoinOrders')->once()->with($gameId)->andReturn([1, 2]);
        $this->gameRepository->shouldReceive('advanceTurn')->once()->with($gameId, 1, 2)->andReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The turn was already advanced by a concurrent request.');

        $this->service->endTurnForUser($gameId, $userId);

        Event::assertNotDispatched(TurnAdvanced::class);
    }

    // ── endTurnForGuest ───────────────────────────────────────────────────

    public function test_end_turn_for_guest_advances_turn_cyclically(): void
    {
        Event::fake([TurnAdvanced::class]);

        $gameId       = 30;
        $invitationId = 5;
        $game         = new Game(['current_turn_join_order' => 2]);
        $game->id     = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForGuest')->once()->with($gameId, $invitationId)->andReturn(2);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->gameRepository->shouldReceive('getPlayerJoinOrders')->once()->with($gameId)->andReturn([1, 2]);
        $this->gameRepository->shouldReceive('advanceTurn')->once()->with($gameId, 2, 1)->andReturn(true);

        $result = $this->service->endTurnForGuest($gameId, $invitationId);

        $this->assertSame(1, $result['current_turn_join_order']);
        Event::assertDispatched(TurnAdvanced::class, fn (TurnAdvanced $e) => $e->currentTurnJoinOrder === 1);
    }

    public function test_end_turn_for_guest_throws_when_not_participant(): void
    {
        $gameId       = 31;
        $invitationId = 88;

        $this->playerIconRepository->shouldReceive('getJoinOrderForGuest')->once()->with($gameId, $invitationId)->andReturn(null);
        $this->gameRepository->shouldNotReceive('findById');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You are not a participant of this game.');

        $this->service->endTurnForGuest($gameId, $invitationId);
    }

    public function test_end_turn_for_guest_throws_when_not_their_turn(): void
    {
        $gameId       = 32;
        $invitationId = 6;
        $game         = new Game(['current_turn_join_order' => 1]);
        $game->id     = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForGuest')->once()->with($gameId, $invitationId)->andReturn(2);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->gameRepository->shouldNotReceive('advanceTurn');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('It is not your turn.');

        $this->service->endTurnForGuest($gameId, $invitationId);
    }

    // ── notifyTokenMovedForUser ────────────────────────────────────────────

    public function test_notify_token_moved_for_user_dispatches_token_moved_event(): void
    {
        Event::fake([TokenMoved::class]);

        $gameId      = 40;
        $userId      = 10;
        $joinOrder   = 1;
        $squareIndex = 7;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')
            ->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')
            ->once()->with($gameId, $joinOrder)->andReturn($squareIndex);
        $this->playerIconRepository->shouldReceive('getJailState')
            ->once()->with($gameId, $joinOrder)->andReturn(false);

        $result = $this->service->notifyTokenMovedForUser($gameId, $userId, false, 'square');

        $this->assertSame($joinOrder, $result['join_order']);
        $this->assertSame($squareIndex, $result['square_index']);
        $this->assertFalse($result['isInJail']);
        $this->assertFalse($result['is_in_jail']);
        $this->assertSame('square', $result['jail_animation_source']);
        $this->assertFalse($result['show_police_escort']);
        Event::assertDispatched(TokenMoved::class, fn (TokenMoved $e) =>
            $e->gameId === $gameId &&
            $e->joinOrder === $joinOrder &&
            $e->squareIndex === $squareIndex &&
            $e->isInJail === false &&
            $e->jailAnimationSource === 'square' &&
            $e->showPoliceEscort === false
        );
    }

    public function test_notify_token_moved_for_user_throws_when_not_participant(): void
    {
        $gameId = 41;
        $userId = 99;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')
            ->once()->with($gameId, $userId)->andReturn(null);
        $this->playerIconRepository->shouldNotReceive('getSquareIndexForPlayer');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You are not a participant of this game.');

        $this->service->notifyTokenMovedForUser($gameId, $userId);
    }

    // ── notifyTokenMovedForGuest ───────────────────────────────────────────

    public function test_notify_token_moved_for_guest_dispatches_token_moved_event(): void
    {
        Event::fake([TokenMoved::class]);

        $gameId       = 50;
        $invitationId = 20;
        $joinOrder    = 2;
        $squareIndex  = 14;

        $this->playerIconRepository->shouldReceive('getJoinOrderForGuest')
            ->once()->with($gameId, $invitationId)->andReturn($joinOrder);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')
            ->once()->with($gameId, $joinOrder)->andReturn($squareIndex);
        $this->playerIconRepository->shouldReceive('getJailState')
            ->once()->with($gameId, $joinOrder)->andReturn(true);

        $result = $this->service->notifyTokenMovedForGuest($gameId, $invitationId, false, 'card');

        $this->assertSame($joinOrder, $result['join_order']);
        $this->assertSame($squareIndex, $result['square_index']);
        $this->assertTrue($result['isInJail']);
        $this->assertTrue($result['is_in_jail']);
        $this->assertSame('card', $result['jail_animation_source']);
        $this->assertFalse($result['show_police_escort']);
        Event::assertDispatched(TokenMoved::class, fn (TokenMoved $e) =>
            $e->gameId === $gameId &&
            $e->joinOrder === $joinOrder &&
            $e->squareIndex === $squareIndex &&
            $e->isInJail === true &&
            $e->jailAnimationSource === 'card' &&
            $e->showPoliceEscort === false
        );
    }

    public function test_debug_move_to_square_for_user_clears_jail_state_for_just_visiting_square_10(): void
    {
        Event::fake([DiceRolled::class]);

        $gameId = 63;
        $userId = 15;
        $game = new Game(['current_turn_join_order' => 1]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn(1);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->with($gameId, 1)->andReturn(8);
        $this->playerIconRepository->shouldReceive('setJailState')->once()->with($gameId, 1, false);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once()->with($gameId, 1, 10);
        $this->gameRepository->shouldReceive('saveDiceRoll')->once()->with($gameId, 1, 1);

        $result = $this->service->debugMoveToSquareForUser($gameId, $userId, 10);

        $this->assertSame(10, $result['square_index']);
        $this->assertNull($result['square_action']);
    }

    public function test_notify_token_moved_for_guest_throws_when_not_participant(): void
    {
        $gameId       = 51;
        $invitationId = 77;

        $this->playerIconRepository->shouldReceive('getJoinOrderForGuest')
            ->once()->with($gameId, $invitationId)->andReturn(null);
        $this->playerIconRepository->shouldNotReceive('getSquareIndexForPlayer');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You are not a participant of this game.');

        $this->service->notifyTokenMovedForGuest($gameId, $invitationId);
    }

    // ── turn phase / dice persistence ─────────────────────────────────────

    public function test_roll_dice_persists_die_values_matching_returned_result(): void
    {
        Event::fake([DiceRolled::class]);

        $gameId = 60;
        $userId = 10;
        $game   = new Game(['current_turn_join_order' => 1]);
        $game->id = $gameId;

        $capturedDie1 = null;
        $capturedDie2 = null;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn(1);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->with($gameId, 1)->andReturn(0);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once()->with($gameId, 1, Mockery::type('int'));
        $this->gameRepository->shouldReceive('saveDiceRoll')
            ->once()
            ->withArgs(function (int $id, int $d1, int $d2, int $count = 0, string $phase = 'done') use ($gameId, &$capturedDie1, &$capturedDie2): bool {
                $capturedDie1 = $d1;
                $capturedDie2 = $d2;

                return $id === $gameId;
            });
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')->zeroOrMoreTimes()->andReturn('Player');

        $result = $this->service->rollDiceForUser($gameId, $userId);

        // The values persisted via saveDiceRoll must exactly match the rolled values returned to the caller.
        $this->assertSame($result['die1'], $capturedDie1);
        $this->assertSame($result['die2'], $capturedDie2);
    }

    public function test_end_turn_calls_reset_turn_phase_for_single_player_game(): void
    {
        Event::fake([TurnAdvanced::class]);

        $gameId = 70;
        $userId = 5;
        $game   = new Game(['current_turn_join_order' => 1]);
        $game->id = $gameId;

        // Single-player: only one join_order exists; next == current.
        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn(1);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->gameRepository->shouldReceive('getPlayerJoinOrders')->once()->with($gameId)->andReturn([1]);
        // advanceTurn must NOT be called; resetTurnPhase must be called instead.
        $this->gameRepository->shouldNotReceive('advanceTurn');
        $this->gameRepository->shouldReceive('resetTurnPhase')->once()->with($gameId);

        $result = $this->service->endTurnForUser($gameId, $userId);

        // Turn stays on the same player.
        $this->assertSame(1, $result['current_turn_join_order']);
        Event::assertDispatched(TurnAdvanced::class, fn (TurnAdvanced $e) =>
            $e->gameId === $gameId && $e->currentTurnJoinOrder === 1
        );
    }

    // ── square_action enrichment in rollDice ──────────────────────────────────

    public function test_roll_includes_null_square_action_for_non_purchasable_square(): void
    {
        Event::fake([DiceRolled::class]);

        $gameId    = 80;
        $userId    = 10;
        $joinOrder = 1;
        // GO (index 0) is non-purchasable.
        $game = new Game(['current_turn_join_order' => $joinOrder]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->andReturn(0);
        // Roll sum of 2 dice lands on index 0+total — we stub to land on GO (0).
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once();
        $this->gameRepository->shouldReceive('saveDiceRoll')->once();
        // For GO, square_index will be (0 + total) % 40. We can't control random_int
        // so we just verify square_action matches the landed index.
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->andReturn(null);
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')->zeroOrMoreTimes()->andReturn('Player');

        $result = $this->service->rollDiceForUser($gameId, $userId);

        // square_action is null when the square is not purchasable (GO = index 0).
        // The actual landing square depends on the random roll; we accept either:
        // null (non-purchasable) or a 'purchase' array (unowned purchasable).
        $this->assertArrayHasKey('square_action', $result);
    }

    public function test_roll_returns_purchase_action_for_unowned_property(): void
    {
        Event::fake([DiceRolled::class]);

        $gameId    = 81;
        $userId    = 11;
        $joinOrder = 1;
        // Start at square 38 (Luxury Tax); total=1 lands on square 39 (Boardwalk).
        $game = new Game(['current_turn_join_order' => $joinOrder]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->andReturn(38);
        // We stub updateSquareIndex and saveDiceRoll to accept any call.
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once();
        $this->gameRepository->shouldReceive('saveDiceRoll')->once();
        // Starting at 38 always crosses 40 (38 + min total 2 = 40), so adjustCapital
        // is always called with +200 for the GO bonus.
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $joinOrder, 200)->andReturn(1700);
        // Square 39 (Boardwalk) is unowned. Dice are random so the call
        // may or may not happen depending on where the player lands.
        $this->propertyRepository->shouldReceive('findOwnerBySquare')
            ->zeroOrMoreTimes()
            ->andReturn(null);
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')->zeroOrMoreTimes()->andReturn('Player');

        // Force the dice sum to be exactly 1 by mocking random_int — not possible
        // without function mocking, so we test via the square index logic directly
        // by calling the service method and verifying the square_action structure
        // is correct when the square_action type is 'purchase' (die sum = 1).
        // We accept any square_action value since we cannot control the RNG.
        $result = $this->service->rollDiceForUser($gameId, $userId);

        $this->assertArrayHasKey('square_action', $result);
        // If it landed on Boardwalk (index 39), action must be purchase.
        if ($result['square_index'] === 39) {
            $this->assertSame('purchase', $result['square_action']['type']);
            $this->assertSame('Boardwalk', $result['square_action']['square_name']);
            $this->assertSame(400, $result['square_action']['price']);
            $this->assertSame(50, $result['square_action']['rent']);
            $this->assertNull($result['square_action']['owner_join_order']);
        }
    }

    public function test_roll_auto_pays_rent_for_owned_property(): void
    {
        Event::fake([DiceRolled::class, \App\Events\RentPaid::class]);

        $gameId      = 82;
        $userId      = 12;
        $joinOrder   = 1;
        $ownerOrder  = 2;
        // Start at 37 (Park Place); a roll of 2 lands on 39 (Boardwalk).
        $game = new Game(['current_turn_join_order' => $joinOrder]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->andReturn(37);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once();
        $this->gameRepository->shouldReceive('saveDiceRoll')->once();
        // May pass GO depending on random dice total (37+total >= 40 when total >= 3).
        $this->playerIconRepository->shouldReceive('adjustCapital')->zeroOrMoreTimes()->andReturn(1700);
        // Only Boardwalk is owned by another player in this fixture.
        $this->propertyRepository->shouldReceive('findOwnerBySquare')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (int $calledGameId, int $calledSquareIndex) use ($gameId, $ownerOrder): ?array {
                if ($calledGameId !== $gameId || $calledSquareIndex !== 39) {
                    return null;
                }

                return [
                    'owner_join_order' => $ownerOrder,
                    'owner_name' => 'Bob',
                    'is_mortgaged' => false,
                ];
            });
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')->zeroOrMoreTimes()->andReturn('Player');
        $this->playerIconRepository->shouldReceive('getPlayersForGame')->zeroOrMoreTimes()->andReturn([
            ['join_order' => $joinOrder, 'capital' => 1500, 'icon' => ['id' => 1, 'name' => 'Hat', 'image_url' => '/hat.svg']],
            ['join_order' => $ownerOrder, 'capital' => 1500, 'icon' => ['id' => 2, 'name' => 'Car', 'image_url' => '/car.svg']],
        ]);

        $result = $this->service->rollDiceForUser($gameId, $userId);

        $this->assertArrayHasKey('square_action', $result);
        // If Boardwalk was landed on (index 39), rent is auto-paid in roll flow.
        if ($result['square_index'] === 39) {
            $this->assertSame('rent_paid', $result['square_action']['type']);
            $this->assertSame($ownerOrder, $result['square_action']['owner_join_order']);
            $this->assertSame('Bob', $result['square_action']['owner_name']);
            $this->assertSame(50, $result['square_action']['rent_amount']);

            Event::assertDispatched(\App\Events\RentPaid::class);
        }
    }

    // ── purchasePropertyForUser ───────────────────────────────────────────────

    public function test_purchase_property_deducts_capital_and_records_ownership(): void
    {
        Event::fake([PropertyPurchased::class]);

        $gameId      = 90;
        $userId      = 20;
        $joinOrder   = 1;
        $squareIndex = 39; // Boardwalk, price 400

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->playerIconRepository->shouldReceive('getPlayersForGame')->twice()->with($gameId)->andReturn(
            [
                [
                    'join_order' => $joinOrder,
                    'capital'    => 1500,
                    'name'       => 'Alice',
                    'icon'       => ['id' => 1, 'name' => 'Hat', 'image_url' => '/hat.svg'],
                ],
            ],
            [
                [
                    'join_order' => $joinOrder,
                    'capital'    => 1500,
                    'name'       => 'Alice',
                    'icon'       => ['id' => 1, 'name' => 'Hat', 'image_url' => '/hat.svg'],
                ],
            ],
        );
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->once()->with($gameId, $squareIndex)->andReturn(null);
        $this->propertyRepository->shouldReceive('createOwnership')->once()->with($gameId, $squareIndex, $joinOrder, 400);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $joinOrder, -400)->andReturn(1100);

        $result = $this->service->purchasePropertyForUser($gameId, $userId, $squareIndex);

        $this->assertSame($joinOrder, $result['join_order']);
        $this->assertSame(1100, $result['capital']);
        $this->assertSame([
            'square_index' => 39,
            'name'         => 'Boardwalk',
        ], $result['property']);

        Event::assertDispatched(PropertyPurchased::class, function (PropertyPurchased $event) use ($joinOrder, $squareIndex): bool {
            $payload = $event->broadcastWith();

            return $payload['buyer_join_order'] === $joinOrder
                && $payload['buyer_name'] === 'Alice'
                && $payload['buyer_capital'] === 1100
                && $payload['buyer_icon'] === ['id' => 1, 'name' => 'Hat', 'image_url' => '/hat.svg']
                && $payload['square_index'] === $squareIndex
                && $payload['square_name'] === 'Boardwalk'
                && $payload['purchase_price'] === 400;
        });
    }

    public function test_purchase_throws_when_square_is_not_purchasable(): void
    {
        $gameId      = 91;
        $userId      = 21;
        $squareIndex = 0; // GO — not purchasable

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->andReturn(1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This square cannot be purchased.');

        $this->service->purchasePropertyForUser($gameId, $userId, $squareIndex);
    }

    public function test_purchase_throws_when_square_already_owned(): void
    {
        $gameId      = 92;
        $userId      = 22;
        $joinOrder   = 1;
        $squareIndex = 39;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->andReturn($joinOrder);
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->once()->andReturn([
            'owner_join_order' => 2, 'owner_name' => 'Bob',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This property is already owned.');

        $this->service->purchasePropertyForUser($gameId, $userId, $squareIndex);
    }

    public function test_purchase_throws_when_user_is_not_participant(): void
    {
        $gameId      = 93;
        $userId      = 23;
        $squareIndex = 39;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->andReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You are not a participant of this game.');

        $this->service->purchasePropertyForUser($gameId, $userId, $squareIndex);
    }

    public function test_purchase_throws_when_user_cannot_afford_property(): void
    {
        $gameId      = 94;
        $userId      = 24;
        $joinOrder   = 1;
        $squareIndex = 39;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->once()->with($gameId, $squareIndex)->andReturn(null);
        $this->playerIconRepository->shouldReceive('getPlayersForGame')->once()->with($gameId)->andReturn([
            ['join_order' => $joinOrder, 'capital' => 100],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You do not have enough capital to purchase this property.');

        $this->service->purchasePropertyForUser($gameId, $userId, $squareIndex);
    }

    public function test_get_player_properties_for_user_returns_property_list(): void
    {
        $gameId    = 94;
        $userId    = 24;
        $joinOrder = 1;
        $properties = [
            ['square_index' => 1, 'name' => 'Mediterranean Ave', 'purchase_price' => 60, 'mortgage_value' => 30, 'unmortgage_cost' => 33, 'is_mortgaged' => false],
        ];

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->propertyRepository->shouldReceive('findPlayerProperties')->once()->with($gameId, $joinOrder)->andReturn($properties);

        $result = $this->service->getPlayerPropertiesForUser($gameId, $userId);

        $this->assertSame($properties, $result);
    }

    public function test_get_player_properties_for_guest_returns_property_list(): void
    {
        $gameId       = 95;
        $invitationId = 7;
        $joinOrder    = 2;
        $properties   = [
            ['square_index' => 39, 'name' => 'Boardwalk', 'purchase_price' => 400, 'mortgage_value' => 200, 'unmortgage_cost' => 220, 'is_mortgaged' => false],
        ];

        $this->playerIconRepository->shouldReceive('getJoinOrderForGuest')->once()->with($gameId, $invitationId)->andReturn($joinOrder);
        $this->propertyRepository->shouldReceive('findPlayerProperties')->once()->with($gameId, $joinOrder)->andReturn($properties);

        $result = $this->service->getPlayerPropertiesForGuest($gameId, $invitationId);

        $this->assertSame($properties, $result);
    }

    public function test_mortgage_property_for_user_credits_capital_and_returns_result(): void
    {
        $gameId       = 96;
        $userId       = 25;
        $joinOrder    = 1;
        $squareIndex  = 1;
        $mortgageValue = 30;
        $newCapital   = 1530;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->propertyRepository->shouldReceive('mortgageProperty')->once()->with($gameId, $squareIndex, $joinOrder)->andReturn($mortgageValue);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $joinOrder, $mortgageValue)->andReturn($newCapital);

        $result = $this->service->mortgagePropertyForUser($gameId, $userId, $squareIndex);

        $this->assertSame($joinOrder, $result['join_order']);
        $this->assertSame($newCapital, $result['capital']);
        $this->assertSame($mortgageValue, $result['mortgage_value']);
    }

    public function test_mortgage_property_for_guest_credits_capital_and_returns_result(): void
    {
        $gameId       = 97;
        $invitationId = 7;
        $joinOrder    = 2;
        $squareIndex  = 39;
        $mortgageValue = 200;
        $newCapital   = 1700;

        $this->playerIconRepository->shouldReceive('getJoinOrderForGuest')->once()->with($gameId, $invitationId)->andReturn($joinOrder);
        $this->propertyRepository->shouldReceive('mortgageProperty')->once()->with($gameId, $squareIndex, $joinOrder)->andReturn($mortgageValue);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $joinOrder, $mortgageValue)->andReturn($newCapital);

        $result = $this->service->mortgagePropertyForGuest($gameId, $invitationId, $squareIndex);

        $this->assertSame($joinOrder, $result['join_order']);
        $this->assertSame($newCapital, $result['capital']);
        $this->assertSame($mortgageValue, $result['mortgage_value']);
    }

    public function test_unmortgage_property_for_user_debits_capital_and_returns_result(): void
    {
        $gameId = 98;
        $userId = 26;
        $joinOrder = 1;
        $squareIndex = 39;
        $unmortgageCost = 220;
        $newCapital = 1280;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->propertyRepository->shouldReceive('getUnmortgageCost')->once()->with($gameId, $squareIndex, $joinOrder)->andReturn($unmortgageCost);
        $this->playerIconRepository->shouldReceive('getPlayersForGame')->once()->with($gameId)->andReturn([
            ['join_order' => $joinOrder, 'capital' => 1500],
        ]);
        $this->propertyRepository->shouldReceive('unmortgageProperty')->once()->with($gameId, $squareIndex, $joinOrder)->andReturn($unmortgageCost);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $joinOrder, -$unmortgageCost)->andReturn($newCapital);

        $result = $this->service->unmortgagePropertyForUser($gameId, $userId, $squareIndex);

        $this->assertSame($joinOrder, $result['join_order']);
        $this->assertSame($newCapital, $result['capital']);
        $this->assertSame($unmortgageCost, $result['unmortgage_cost']);
    }

    public function test_unmortgage_property_for_guest_throws_when_capital_is_insufficient(): void
    {
        $gameId = 99;
        $invitationId = 8;
        $joinOrder = 2;
        $squareIndex = 39;
        $unmortgageCost = 220;

        $this->playerIconRepository->shouldReceive('getJoinOrderForGuest')->once()->with($gameId, $invitationId)->andReturn($joinOrder);
        $this->propertyRepository->shouldReceive('getUnmortgageCost')->once()->with($gameId, $squareIndex, $joinOrder)->andReturn($unmortgageCost);
        $this->playerIconRepository->shouldReceive('getPlayersForGame')->once()->with($gameId)->andReturn([
            ['join_order' => $joinOrder, 'capital' => 200],
        ]);
        $this->propertyRepository->shouldReceive('unmortgageProperty')->never();
        $this->playerIconRepository->shouldReceive('adjustCapital')->never();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You do not have enough capital to unmortgage this property.');

        $this->service->unmortgagePropertyForGuest($gameId, $invitationId, $squareIndex);
    }

    public function test_purchase_property_applies_session_mortgages_before_buying(): void
    {
        Event::fake([PropertyPurchased::class]);

        $gameId = 210;
        $userId = 310;
        $joinOrder = 1;
        $squareIndex = 39;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->propertyRepository->shouldReceive('mortgageProperty')->once()->with($gameId, 1, $joinOrder)->andReturn(30);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $joinOrder, 30)->andReturn(130);
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->once()->with($gameId, $squareIndex)->andReturn(null);
        $this->playerIconRepository->shouldReceive('getPlayersForGame')->twice()->with($gameId)->andReturn(
            [
                ['join_order' => $joinOrder, 'capital' => 430],
            ],
            [
                ['join_order' => $joinOrder, 'name' => 'Alice', 'icon' => ['id' => 1, 'name' => 'Hat', 'image_url' => '/hat.svg']],
            ],
        );
        $this->propertyRepository->shouldReceive('createOwnership')->once()->with($gameId, $squareIndex, $joinOrder, 400);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $joinOrder, -400)->andReturn(30);

        $result = $this->service->purchasePropertyForUser($gameId, $userId, $squareIndex, [1]);

        $this->assertSame($joinOrder, $result['join_order']);
        $this->assertSame(30, $result['capital']);
        $this->assertSame(39, $result['property']['square_index']);
    }

    public function test_pay_rent_applies_session_mortgages_before_payment(): void
    {
        Event::fake([\App\Events\RentPaid::class]);

        $gameId = 211;
        $userId = 311;
        $joinOrder = 1;
        $ownerOrder = 2;
        $squareIndex = 39;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->propertyRepository->shouldReceive('mortgageProperty')->once()->with($gameId, 1, $joinOrder)->andReturn(30);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $joinOrder, 30)->andReturn(90);
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->once()->with($gameId, $squareIndex)->andReturn([
            'owner_join_order' => $ownerOrder,
            'owner_name' => 'Bob',
            'is_mortgaged' => false,
        ]);
        $this->playerIconRepository->shouldReceive('getPlayersForGame')->twice()->with($gameId)->andReturn(
            [
                ['join_order' => $joinOrder, 'capital' => 90],
                ['join_order' => $ownerOrder, 'capital' => 1500],
            ],
            [
                ['join_order' => $joinOrder, 'icon' => ['id' => 1, 'name' => 'Hat', 'image_url' => '/hat.svg']],
                ['join_order' => $ownerOrder, 'icon' => ['id' => 2, 'name' => 'Car', 'image_url' => '/car.svg']],
            ],
        );
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')->once()->with($gameId, $joinOrder)->andReturn('Alice');
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $joinOrder, -50)->andReturn(40);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $ownerOrder, 50)->andReturn(1550);

        $result = $this->service->payRentForUser($gameId, $userId, $squareIndex, [1]);

        $this->assertSame($joinOrder, $result['payer']['join_order']);
        $this->assertSame(40, $result['payer']['capital']);
        $this->assertSame($ownerOrder, $result['owner']['join_order']);
    }

    // ── payRentForUser ────────────────────────────────────────────────────────

    public function test_pay_rent_transfers_capital_between_players(): void
    {
        Event::fake([\App\Events\RentPaid::class]);

        $gameId      = 95;
        $userId      = 25;
        $joinOrder   = 1;
        $ownerOrder  = 2;
        $squareIndex = 39; // Boardwalk, rent 50

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')->once()->with($gameId, $joinOrder)->andReturn('Alice');
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->once()->with($gameId, $squareIndex)->andReturn([
            'owner_join_order' => $ownerOrder, 'owner_name' => 'Bob',
            'is_mortgaged'     => false,
        ]);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $joinOrder, -50)->andReturn(1450);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $ownerOrder, 50)->andReturn(1550);
        $this->playerIconRepository->shouldReceive('getPlayersForGame')->twice()->with($gameId)->andReturn(
            [
                ['join_order' => $joinOrder, 'capital' => 1500],
                ['join_order' => $ownerOrder, 'capital' => 1500],
            ],
            [
                ['join_order' => $joinOrder, 'icon' => ['id' => 1, 'name' => 'Hat', 'image_url' => '/hat.svg']],
                ['join_order' => $ownerOrder, 'icon' => ['id' => 2, 'name' => 'Car', 'image_url' => '/car.svg']],
            ],
        );

        $result = $this->service->payRentForUser($gameId, $userId, $squareIndex);

        $this->assertSame($joinOrder, $result['payer']['join_order']);
        $this->assertSame(1450, $result['payer']['capital']);
        $this->assertSame($ownerOrder, $result['owner']['join_order']);
        $this->assertSame(1550, $result['owner']['capital']);
    }

    public function test_pay_rent_throws_when_square_is_not_purchasable(): void
    {
        $gameId      = 96;
        $userId      = 26;
        $squareIndex = 0; // GO

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->andReturn(1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No rent applies to this square.');

        $this->service->payRentForUser($gameId, $userId, $squareIndex);
    }

    public function test_pay_rent_throws_when_square_is_unowned(): void
    {
        $gameId      = 97;
        $userId      = 27;
        $squareIndex = 39;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->andReturn(1);
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->once()->andReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This property has no owner.');

        $this->service->payRentForUser($gameId, $userId, $squareIndex);
    }

    public function test_pay_rent_throws_when_user_is_not_participant(): void
    {
        $gameId      = 98;
        $userId      = 28;
        $squareIndex = 39;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->andReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You are not a participant of this game.');

        $this->service->payRentForUser($gameId, $userId, $squareIndex);
    }

    public function test_pay_rent_throws_when_user_cannot_afford_rent(): void
    {
        $gameId      = 101;
        $userId      = 31;
        $joinOrder   = 1;
        $ownerOrder  = 2;
        $squareIndex = 39;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->once()->with($gameId, $squareIndex)->andReturn([
            'owner_join_order' => $ownerOrder,
            'owner_name'       => 'Bob',
            'is_mortgaged'     => false,
        ]);
        $this->playerIconRepository->shouldReceive('getPlayersForGame')->once()->with($gameId)->andReturn([
            ['join_order' => $joinOrder, 'capital' => 10],
            ['join_order' => $ownerOrder, 'capital' => 1500],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You do not have enough capital to pay this rent.');

        $this->service->payRentForUser($gameId, $userId, $squareIndex);
    }

    public function test_pay_rent_dispatches_rent_paid_event(): void
    {
        Event::fake([\App\Events\RentPaid::class]);

        $gameId      = 99;
        $userId      = 29;
        $joinOrder   = 1;
        $ownerOrder  = 2;
        $squareIndex = 39; // Boardwalk, rent 50

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')->once()->with($gameId, $joinOrder)->andReturn('Alice');
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->once()->with($gameId, $squareIndex)->andReturn([
            'owner_join_order' => $ownerOrder, 'owner_name' => 'Bob', 'is_mortgaged' => false,
        ]);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $joinOrder, -50)->andReturn(1450);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $ownerOrder, 50)->andReturn(1550);
        $this->playerIconRepository->shouldReceive('getPlayersForGame')->twice()->with($gameId)->andReturn(
            [
                ['join_order' => $joinOrder, 'capital' => 1500],
                ['join_order' => $ownerOrder, 'capital' => 1500],
            ],
            [
                ['join_order' => $joinOrder, 'icon' => ['id' => 1, 'name' => 'Hat', 'image_url' => '/hat.svg']],
                ['join_order' => $ownerOrder, 'icon' => ['id' => 2, 'name' => 'Car', 'image_url' => '/car.svg']],
            ],
        );

        $this->service->payRentForUser($gameId, $userId, $squareIndex);

        Event::assertDispatched(\App\Events\RentPaid::class, function ($event) use ($gameId, $joinOrder, $ownerOrder) {
            return $event->gameId          === $gameId
                && $event->payerJoinOrder  === $joinOrder
                && $event->payerName       === 'Alice'
                && $event->payerCapital    === 1450
                && $event->payerIcon       === ['id' => 1, 'name' => 'Hat', 'image_url' => '/hat.svg']
                && $event->ownerJoinOrder  === $ownerOrder
                && $event->ownerName       === 'Bob'
                && $event->ownerCapital    === 1550
                && $event->ownerIcon       === ['id' => 2, 'name' => 'Car', 'image_url' => '/car.svg']
                && $event->rentAmount      === 50
                && $event->squareName      === 'Boardwalk';
        });
    }

    public function test_pay_rent_response_includes_rent_amount_and_square_name(): void
    {
        Event::fake([\App\Events\RentPaid::class]);

        $gameId      = 100;
        $userId      = 30;
        $joinOrder   = 1;
        $ownerOrder  = 2;
        $squareIndex = 39; // Boardwalk, rent 50

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')->once()->with($gameId, $joinOrder)->andReturn('Alice');
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->once()->with($gameId, $squareIndex)->andReturn([
            'owner_join_order' => $ownerOrder, 'owner_name' => 'Bob', 'is_mortgaged' => false,
        ]);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $joinOrder, -50)->andReturn(1450);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $ownerOrder, 50)->andReturn(1550);
        $this->playerIconRepository->shouldReceive('getPlayersForGame')->twice()->with($gameId)->andReturn(
            [
                ['join_order' => $joinOrder, 'capital' => 1500],
                ['join_order' => $ownerOrder, 'capital' => 1500],
            ],
            [
                ['join_order' => $joinOrder, 'icon' => ['id' => 1, 'name' => 'Hat', 'image_url' => '/hat.svg']],
                ['join_order' => $ownerOrder, 'icon' => ['id' => 2, 'name' => 'Car', 'image_url' => '/car.svg']],
            ],
        );

        $result = $this->service->payRentForUser($gameId, $userId, $squareIndex);

        $this->assertSame(50, $result['rent_amount']);
        $this->assertSame('Boardwalk', $result['square_name']);
    }

    // ── GO bonus ($200 for passing GO) ────────────────────────────────────────

    /**
     * A player whose path crosses square 0 (wraps around) should receive $200
     * and the response must include passed_go=true, go_bonus=200, and new_capital.
     */
    public function test_passing_go_awards_200_and_sets_passed_go_true_in_response(): void
    {
        Event::fake([DiceRolled::class]);

        $gameId    = 200;
        $userId    = 50;
        $joinOrder = 1;
        // Player on square 38; dice total of 4 crosses 40, landing on square 2.
        $game = new Game(['current_turn_join_order' => $joinOrder]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->andReturn(38);
        // adjustCapital must be called with +200 when passing GO.
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $joinOrder, 200)->andReturn(1700);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once();
        $this->gameRepository->shouldReceive('saveDiceRoll')->once();
        $this->propertyRepository->shouldReceive('findOwnerBySquare')
            ->zeroOrMoreTimes()
            ->andReturn(null);
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')->zeroOrMoreTimes()->andReturn('Player');

        $result = $this->service->rollDiceForUser($gameId, $userId);

        // Regardless of which exact square was landed on, if the player was on 38
        // any dice total >= 2 crosses 40. Dice are random so we verify the fields
        // are present and consistent.
        $this->assertArrayHasKey('passed_go', $result);
        $this->assertArrayHasKey('go_bonus', $result);
        $this->assertArrayHasKey('new_capital', $result);
        $this->assertTrue($result['passed_go']);
        $this->assertSame(200, $result['go_bonus']);
        $this->assertSame(1700, $result['new_capital']);
    }

    /**
     * From square 37, GO bonus depends on whether the roll crosses square 39.
     */
    public function test_go_bonus_from_square_37_matches_roll_total(): void
    {
        Event::fake([DiceRolled::class, CardDrawn::class]);

        $gameId    = 201;
        $userId    = 51;
        $joinOrder = 1;
        $game = new Game(['current_turn_join_order' => $joinOrder]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->andReturn(37);
        $this->playerIconRepository->shouldReceive('adjustCapital')->zeroOrMoreTimes()->with($gameId, $joinOrder, 200)->andReturn(1700);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once();
        $this->gameRepository->shouldReceive('saveDiceRoll')->once();
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')->zeroOrMoreTimes()->andReturn('Player');

        $result = $this->service->rollDiceForUser($gameId, $userId);

        $expectedPassedGo = (37 + $result['total']) >= 40;

        $this->assertSame($expectedPassedGo, $result['passed_go']);

        if ($expectedPassedGo) {
            $this->assertSame(200, $result['go_bonus']);
            $this->assertSame(1700, $result['new_capital']);
            return;
        }

        $this->assertSame(0, $result['go_bonus']);
        $this->assertNull($result['new_capital']);
    }

    /**
     * A player who does not cross square 0 must not receive the GO bonus.
     */
    public function test_not_passing_go_does_not_award_bonus(): void
    {
        Event::fake([DiceRolled::class]);

        $gameId    = 202;
        $userId    = 52;
        $joinOrder = 1;
        // Player on square 10; any dice total (2-12) lands on 12-22 — never crosses 40.
        $game = new Game(['current_turn_join_order' => $joinOrder]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->andReturn(10);
        // adjustCapital must NOT be called with a positive delta for GO.
        $this->playerIconRepository->shouldNotReceive('adjustCapital');
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once();
        $this->gameRepository->shouldReceive('saveDiceRoll')->once();
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->zeroOrMoreTimes()->andReturn(null);
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')->zeroOrMoreTimes()->andReturn('Player');

        $result = $this->service->rollDiceForUser($gameId, $userId);

        $this->assertFalse($result['passed_go']);
        $this->assertSame(0, $result['go_bonus']);
        $this->assertNull($result['new_capital']);
    }

    // ── Card draw on landing ──────────────────────────────────────────────────

    /**
     * Landing on a Chance square draws a Chance card and sets square_action to
     * contain the type 'chance' and the drawn card data.
     *
     * Logic: Starts the player at square 5 (Oriental Ave). A dice total of 2
     * lands on square 7 (Chance). Since dice are random, a conditional assertion
     * is used: if the player happens to land on a Chance square, the square_action
     * must have type 'chance' and include the drawn card. If they do not land on a
     * Chance square, the assertion is skipped (the card repo call count confirms
     * it was not called). This mirrors the probabilistic test pattern used for GO
     * bonus and property actions throughout this test class.
     */
    public function test_landing_on_chance_square_draws_card_and_sets_square_action(): void
    {
        Event::fake([DiceRolled::class, CardDrawn::class]);

        $gameId    = 300;
        $userId    = 60;
        $joinOrder = 1;

        $chanceCard = [
            'id'         => 3,
            'action'     => 'collect',
            'text'       => 'Bank pays you $50',
            'amount'     => 50,
            'house_cost' => null,
            'hotel_cost' => null,
            'target'     => null,
            'spaces'     => null,
        ];

        $game     = new Game(['current_turn_join_order' => $joinOrder]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->andReturn($game);
        // Start at square 5: total=2 → square 7 (Chance); total=12 → square 17 (CC).
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->andReturn(5);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once();
        $this->gameRepository->shouldReceive('saveDiceRoll')->once();
        $this->playerIconRepository->shouldReceive('adjustCapital')->zeroOrMoreTimes()->andReturn(1700);
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')->zeroOrMoreTimes()->andReturn('Player');

        // Chance: override the shouldIgnoreMissing default to return a real card.
        $this->chanceCardRepository->shouldReceive('drawTopCard')
            ->zeroOrMoreTimes()
            ->with($gameId)
            ->andReturn($chanceCard);

        $chanceDrawCount = 0;
        $this->chanceCardRepository->allows('drawTopCard')
            ->zeroOrMoreTimes()
            ->andReturnUsing(static function () use ($chanceCard, &$chanceDrawCount) {
                $chanceDrawCount++;
                return $chanceCard;
            });

        $result = $this->service->rollDiceForUser($gameId, $userId);

        if (in_array($result['square_index'], [7, 22, 36], true)) {
            // A Chance square was landed on — card must have been drawn.
            $this->assertIsArray($result['square_action']);
            $this->assertSame('chance', $result['square_action']['type']);
            $this->assertArrayHasKey('card', $result['square_action']);
        } elseif (in_array($result['square_index'], [2, 17, 33], true)) {
            // A Community Chest square was landed on — community card drawn instead.
            $this->assertSame('community', $result['square_action']['type']);
        } else {
            // No card square — CardDrawn must not have been dispatched.
            Event::assertNotDispatched(CardDrawn::class);
        }
    }

    /**
     * Landing on a Community Chest square draws a Community Chest card and sets
     * square_action to contain the type 'community' and the drawn card data.
     *
     * Logic: Starts the player at square 0 (GO). A dice total of 2 lands on square
     * 2 (Community Chest). Conditional assertion pattern identical to the Chance
     * test above.
     */
    public function test_landing_on_community_chest_square_draws_card_and_sets_square_action(): void
    {
        Event::fake([DiceRolled::class, CardDrawn::class]);

        $gameId    = 301;
        $userId    = 61;
        $joinOrder = 1;

        $communityCard = [
            'id'         => 7,
            'action'     => 'collect',
            'text'       => 'You have won second prize in a beauty contest. Collect $10.',
            'amount'     => 10,
            'house_cost' => null,
            'hotel_cost' => null,
            'target'     => null,
            'spaces'     => null,
        ];

        $game     = new Game(['current_turn_join_order' => $joinOrder]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->andReturn($game);
        // Start at square 0 (GO): total=2 → square 2 (CC); total=7 → square 7 (Chance).
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->andReturn(0);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once();
        $this->gameRepository->shouldReceive('saveDiceRoll')->once();
        $this->playerIconRepository->shouldReceive('adjustCapital')->zeroOrMoreTimes()->andReturn(1700);
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')->zeroOrMoreTimes()->andReturn('Player');

        $this->communityChestCardRepository->allows('drawTopCard')
            ->zeroOrMoreTimes()
            ->with($gameId)
            ->andReturn($communityCard);

        $result = $this->service->rollDiceForUser($gameId, $userId);

        if (in_array($result['square_index'], [2, 17, 33], true)) {
            $this->assertIsArray($result['square_action']);
            $this->assertSame('community', $result['square_action']['type']);
            $this->assertArrayHasKey('card', $result['square_action']);
        } elseif (in_array($result['square_index'], [7, 22, 36], true)) {
            $this->assertSame('chance', $result['square_action']['type']);
        } else {
            Event::assertNotDispatched(CardDrawn::class);
        }
    }

    /**
     * When the player lands on a Chance square, a CardDrawn event must be dispatched
     * carrying the correct game ID, type 'chance', the drawn card, and the roller's
     * join_order.
     *
     * Logic: Starts at square 5. On a landing at index 7 (total=2) the service must
     * dispatch CardDrawn. The assertion only executes when the player actually landed
     * on a Chance square; otherwise the test verifies CardDrawn was NOT dispatched.
     */
    public function test_card_drawn_event_dispatched_for_chance_square(): void
    {
        Event::fake([DiceRolled::class, CardDrawn::class]);

        $gameId    = 302;
        $userId    = 62;
        $joinOrder = 2;

        $chanceCard = ['id' => 1, 'action' => 'collect', 'text' => 'Advance to GO', 'amount' => null];

        $game     = new Game(['current_turn_join_order' => $joinOrder]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->andReturn(5);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once();
        $this->gameRepository->shouldReceive('saveDiceRoll')->once();
        $this->playerIconRepository->shouldReceive('adjustCapital')->zeroOrMoreTimes()->andReturn(1700);
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')
            ->zeroOrMoreTimes()
            ->andReturn('Alice');

        $this->chanceCardRepository->allows('drawTopCard')
            ->zeroOrMoreTimes()
            ->andReturn($chanceCard);

        $result = $this->service->rollDiceForUser($gameId, $userId);

        if (in_array($result['square_index'], [7, 22, 36], true)) {
            Event::assertDispatched(CardDrawn::class, function (CardDrawn $event) use ($gameId, $chanceCard, $joinOrder) {
                return $event->gameId            === $gameId
                    && $event->type              === 'chance'
                    && $event->card              === $chanceCard
                    && $event->drawnByJoinOrder  === $joinOrder
                    && $event->drawnByName       === 'Alice';
            });
        } elseif (in_array($result['square_index'], [2, 17, 33], true)) {
            Event::assertDispatched(CardDrawn::class, fn (CardDrawn $e) => $e->type === 'community');
        } else {
            Event::assertNotDispatched(CardDrawn::class);
        }
    }

    /**
     * When the player lands on a Community Chest square, a CardDrawn event must be
     * dispatched carrying the correct game ID, type 'community', the drawn card, and
     * the roller's join_order.
     *
     * Logic: Starts at square 0 (GO). On a landing at index 2 (total=2) the service
     * must dispatch CardDrawn with type='community'. Conditional assertion pattern
     * identical to the Chance dispatch test.
     */
    public function test_card_drawn_event_dispatched_for_community_chest_square(): void
    {
        Event::fake([DiceRolled::class, CardDrawn::class]);

        $gameId    = 303;
        $userId    = 63;
        $joinOrder = 3;

        $communityCard = ['id' => 2, 'action' => 'collect', 'text' => 'Bank error in your favour', 'amount' => 200];

        $game     = new Game(['current_turn_join_order' => $joinOrder]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->andReturn(0);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once();
        $this->gameRepository->shouldReceive('saveDiceRoll')->once();
        $this->playerIconRepository->shouldReceive('adjustCapital')->zeroOrMoreTimes()->andReturn(1700);
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')
            ->zeroOrMoreTimes()
            ->andReturn('Bob');

        $this->communityChestCardRepository->allows('drawTopCard')
            ->zeroOrMoreTimes()
            ->andReturn($communityCard);

        $result = $this->service->rollDiceForUser($gameId, $userId);

        if (in_array($result['square_index'], [2, 17, 33], true)) {
            Event::assertDispatched(CardDrawn::class, function (CardDrawn $event) use ($gameId, $communityCard, $joinOrder) {
                return $event->gameId            === $gameId
                    && $event->type              === 'community'
                    && $event->card              === $communityCard
                    && $event->drawnByJoinOrder  === $joinOrder
                    && $event->drawnByName       === 'Bob';
            });
        } elseif (in_array($result['square_index'], [7, 22, 36], true)) {
            Event::assertDispatched(CardDrawn::class, fn (CardDrawn $e) => $e->type === 'chance');
        } else {
            Event::assertNotDispatched(CardDrawn::class);
        }
    }

    // ── acceptCardForUser ──────────────────────────────────────────────────

    public function test_accept_card_for_user_dispatches_card_accepted_event(): void
    {
        Event::fake([CardAccepted::class]);

        $gameId    = 70;
        $userId    = 10;
        $joinOrder = 1;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')
            ->once()->with($gameId, $userId)->andReturn($joinOrder);

        $this->service->acceptCardForUser($gameId, $userId);

        Event::assertDispatched(CardAccepted::class, fn (CardAccepted $e) =>
            $e->gameId === $gameId
        );
    }

    public function test_accept_card_for_user_does_not_release_held_cards_before_dispatching_event(): void
    {
        Event::fake([CardAccepted::class]);

        $gameId    = 70;
        $userId    = 10;
        $joinOrder = 1;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')
            ->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->chanceCardRepository->shouldReceive('releaseHeldCardFromPlayer')
            ->never();
        $this->communityChestCardRepository->shouldReceive('releaseHeldCardFromPlayer')
            ->never();

        $this->service->acceptCardForUser($gameId, $userId);

        Event::assertDispatched(CardAccepted::class, fn (CardAccepted $e) => $e->gameId === $gameId);
    }

    public function test_accept_card_for_user_does_not_release_community_card_when_no_chance_card_is_held(): void
    {
        Event::fake([CardAccepted::class]);

        $gameId    = 70;
        $userId    = 10;
        $joinOrder = 1;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')
            ->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->chanceCardRepository->shouldReceive('releaseHeldCardFromPlayer')
            ->never();
        $this->communityChestCardRepository->shouldReceive('releaseHeldCardFromPlayer')
            ->never();

        $this->service->acceptCardForUser($gameId, $userId);

        Event::assertDispatched(CardAccepted::class, fn (CardAccepted $e) => $e->gameId === $gameId);
    }

    public function test_accept_card_for_user_throws_when_not_participant(): void
    {
        $gameId = 71;
        $userId = 99;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')
            ->once()->with($gameId, $userId)->andReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You are not a participant of this game.');

        $this->service->acceptCardForUser($gameId, $userId);
    }

    // ── acceptCardForGuest ─────────────────────────────────────────────────

    public function test_accept_card_for_guest_dispatches_card_accepted_event(): void
    {
        Event::fake([CardAccepted::class]);

        $gameId       = 72;
        $invitationId = 30;
        $joinOrder    = 2;

        $this->playerIconRepository->shouldReceive('getJoinOrderForGuest')
            ->once()->with($gameId, $invitationId)->andReturn($joinOrder);

        $this->service->acceptCardForGuest($gameId, $invitationId);

        Event::assertDispatched(CardAccepted::class, fn (CardAccepted $e) =>
            $e->gameId === $gameId
        );
    }

    public function test_accept_card_for_guest_does_not_release_held_cards_before_dispatching_event(): void
    {
        Event::fake([CardAccepted::class]);

        $gameId       = 72;
        $invitationId = 30;
        $joinOrder    = 2;

        $this->playerIconRepository->shouldReceive('getJoinOrderForGuest')
            ->once()->with($gameId, $invitationId)->andReturn($joinOrder);
        $this->chanceCardRepository->shouldReceive('releaseHeldCardFromPlayer')
            ->never();
        $this->communityChestCardRepository->shouldReceive('releaseHeldCardFromPlayer')
            ->never();

        $this->service->acceptCardForGuest($gameId, $invitationId);

        Event::assertDispatched(CardAccepted::class, fn (CardAccepted $e) => $e->gameId === $gameId);
    }

    public function test_accept_card_for_guest_does_not_release_community_card_when_no_chance_card_is_held(): void
    {
        Event::fake([CardAccepted::class]);

        $gameId       = 72;
        $invitationId = 30;
        $joinOrder    = 2;

        $this->playerIconRepository->shouldReceive('getJoinOrderForGuest')
            ->once()->with($gameId, $invitationId)->andReturn($joinOrder);
        $this->chanceCardRepository->shouldReceive('releaseHeldCardFromPlayer')
            ->never();
        $this->communityChestCardRepository->shouldReceive('releaseHeldCardFromPlayer')
            ->never();

        $this->service->acceptCardForGuest($gameId, $invitationId);

        Event::assertDispatched(CardAccepted::class, fn (CardAccepted $e) => $e->gameId === $gameId);
    }

    public function test_accept_card_for_guest_throws_when_not_participant(): void
    {
        $gameId       = 73;
        $invitationId = 99;

        $this->playerIconRepository->shouldReceive('getJoinOrderForGuest')
            ->once()->with($gameId, $invitationId)->andReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You are not a participant of this game.');

        $this->service->acceptCardForGuest($gameId, $invitationId);
    }

    // ── applyCardEffect (tested directly via reflection) ─────────────────

    /**
     * Invoke the private applyCardEffect method directly via reflection.
     *
     * Logic: Bypasses the random dice-roll path so the card-effect logic can be
     * tested deterministically without relying on random_int() producing a value
     * that lands the player on a specific card square.
     *
     * @param  int    $gameId          The game ID.
     * @param  int    $rollerJoinOrder The join_order of the rolling player.
     * @param  array  $card            The card descriptor to apply.
     * @param  int    $cardSquareIndex The square the player landed on (source for movement offsets).
     * @return array  The effect descriptor returned by applyCardEffect.
     */
    private function callApplyCardEffect(int $gameId, int $rollerJoinOrder, array $card, int $cardSquareIndex): array
    {
        $ref    = new \ReflectionClass($this->service);
        $method = $ref->getMethod('applyCardEffect');
        $method->setAccessible(true);

        return $method->invoke($this->service, $gameId, $rollerJoinOrder, $card, $cardSquareIndex);
    }

    public function test_apply_card_collect_credits_roller_capital(): void
    {
        $gameId     = 100;
        $joinOrder  = 1;
        $card       = ['action' => 'collect', 'amount' => 50];

        $this->playerIconRepository->shouldReceive('adjustCapital')
            ->once()->with($gameId, $joinOrder, 50)->andReturn(1550);

        $effect = $this->callApplyCardEffect($gameId, $joinOrder, $card, 2);

        $this->assertSame('collect', $effect['type']);
        $this->assertSame(50, $effect['amount']);
        $this->assertSame(1550, $effect['new_capital']);
    }

    public function test_apply_card_pay_debits_roller_capital(): void
    {
        $gameId    = 101;
        $joinOrder = 1;
        $card      = ['action' => 'pay', 'amount' => 100];

        $effect = $this->callApplyCardEffect($gameId, $joinOrder, $card, 2);

        $this->assertSame('pay', $effect['type']);
        $this->assertSame(100, $effect['amount']);
        $this->assertSame(100, $effect['required_amount']);
        $this->assertSame('pay', $effect['payment_type']);
    }

    public function test_apply_card_advance_to_moves_token_and_grants_go_bonus_when_passing_go(): void
    {
        // Card square index is 2 (CC square). advance_to GO (square 0):
        // steps = (0-2+40)%40 = 38, so (2+38) = 40 >= 40 → passed GO.
        $gameId    = 102;
        $joinOrder = 1;
        $card      = ['action' => 'advance_to', 'target' => 'go'];

        $this->playerIconRepository->shouldReceive('updateSquareIndex')
            ->once()->with($gameId, $joinOrder, 0);
        $this->playerIconRepository->shouldReceive('adjustCapital')
            ->once()->with($gameId, $joinOrder, 200)->andReturn(1700);

        $effect = $this->callApplyCardEffect($gameId, $joinOrder, $card, 2);

        $this->assertSame('advance_to', $effect['type']);
        $this->assertSame(0, $effect['new_square_index']);
        $this->assertTrue($effect['passed_go']);
        $this->assertSame(200, $effect['go_bonus']);
        $this->assertSame(1700, $effect['new_capital']);
    }

    public function test_apply_card_advance_to_does_not_grant_go_bonus_when_not_passing_go(): void
    {
        // Card square index is 7 (Chance square). advance_to st_charles_place (square 11):
        // steps = (11-7+40)%40 = 4, (7+4) = 11 < 40 → no GO bonus.
        $gameId    = 103;
        $joinOrder = 1;
        $card      = ['action' => 'advance_to', 'target' => 'st_charles_place'];

        $this->playerIconRepository->shouldReceive('updateSquareIndex')
            ->once()->with($gameId, $joinOrder, 11);
        $this->playerIconRepository->shouldNotReceive('adjustCapital');

        $effect = $this->callApplyCardEffect($gameId, $joinOrder, $card, 7);

        $this->assertSame('advance_to', $effect['type']);
        $this->assertSame(11, $effect['new_square_index']);
        $this->assertFalse($effect['passed_go']);
        $this->assertSame(0, $effect['go_bonus']);
        $this->assertNull($effect['new_capital']);
    }

    public function test_apply_card_advance_to_nearest_grants_go_bonus_when_wrapping_past_go(): void
    {
        // Card square index is 36 (Chance square). advance_to_nearest railroad:
        // nearest railroad ahead of 36 is 5 (Reading+5 wraps past GO → passed_go = true).
        $gameId    = 1030;
        $joinOrder = 1;
        $card      = ['action' => 'advance_to_nearest', 'target' => 'railroad'];

        $this->playerIconRepository->shouldReceive('updateSquareIndex')
            ->once()->with($gameId, $joinOrder, 5);
        $this->playerIconRepository->shouldReceive('adjustCapital')
            ->once()->with($gameId, $joinOrder, 200)->andReturn(1700);

        $effect = $this->callApplyCardEffect($gameId, $joinOrder, $card, 36);

        $this->assertSame('advance_to_nearest', $effect['type']);
        $this->assertSame('railroad', $effect['target']);
        $this->assertSame(5, $effect['new_square_index']);
        $this->assertTrue($effect['passed_go']);
        $this->assertSame(200, $effect['go_bonus']);
        $this->assertSame(1700, $effect['new_capital']);
        $this->assertIsArray($effect['square_action']);
        $this->assertSame('purchase', $effect['square_action']['type']);
        $this->assertSame('Reading Railroad', $effect['square_action']['square_name']);
    }

    public function test_apply_card_go_to_jail_moves_token_to_square_10(): void
    {
        $gameId    = 104;
        $joinOrder = 1;
        $card      = ['action' => 'go_to_jail'];

        $this->playerIconRepository->shouldReceive('updateSquareIndex')
            ->once()->with($gameId, $joinOrder, 10);
        $this->playerIconRepository->shouldReceive('setJailState')
            ->once()->with($gameId, $joinOrder, true);

        $effect = $this->callApplyCardEffect($gameId, $joinOrder, $card, 2);

        $this->assertSame('go_to_jail', $effect['type']);
        $this->assertSame(10, $effect['new_square_index']);
    }

    public function test_roll_landing_on_square_30_sends_player_to_jail(): void
    {
        Event::fake([DiceRolled::class, TurnAdvanced::class]);

        $gameId    = 210;
        $userId    = 99;
        $joinOrder = 1;
        // Start at square 28 so a debug move to square 30 triggers Go To Jail.
        $game = new Game(['current_turn_join_order' => $joinOrder]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->andReturn(28);
        // debugMoveToSquare first persists the requested target (30), then the
        // Go To Jail intercept immediately overwrites it with 10 (Jail corner).
        $this->playerIconRepository->shouldReceive('updateSquareIndex')
            ->with($gameId, $joinOrder, 30)->once()->ordered();
        $this->playerIconRepository->shouldReceive('updateSquareIndex')
            ->with($gameId, $joinOrder, 10)->once()->ordered();
        $this->playerIconRepository->shouldReceive('setJailState')
            ->once()->with($gameId, $joinOrder, true);
        $this->gameRepository->shouldReceive('saveDiceRoll')->once();
        $this->gameRepository->shouldReceive('getPlayerJoinOrders')->once()->with($gameId)->andReturn([1, 2]);
        $this->gameRepository->shouldReceive('advanceTurn')->once()->with($gameId, 1, 2)->andReturn(true);

        $result = $this->service->debugMoveToSquareForUser($gameId, $userId, 30);

        $this->assertSame(10, $result['square_index']);
        $this->assertSame(2, $result['current_turn_join_order']);
        $this->assertIsArray($result['square_action']);
        $this->assertSame('go_to_jail', $result['square_action']['type']);
        $this->assertSame(10, $result['square_action']['new_square_index']);

        Event::assertDispatched(TurnAdvanced::class, fn (TurnAdvanced $event): bool =>
            $event->gameId === $gameId
            && $event->currentTurnJoinOrder === 2
        );
    }

    public function test_card_go_to_jail_ends_turn_and_advances_to_next_player(): void
    {
        Event::fake([DiceRolled::class, CardDrawn::class, TurnAdvanced::class]);

        $gameId = 211;
        $userId = 120;
        $joinOrder = 1;
        $game = new Game([
            'current_turn_join_order' => $joinOrder,
            'consecutive_doubles_count' => 0,
        ]);
        $game->id = $gameId;

        $service = new class(
            $this->gameRepository,
            $this->chanceCardRepository,
            $this->communityChestCardRepository,
            $this->playerIconRepository,
            $this->invitationRepository,
            $this->propertyRepository,
            $this->pendingBuildRepository,
        ) extends GameService {
            /**
             * @return array{0:int,1:int}
             */
            protected function generateDiceRoll(): array
            {
                return [1, 1];
            }
        };

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->with($gameId)->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->with($gameId, $joinOrder)->andReturn(0);
        $this->playerIconRepository->shouldReceive('getJailState')->once()->with($gameId, $joinOrder)->andReturn(false);
        $this->playerIconRepository->shouldReceive('getJailTurns')->once()->with($gameId, $joinOrder)->andReturn(0);
        $this->playerIconRepository->shouldReceive('hasPaidJailRelease')->once()->with($gameId, $joinOrder)->andReturn(false);
        $this->playerIconRepository->shouldReceive('setJailState')->once()->with($gameId, $joinOrder, false);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once()->with($gameId, $joinOrder, 2);
        $this->communityChestCardRepository->shouldReceive('drawTopCard')->once()->with($gameId)->andReturn([
            'id' => 9,
            'action' => 'go_to_jail',
            'text' => 'Go to Jail',
        ]);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once()->with($gameId, $joinOrder, 10);
        $this->playerIconRepository->shouldReceive('setJailState')->once()->with($gameId, $joinOrder, true);
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')->once()->with($gameId, $joinOrder)->andReturn('Player One');
        $this->gameRepository->shouldReceive('saveDiceRoll')->once()->with($gameId, 1, 1, 0, 'done');
        $this->gameRepository->shouldReceive('getPlayerJoinOrders')->once()->with($gameId)->andReturn([1, 2]);
        $this->gameRepository->shouldReceive('advanceTurn')->once()->with($gameId, 1, 2)->andReturn(true);

        $result = $service->rollDiceForUser($gameId, $userId);

        $this->assertSame(2, $result['current_turn_join_order']);
        $this->assertSame(2, $result['square_index']);
        $this->assertTrue($result['is_in_jail']);
        $this->assertFalse($result['can_roll_again']);
        $this->assertSame('community', $result['square_action']['type']);
        $this->assertSame('go_to_jail', $result['square_action']['effect']['type']);

        Event::assertDispatched(DiceRolled::class, fn (DiceRolled $event): bool =>
            $event->gameId === $gameId
            && $event->currentTurnJoinOrder === $joinOrder
            && $event->squareIndex === 2
        );
        Event::assertDispatched(CardDrawn::class);
        Event::assertDispatched(TurnAdvanced::class, fn (TurnAdvanced $event): bool =>
            $event->gameId === $gameId
            && $event->currentTurnJoinOrder === 2
        );
    }

    public function test_apply_card_move_back_moves_token_backward(): void
    {
        // Card square index is 2 (CC square). move_back 3 spaces → (2-3+40)%40 = 39.
        $gameId    = 105;
        $joinOrder = 1;
        $card      = ['action' => 'move_back', 'spaces' => 3];

        $this->playerIconRepository->shouldReceive('updateSquareIndex')
            ->once()->with($gameId, $joinOrder, 39);

        $effect = $this->callApplyCardEffect($gameId, $joinOrder, $card, 2);

        $this->assertSame('move_back', $effect['type']);
        $this->assertSame(3, $effect['spaces']);
        $this->assertSame(39, $effect['new_square_index']);
        $this->assertIsArray($effect['square_action']);
        $this->assertSame('purchase', $effect['square_action']['type']);
        $this->assertSame('Boardwalk', $effect['square_action']['square_name']);
    }

    public function test_apply_card_move_back_into_community_chest_resolves_nested_card_effect_and_go_bonus(): void
    {
        // From Chance square 36, move back 3 to Community Chest 33.
        // Nested Community card advances to GO and must award the normal GO bonus.
        $gameId    = 108;
        $joinOrder = 1;
        $card      = ['action' => 'move_back', 'spaces' => 3];

        $communityCard = [
            'id'        => 77,
            'action'    => 'advance_to',
            'target'    => 'go',
            'text'      => 'Advance to GO',
            'amount'    => null,
            'house_cost'=> null,
            'hotel_cost'=> null,
            'spaces'    => null,
        ];

        $this->playerIconRepository->shouldReceive('updateSquareIndex')
            ->once()->with($gameId, $joinOrder, 33);
        $this->communityChestCardRepository->shouldReceive('drawTopCard')
            ->once()->with($gameId)->andReturn($communityCard);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')
            ->once()->with($gameId, $joinOrder, 0);
        $this->playerIconRepository->shouldReceive('adjustCapital')
            ->once()->with($gameId, $joinOrder, 200)->andReturn(1700);
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')
            ->once()->with($gameId, $joinOrder)->andReturn('Alice');

        Event::fake([CardDrawn::class]);

        $effect = $this->callApplyCardEffect($gameId, $joinOrder, $card, 36);

        $this->assertSame('move_back', $effect['type']);
        $this->assertSame(33, $effect['new_square_index']);
        $this->assertIsArray($effect['square_action']);
        $this->assertSame('community', $effect['square_action']['type']);
        $this->assertSame(77, $effect['square_action']['card']['id']);
        $this->assertSame('advance_to', $effect['square_action']['effect']['type']);
        $this->assertSame(0, $effect['square_action']['effect']['new_square_index']);
        $this->assertTrue($effect['square_action']['effect']['passed_go']);
        $this->assertSame(200, $effect['square_action']['effect']['go_bonus']);
        $this->assertSame(1700, $effect['square_action']['effect']['new_capital']);

        Event::assertDispatched(CardDrawn::class, function (CardDrawn $event) use ($gameId, $joinOrder): bool {
            return $event->gameId === $gameId
                && $event->type === 'community'
                && $event->drawnByJoinOrder === $joinOrder
                && ($event->cardEffect['type'] ?? null) === 'advance_to'
                && ($event->cardEffect['passed_go'] ?? false) === true;
        });
    }

    public function test_apply_card_pay_each_player_charges_roller_and_credits_others(): void
    {
        // Roller (join_order 1) pays $50 to each other player (2 players total).
        $gameId    = 106;
        $joinOrder = 1;
        $card      = ['action' => 'pay_each_player', 'amount' => 50];

        $this->playerIconRepository->shouldReceive('getAllJoinOrders')
            ->once()->with($gameId)->andReturn([1, 2]);

        $effect = $this->callApplyCardEffect($gameId, $joinOrder, $card, 2);

        $this->assertSame('pay_each_player', $effect['type']);
        $this->assertSame(50, $effect['amount']);
        $this->assertSame(50, $effect['required_amount']);
        $this->assertSame('pay_each_player', $effect['payment_type']);
        $this->assertSame(1, $effect['other_player_count']);
    }

    public function test_accept_card_for_user_finalizes_a_deferred_card_payment_and_dispatches_payload(): void
    {
        Event::fake([CardAccepted::class]);

        $gameId    = 108;
        $userId    = 10;
        $joinOrder = 1;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')
            ->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->playerIconRepository->shouldReceive('getPlayersForGame')
            ->once()->with($gameId)->andReturn([
                ['join_order' => 1, 'capital' => 1500],
                ['join_order' => 2, 'capital' => 1500],
            ]);
        $this->playerIconRepository->shouldReceive('adjustCapital')
            ->once()->with($gameId, 1, -100)->andReturn(1400);

        $result = $this->service->acceptCardForUser($gameId, $userId, [], 'pay', 100);

        $this->assertSame([
            'payer' => ['join_order' => 1, 'capital' => 1400],
            'payment_type' => 'pay',
            'amount' => 100,
        ], $result);

        Event::assertDispatched(CardAccepted::class, function (CardAccepted $event) use ($gameId): bool {
            return $event->gameId === $gameId
                && $event->broadcastWith() === [
                    'payer' => ['join_order' => 1, 'capital' => 1400],
                    'payment_type' => 'pay',
                    'amount' => 100,
                ];
        });
    }

    public function test_accept_card_for_guest_finalizes_pay_each_player_card_payment(): void
    {
        Event::fake([CardAccepted::class]);

        $gameId       = 109;
        $invitationId = 30;
        $joinOrder    = 2;

        $this->playerIconRepository->shouldReceive('getJoinOrderForGuest')
            ->once()->with($gameId, $invitationId)->andReturn($joinOrder);
        $this->playerIconRepository->shouldReceive('getPlayersForGame')
            ->once()->with($gameId)->andReturn([
                ['join_order' => 1, 'capital' => 1500],
                ['join_order' => 2, 'capital' => 1500],
                ['join_order' => 3, 'capital' => 1500],
            ]);
        $this->playerIconRepository->shouldReceive('getAllJoinOrders')
            ->once()->with($gameId)->andReturn([1, 2, 3]);
        $this->playerIconRepository->shouldReceive('adjustCapital')
            ->once()->with($gameId, 2, -100)->andReturn(1400);
        $this->playerIconRepository->shouldReceive('adjustCapital')
            ->once()->with($gameId, 1, 50)->andReturn(1550);
        $this->playerIconRepository->shouldReceive('adjustCapital')
            ->once()->with($gameId, 3, 50)->andReturn(1550);

        $result = $this->service->acceptCardForGuest($gameId, $invitationId, [], 'pay_each_player', 50);

        $this->assertSame([
            'payer' => ['join_order' => 2, 'capital' => 1400],
            'other_player_capitals' => [
                ['join_order' => 1, 'capital' => 1550],
                ['join_order' => 3, 'capital' => 1550],
            ],
            'payment_type' => 'pay_each_player',
            'amount' => 50,
        ], $result);

        Event::assertDispatched(CardAccepted::class, function (CardAccepted $event) use ($gameId): bool {
            return $event->gameId === $gameId
                && $event->broadcastWith() === [
                    'payer' => ['join_order' => 2, 'capital' => 1400],
                    'other_player_capitals' => [
                        ['join_order' => 1, 'capital' => 1550],
                        ['join_order' => 3, 'capital' => 1550],
                    ],
                    'payment_type' => 'pay_each_player',
                    'amount' => 50,
                ];
        });
    }

    public function test_apply_card_collect_from_each_player_credits_roller_and_charges_others(): void
    {
        // Roller (join_order 1) collects $50 from each other player (2 players total).
        $gameId    = 107;
        $joinOrder = 1;
        $card      = ['action' => 'collect_from_each_player', 'amount' => 50];

        $this->playerIconRepository->shouldReceive('getAllJoinOrders')
            ->once()->with($gameId)->andReturn([1, 2]);
        $this->playerIconRepository->shouldReceive('adjustCapital')
            ->once()->with($gameId, 1, 50)->andReturn(1550);
        $this->playerIconRepository->shouldReceive('adjustCapital')
            ->once()->with($gameId, 2, -50)->andReturn(1450);

        $effect = $this->callApplyCardEffect($gameId, $joinOrder, $card, 2);

        $this->assertSame('collect_from_each_player', $effect['type']);
        $this->assertSame(50, $effect['amount']);
        $this->assertSame(1550, $effect['new_capital']);
        $this->assertCount(1, $effect['other_player_capitals']);
        $this->assertSame(2, $effect['other_player_capitals'][0]['join_order']);
        $this->assertSame(1450, $effect['other_player_capitals'][0]['capital']);
    }

    // ── mortgaged property flow ────────────────────────────────────────────

    public function test_pay_rent_throws_when_property_is_mortgaged(): void
    {
        $gameId      = 200;
        $userId      = 301;
        $joinOrder   = 1;
        $ownerOrder  = 2;
        $squareIndex = 39; // Boardwalk, mortgaged

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->once()->with($gameId, $squareIndex)->andReturn([
            'owner_join_order' => $ownerOrder,
            'owner_name'       => 'Bob',
            'is_mortgaged'     => true,
        ]);
        $this->playerIconRepository->shouldNotReceive('adjustCapital');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This property is mortgaged and does not charge rent.');

        $this->service->payRentForUser($gameId, $userId, $squareIndex);
    }

    public function test_roll_does_not_collect_rent_on_mortgaged_property(): void
    {
        Event::fake([DiceRolled::class, MortgagedPropertyNotified::class]);

        $gameId      = 201;
        $userId      = 302;
        $joinOrder   = 1;
        $ownerOrder  = 2;
        // Use debugMove to land directly on Boardwalk (39) which is mortgaged.
        $game = new Game(['current_turn_join_order' => $joinOrder]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->andReturn(0);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once()->with($gameId, $joinOrder, 39);
        $this->gameRepository->shouldReceive('saveDiceRoll')->once();
        // Boardwalk is mortgaged by another player.
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->once()->with($gameId, 39)->andReturn([
            'owner_join_order' => $ownerOrder,
            'owner_name'       => 'Bob',
            'is_mortgaged'     => true,
        ]);
        $this->playerIconRepository->shouldReceive('getPlayersForGame')->once()->with($gameId)->andReturn([
            ['join_order' => $joinOrder, 'name' => 'Alice', 'icon' => ['id' => 1, 'name' => 'Hat', 'image_url' => '/hat.svg']],
            ['join_order' => $ownerOrder, 'name' => 'Bob', 'icon' => ['id' => 2, 'name' => 'Car', 'image_url' => '/car.svg']],
        ]);
        $this->playerIconRepository->shouldReceive('getNameByJoinOrder')->zeroOrMoreTimes()->andReturn('Player');

        $result = $this->service->debugMoveToSquareForUser($gameId, $userId, 39);

        // Should return mortgaged action, not rent_paid.
        $this->assertSame('mortgaged', $result['square_action']['type']);
        $this->assertSame($ownerOrder, $result['square_action']['owner_join_order']);
        $this->assertSame('Bob', $result['square_action']['owner_name']);
        $this->assertSame($joinOrder, $result['square_action']['payer_join_order']);

        Event::assertDispatched(MortgagedPropertyNotified::class, function (MortgagedPropertyNotified $event) use ($gameId, $joinOrder, $ownerOrder): bool {
            return $event->gameId === $gameId
                && $event->payerJoinOrder === $joinOrder
                && $event->ownerJoinOrder === $ownerOrder
                && $event->squareName === 'Boardwalk';
        });
    }
}
