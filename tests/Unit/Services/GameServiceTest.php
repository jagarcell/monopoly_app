<?php

namespace Tests\Unit\Services;

use App\Models\Game;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use App\Repositories\GameRepository;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->gameRepository               = Mockery::mock(GameRepository::class);
        $this->chanceCardRepository         = Mockery::mock(ChanceCardRepository::class);
        $this->communityChestCardRepository = Mockery::mock(CommunityChestCardRepository::class);
        $this->service                      = new GameService(
            $this->gameRepository,
            $this->chanceCardRepository,
            $this->communityChestCardRepository,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_creates_first_game_named_game_1(): void
    {
        $userId   = 42;
        $game     = new Game(['name' => 'Game #1', 'user_id' => $userId]);
        $game->id = 1;

        $this->gameRepository->shouldReceive('countByUser')->once()->with($userId)->andReturn(0);
        $this->gameRepository->shouldReceive('create')->once()->with($userId, 'Game #1')->andReturn($game);
        $this->chanceCardRepository->shouldReceive('createDeckForGame')->once()->with(1);
        $this->communityChestCardRepository->shouldReceive('createDeckForGame')->once()->with(1);

        $result = $this->service->createGame($userId);

        $this->assertSame($game, $result);
        $this->assertSame('Game #1', $result->name);
    }

    public function test_creates_sequential_game_name_based_on_existing_count(): void
    {
        $userId   = 42;
        $game     = new Game(['name' => 'Game #5', 'user_id' => $userId]);
        $game->id = 5;

        $this->gameRepository->shouldReceive('countByUser')->once()->with($userId)->andReturn(4);
        $this->gameRepository->shouldReceive('create')->once()->with($userId, 'Game #5')->andReturn($game);
        $this->chanceCardRepository->shouldReceive('createDeckForGame')->once()->with(5);
        $this->communityChestCardRepository->shouldReceive('createDeckForGame')->once()->with(5);

        $result = $this->service->createGame($userId);

        $this->assertSame('Game #5', $result->name);
    }

    public function test_delegates_insert_to_repository(): void
    {
        $userId   = 7;
        $game     = new Game(['name' => 'Game #2', 'user_id' => $userId]);
        $game->id = 2;

        $this->gameRepository->shouldReceive('countByUser')->once()->with($userId)->andReturn(1);
        $this->gameRepository->shouldReceive('create')->once()->with($userId, 'Game #2')->andReturn($game);
        $this->chanceCardRepository->shouldReceive('createDeckForGame')->once()->with(2);
        $this->communityChestCardRepository->shouldReceive('createDeckForGame')->once()->with(2);

        $this->service->createGame($userId);

        // Mockery verifies the expectation automatically on tearDown.
        $this->assertTrue(true);
    }

    public function test_chance_deck_is_created_after_game_insert(): void
    {
        $userId   = 10;
        $game     = new Game(['name' => 'Game #3', 'user_id' => $userId]);
        $game->id = 3;

        $this->gameRepository->shouldReceive('countByUser')->once()->with($userId)->andReturn(2);
        $this->gameRepository->shouldReceive('create')->once()->with($userId, 'Game #3')->andReturn($game);
        $this->chanceCardRepository->shouldReceive('createDeckForGame')->once()->with($game->id);
        $this->communityChestCardRepository->shouldReceive('createDeckForGame')->once()->with($game->id);

        $result = $this->service->createGame($userId);

        $this->assertSame($game, $result);
    }

    public function test_community_chest_deck_is_created_after_game_insert(): void
    {
        $userId   = 15;
        $game     = new Game(['name' => 'Game #4', 'user_id' => $userId]);
        $game->id = 4;

        $this->gameRepository->shouldReceive('countByUser')->once()->with($userId)->andReturn(3);
        $this->gameRepository->shouldReceive('create')->once()->with($userId, 'Game #4')->andReturn($game);
        $this->chanceCardRepository->shouldReceive('createDeckForGame')->once()->with($game->id);
        $this->communityChestCardRepository->shouldReceive('createDeckForGame')->once()->with($game->id);

        $result = $this->service->createGame($userId);

        $this->assertSame($game, $result);
    }
}
