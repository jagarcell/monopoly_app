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
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

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
        // Provide a minimal container binding for the DB facade used in sell methods
        $container = new Container();
        $container->bind('db', function () {
            return new class {
                public function table($name)
                {
                    return new class {
                        public function where(...$args)
                        {
                            return $this;
                        }
                        public function select(...$args)
                        {
                            return $this;
                        }
                        public function first()
                        {
                            return (object) ['purchase_price' => 100];
                        }
                    };
                }

                public function transaction($callable)
                {
                    return $callable();
                }
            };
        });
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
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
        $joinOrder = 2;
        $this->gameRepo->shouldReceive('findById')->with($gameId)->andReturn(Mockery::mock(\App\Models\Game::class));

        // Owner missing for one square in group
        $this->propRepo->shouldReceive('findOwnerBySquare')->with($gameId, 1)->andReturn(['owner_join_order' => 2, 'is_mortgaged' => false]);
        $this->propRepo->shouldReceive('findOwnerBySquare')->with($gameId, 3)->andReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('You must own the entire colour group to build.');

        $this->service->buildHouse($gameId, $joinOrder, $square);
    }

    public function test_build_blocked_by_mortgage()
    {
        $gameId = 1; $userId = 10; $square = 1;
        $joinOrder = 2;
        $this->gameRepo->shouldReceive('findById')->with($gameId)->andReturn(Mockery::mock(\App\Models\Game::class));

        $this->propRepo->shouldReceive('findOwnerBySquare')->with($gameId, 1)->andReturn(['owner_join_order' => 2, 'is_mortgaged' => false]);
        $this->propRepo->shouldReceive('findOwnerBySquare')->with($gameId, 3)->andReturn(['owner_join_order' => 2, 'is_mortgaged' => true]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('You cannot build while a property in the set is mortgaged.');

        $this->service->buildHouse($gameId, $joinOrder, $square);
    }

    public function test_even_building_enforced()
    {
        $gameId = 1; $userId = 10; $square = 6; // light blue group 6,8,9
        $joinOrder = 2;
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

        $result = $this->service->buildHouse($gameId, $joinOrder, $square);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['pending_houses']);
    }

    public function test_sell_house_even_selling()
    {
        $gameId = 1; $userId = 10; $square = 6; // light blue group 6,8,9
        $joinOrder = 2;
        $this->gameRepo->shouldReceive('findById')->with($gameId)->andReturn(Mockery::mock(\App\Models\Game::class));

        foreach ([6,8,9] as $sq) {
            $this->propRepo->shouldReceive('findOwnerBySquare')->with($gameId, $sq)->andReturn(['owner_join_order' => 2, 'is_mortgaged' => false]);
        }

        // current houses: 1,1,1 -> selling one on 6 => 0,1,1 (difference 1) allowed
        $this->propRepo->shouldReceive('getBuildingsForSquares')->with($gameId, [6,8,9])->andReturn([
            6 => ['square_index' => 6, 'houses_count' => 1, 'has_hotel' => false],
            8 => ['square_index' => 8, 'houses_count' => 1, 'has_hotel' => false],
            9 => ['square_index' => 9, 'houses_count' => 1, 'has_hotel' => false],
        ]);

        $this->pendingBuildRepo->shouldReceive('getPendingBuildsForGame')->with($gameId)->andReturn([]);

        // Expect property to be updated to zero houses
        $this->playerRepo->shouldReceive('adjustCapital')->with($gameId, 2, Mockery::any())->andReturn(1525);
        $this->propRepo->shouldReceive('setBuildingsForSquare')->with($gameId, $square, 0, false)->once();

        $result = $this->service->sellHouse($gameId, $joinOrder, $square);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['sold_houses']);
    }

    public function test_sell_hotel_replaced_with_4_houses()
    {
        $gameId = 1; $userId = 10; $square = 6; // light blue group 6,8,9
        $joinOrder = 2;
        $this->gameRepo->shouldReceive('findById')->with($gameId)->andReturn(Mockery::mock(\App\Models\Game::class));

        foreach ([6,8,9] as $sq) {
            $this->propRepo->shouldReceive('findOwnerBySquare')->with($gameId, $sq)->andReturn(['owner_join_order' => 2, 'is_mortgaged' => false]);
        }

        $this->propRepo->shouldReceive('getBuildingsForSquares')->with($gameId, [6,8,9])->andReturn([
            6 => ['square_index' => 6, 'houses_count' => 0, 'has_hotel' => true],
            8 => ['square_index' => 8, 'houses_count' => 4, 'has_hotel' => false],
            9 => ['square_index' => 9, 'houses_count' => 4, 'has_hotel' => false],
        ]);

        $this->pendingBuildRepo->shouldReceive('getPendingBuildsForGame')->with($gameId)->andReturn([]);

        // Expect hotel replaced with 4 houses
        $this->playerRepo->shouldReceive('adjustCapital')->with($gameId, 2, Mockery::any())->andReturn(1600);
        $this->propRepo->shouldReceive('setBuildingsForSquare')->with($gameId, $square, 4, false)->once();

        $result = $this->service->sellHotel($gameId, $joinOrder, $square);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['sold_hotel']);
    }
}
