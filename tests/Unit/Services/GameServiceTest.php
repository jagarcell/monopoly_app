<?php

namespace Tests\Unit\Services;

use App\Events\DiceRolled;
use App\Events\TokenMoved;
use App\Events\TurnAdvanced;
use App\Models\Game;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use App\Repositories\GameInvitationRepository;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->gameRepository               = Mockery::mock(GameRepository::class);
        $this->chanceCardRepository         = Mockery::mock(ChanceCardRepository::class);
        $this->communityChestCardRepository = Mockery::mock(CommunityChestCardRepository::class);
        $this->playerIconRepository         = Mockery::mock(PlayerIconRepository::class);
        $this->invitationRepository         = Mockery::mock(GameInvitationRepository::class);
        $this->service                      = new GameService(
            $this->gameRepository,
            $this->chanceCardRepository,
            $this->communityChestCardRepository,
            $this->playerIconRepository,
            $this->invitationRepository,
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
}
