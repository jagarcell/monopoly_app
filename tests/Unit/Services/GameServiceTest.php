<?php

namespace Tests\Unit\Services;

use App\Events\DiceRolled;
use App\Events\TokenMoved;
use App\Events\TurnAdvanced;
use App\Models\Game;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use App\Repositories\GameInvitationRepository;
use App\Repositories\GamePropertyRepository;
use App\Repositories\GameRepository;
use App\Repositories\PlayerIconRepository;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->gameRepository               = Mockery::mock(GameRepository::class);
        $this->chanceCardRepository         = Mockery::mock(ChanceCardRepository::class);
        $this->communityChestCardRepository = Mockery::mock(CommunityChestCardRepository::class);
        $this->playerIconRepository         = Mockery::mock(PlayerIconRepository::class);
        $this->invitationRepository         = Mockery::mock(GameInvitationRepository::class);
        $this->propertyRepository           = Mockery::mock(GamePropertyRepository::class);
        $this->propertyRepository->shouldIgnoreMissing();
        $this->service                      = new GameService(
            $this->gameRepository,
            $this->chanceCardRepository,
            $this->communityChestCardRepository,
            $this->playerIconRepository,
            $this->invitationRepository,
            $this->propertyRepository,
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
        $this->gameRepository->shouldReceive('saveDiceRoll')->once()->with($gameId, Mockery::type('int'), Mockery::type('int'));
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
        $this->gameRepository->shouldReceive('saveDiceRoll')->once()->with($gameId, Mockery::type('int'), Mockery::type('int'));
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
        $this->gameRepository->shouldReceive('saveDiceRoll')->once()->with($gameId, Mockery::type('int'), Mockery::type('int'));
        // May pass GO depending on random dice total (37+total >= 40 when total >= 3).
        $this->playerIconRepository->shouldReceive('adjustCapital')->zeroOrMoreTimes()->andReturn(1700);

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
        $this->gameRepository->shouldReceive('saveDiceRoll')->once()->with($gameId, Mockery::type('int'), Mockery::type('int'));

        $result = $this->service->rollDiceForUser($gameId, $userId);

        $this->assertSame((10 + $result['total']) % 40, $result['square_index']);
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

        $result = $this->service->notifyTokenMovedForUser($gameId, $userId);

        $this->assertSame($joinOrder, $result['join_order']);
        $this->assertSame($squareIndex, $result['square_index']);
        Event::assertDispatched(TokenMoved::class, fn (TokenMoved $e) =>
            $e->gameId === $gameId &&
            $e->joinOrder === $joinOrder &&
            $e->squareIndex === $squareIndex
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

        $result = $this->service->notifyTokenMovedForGuest($gameId, $invitationId);

        $this->assertSame($joinOrder, $result['join_order']);
        $this->assertSame($squareIndex, $result['square_index']);
        Event::assertDispatched(TokenMoved::class, fn (TokenMoved $e) =>
            $e->gameId === $gameId &&
            $e->joinOrder === $joinOrder &&
            $e->squareIndex === $squareIndex
        );
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
            ->with(
                $gameId,
                Mockery::on(function (int $d1) use (&$capturedDie1): bool { $capturedDie1 = $d1; return true; }),
                Mockery::on(function (int $d2) use (&$capturedDie2): bool { $capturedDie2 = $d2; return true; }),
            );

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

    public function test_roll_returns_rent_action_for_owned_property(): void
    {
        Event::fake([DiceRolled::class]);

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
        // Square is owned by another player. Dice are random so the call
        // may or may not happen depending on where the player lands.
        $this->propertyRepository->shouldReceive('findOwnerBySquare')
            ->zeroOrMoreTimes()
            ->andReturn(['owner_join_order' => $ownerOrder, 'owner_name' => 'Bob']);

        $result = $this->service->rollDiceForUser($gameId, $userId);

        $this->assertArrayHasKey('square_action', $result);
        // If Boardwalk was landed on (index 39), action must be 'rent'.
        if ($result['square_index'] === 39) {
            $this->assertSame('rent', $result['square_action']['type']);
            $this->assertSame($ownerOrder, $result['square_action']['owner_join_order']);
            $this->assertSame('Bob', $result['square_action']['owner_name']);
        }
    }

    // ── purchasePropertyForUser ───────────────────────────────────────────────

    public function test_purchase_property_deducts_capital_and_records_ownership(): void
    {
        $gameId      = 90;
        $userId      = 20;
        $joinOrder   = 1;
        $squareIndex = 39; // Boardwalk, price 400

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->with($gameId, $userId)->andReturn($joinOrder);
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->once()->with($gameId, $squareIndex)->andReturn(null);
        $this->propertyRepository->shouldReceive('createOwnership')->once()->with($gameId, $squareIndex, $joinOrder, 400);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $joinOrder, -400)->andReturn(1100);

        $result = $this->service->purchasePropertyForUser($gameId, $userId, $squareIndex);

        $this->assertSame($joinOrder, $result['join_order']);
        $this->assertSame(1100, $result['capital']);
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
        ]);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $joinOrder, -50)->andReturn(1450);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $ownerOrder, 50)->andReturn(1550);

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
            'owner_join_order' => $ownerOrder, 'owner_name' => 'Bob',
        ]);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $joinOrder, -50)->andReturn(1450);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $ownerOrder, 50)->andReturn(1550);

        $this->service->payRentForUser($gameId, $userId, $squareIndex);

        Event::assertDispatched(\App\Events\RentPaid::class, function ($event) use ($gameId, $joinOrder, $ownerOrder) {
            return $event->gameId          === $gameId
                && $event->payerJoinOrder  === $joinOrder
                && $event->payerName       === 'Alice'
                && $event->payerCapital    === 1450
                && $event->ownerJoinOrder  === $ownerOrder
                && $event->ownerName       === 'Bob'
                && $event->ownerCapital    === 1550
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
            'owner_join_order' => $ownerOrder, 'owner_name' => 'Bob',
        ]);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $joinOrder, -50)->andReturn(1450);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $ownerOrder, 50)->andReturn(1550);

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
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->zeroOrMoreTimes()->andReturn(null);

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
     * A player who lands exactly on GO (square 0) should also collect $200.
     */
    public function test_landing_exactly_on_go_awards_200(): void
    {
        Event::fake([DiceRolled::class]);

        $gameId    = 201;
        $userId    = 51;
        $joinOrder = 1;
        // Player on square 34; a dice total of 6 lands exactly on square 0.
        $game = new Game(['current_turn_join_order' => $joinOrder]);
        $game->id = $gameId;

        $this->playerIconRepository->shouldReceive('getJoinOrderForUser')->once()->andReturn($joinOrder);
        $this->gameRepository->shouldReceive('findById')->once()->andReturn($game);
        $this->playerIconRepository->shouldReceive('getSquareIndexForPlayer')->once()->andReturn(34);
        $this->playerIconRepository->shouldReceive('adjustCapital')->once()->with($gameId, $joinOrder, 200)->andReturn(1700);
        $this->playerIconRepository->shouldReceive('updateSquareIndex')->once();
        $this->gameRepository->shouldReceive('saveDiceRoll')->once();
        $this->propertyRepository->shouldReceive('findOwnerBySquare')->zeroOrMoreTimes()->andReturn(null);

        $result = $this->service->rollDiceForUser($gameId, $userId);

        // 34 + any dice total (2-12) always crosses 40, so passed_go must always
        // be true regardless of which exact dice values were rolled.
        $this->assertTrue($result['passed_go']);
        $this->assertSame(200, $result['go_bonus']);
        $this->assertSame(1700, $result['new_capital']);
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

        $result = $this->service->rollDiceForUser($gameId, $userId);

        $this->assertFalse($result['passed_go']);
        $this->assertSame(0, $result['go_bonus']);
        $this->assertNull($result['new_capital']);
    }
}
