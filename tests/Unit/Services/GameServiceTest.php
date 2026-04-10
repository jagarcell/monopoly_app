<?php

namespace Tests\Unit\Services;

use App\Models\Game;
use App\Repositories\GameRepository;
use App\Services\GameService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class GameServiceTest extends TestCase
{
    private GameService $service;
    private MockInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(GameRepository::class);
        $this->service    = new GameService($this->repository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_creates_first_game_named_game_1(): void
    {
        $userId = 42;
        $game   = new Game(['id' => 1, 'name' => 'Game #1', 'user_id' => $userId]);

        $this->repository->shouldReceive('countByUser')->once()->with($userId)->andReturn(0);
        $this->repository->shouldReceive('create')->once()->with($userId, 'Game #1')->andReturn($game);

        $result = $this->service->createGame($userId);

        $this->assertSame($game, $result);
        $this->assertSame('Game #1', $result->name);
    }

    public function test_creates_sequential_game_name_based_on_existing_count(): void
    {
        $userId = 42;
        $game   = new Game(['id' => 5, 'name' => 'Game #5', 'user_id' => $userId]);

        $this->repository->shouldReceive('countByUser')->once()->with($userId)->andReturn(4);
        $this->repository->shouldReceive('create')->once()->with($userId, 'Game #5')->andReturn($game);

        $result = $this->service->createGame($userId);

        $this->assertSame('Game #5', $result->name);
    }

    public function test_delegates_insert_to_repository(): void
    {
        $userId = 7;
        $game   = new Game(['id' => 2, 'name' => 'Game #2', 'user_id' => $userId]);

        $this->repository->shouldReceive('countByUser')->once()->with($userId)->andReturn(1);
        $this->repository->shouldReceive('create')->once()->with($userId, 'Game #2')->andReturn($game);

        $this->service->createGame($userId);

        // Mockery verifies the expectation automatically on tearDown.
        $this->assertTrue(true);
    }
}
