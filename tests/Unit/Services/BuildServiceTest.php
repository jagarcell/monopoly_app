<?php

namespace Tests\Unit\Services;

use App\Repositories\GamePropertyRepository;
use App\Repositories\PlayerIconRepository;
use App\Repositories\GameRepository;
use App\Services\BuildService;
use App\Repositories\GamePendingBuildRepository;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

class BuildServiceTest extends TestCase
{
    /** @var MockInterface|GamePropertyRepository */
    private $propRepo;

    /** @var MockInterface|PlayerIconRepository */
    private $playerRepo;

    /** @var MockInterface|GameRepository */
    private $gameRepo;

    /** @var MockInterface|GamePendingBuildRepository */
    private $pendingBuildRepo;

    private BuildService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->propRepo = Mockery::mock(GamePropertyRepository::class);
        $this->playerRepo = Mockery::mock(PlayerIconRepository::class);
        $this->gameRepo = Mockery::mock(GameRepository::class);
        $this->pendingBuildRepo = Mockery::mock(GamePendingBuildRepository::class);
        $this->pendingBuildRepo->shouldIgnoreMissing();

        $this->service = new BuildService($this->propRepo, $this->playerRepo, $this->gameRepo, $this->pendingBuildRepo);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_build_blocked_when_not_full_set()
    {
        $gameId = 1; $userId = 10; $square = 1;

        $this->playerRepo->shouldReceive('getJoinOrderForUser')->with($gameId, $userId)->andReturn(2);
        $this->playerRepo->shouldReceive('getJoinOrderForGuest')->andReturnNull();
        $this->gameRepo->shouldReceive('findById')->with($gameId)->andReturn(Mockery::mock(\App\Models\Game::class));

        // Owner missing for one square in group
        $this->propRepo->shouldReceive('findOwnerBySquare')->with($gameId, 1)->andReturn(['owner_join_order' => 2, 'is_mortgaged' => false]);
        $this->propRepo->shouldReceive('findOwnerBySquare')->with($gameId, 3)->andReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('You must own the entire colour group to build.');

        $this->service->buildHouse($gameId, $userId, $square);
    }

    public function test_build_blocked_by_mortgage()
    {
        $gameId = 1; $userId = 10; $square = 1;

        $this->playerRepo->shouldReceive('getJoinOrderForUser')->with($gameId, $userId)->andReturn(2);
        $this->playerRepo->shouldReceive('getJoinOrderForGuest')->andReturnNull();
        $this->gameRepo->shouldReceive('findById')->with($gameId)->andReturn(Mockery::mock(\App\Models\Game::class));

        $this->propRepo->shouldReceive('findOwnerBySquare')->with($gameId, 1)->andReturn(['owner_join_order' => 2, 'is_mortgaged' => false]);
        $this->propRepo->shouldReceive('findOwnerBySquare')->with($gameId, 3)->andReturn(['owner_join_order' => 2, 'is_mortgaged' => true]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('You cannot build while a property in the set is mortgaged.');

        $this->service->buildHouse($gameId, $userId, $square);
    }

    public function test_even_building_enforced()
    {
        $gameId = 1; $userId = 10; $square = 6; // light blue group 6,8,9

        $this->playerRepo->shouldReceive('getJoinOrderForUser')->with($gameId, $userId)->andReturn(2);
        $this->playerRepo->shouldReceive('getJoinOrderForGuest')->andReturnNull();
        $this->gameRepo->shouldReceive('findById')->with($gameId)->andReturn(Mockery::mock(\App\Models\Game::class));

        foreach ([6,8,9] as $sq) {
            $this->propRepo->shouldReceive('findOwnerBySquare')->with($gameId, $sq)->andReturn(['owner_join_order' => 2, 'is_mortgaged' => false]);
        }

        // current houses: 0,0,0 -> building on 6 => 1,0,0 is invalid (difference >1)
        $this->propRepo->shouldReceive('getBuildingsForSquares')->with($gameId, [6,8,9])->andReturn([
            6 => ['square_index' => 6, 'houses_count' => 0, 'has_hotel' => false],
            8 => ['square_index' => 8, 'houses_count' => 0, 'has_hotel' => false],
            9 => ['square_index' => 9, 'houses_count' => 0, 'has_hotel' => false],
        ]);
        $this->propRepo->shouldReceive('countTotalHouses')->with($gameId)->andReturn(0);

        // Building the first house on an empty group is allowed (difference <= 1).
        // New behaviour queues pending builds rather than writing immediately.
        $this->pendingBuildRepo->shouldReceive('addPendingBuild')->with($gameId, 2, $square, 1, false)->once();

        $result = $this->service->buildHouse($gameId, $userId, $square);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['pending_houses']);
    }
}
