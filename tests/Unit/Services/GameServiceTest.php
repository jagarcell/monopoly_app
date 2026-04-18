<?php

namespace Tests\Unit\Services;

use App\Models\Game;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use App\Repositories\GameRepository;
use App\Repositories\PlayerIconRepository;
use App\Services\GameService;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->gameRepository               = Mockery::mock(GameRepository::class);
        $this->chanceCardRepository         = Mockery::mock(ChanceCardRepository::class);
        $this->communityChestCardRepository = Mockery::mock(CommunityChestCardRepository::class);
        $this->playerIconRepository         = Mockery::mock(PlayerIconRepository::class);
        $this->service                      = new GameService(
            $this->gameRepository,
            $this->chanceCardRepository,
            $this->communityChestCardRepository,
            $this->playerIconRepository,
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
}
