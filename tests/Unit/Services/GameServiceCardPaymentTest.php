<?php

namespace Tests\Unit\Services;

use Mockery;
use Tests\TestCase;
use App\Services\GameService;
use App\Repositories\GameRepository;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use App\Repositories\PlayerIconRepository;
use App\Repositories\GameInvitationRepository;
use App\Repositories\GamePropertyRepository;
use App\Repositories\GamePendingBuildRepository;

class GameServiceCardPaymentTest extends TestCase
{
    protected function tearDown(): void
    {
        // Restore any global error/exception handlers that the service may have set
        if (function_exists('restore_error_handler')) {
            @restore_error_handler();
        }
        if (function_exists('restore_exception_handler')) {
            @restore_exception_handler();
        }

        Mockery::close();
        parent::tearDown();
    }

    private function makeService(array $mocks)
    {
        return new GameService(
            $mocks['gameRepository'],
            $mocks['chanceCardRepository'],
            $mocks['communityChestCardRepository'],
            $mocks['playerIconRepository'],
            $mocks['invitationRepository'],
            $mocks['propertyRepository'],
            $mocks['pendingBuildRepository'],
        );
    }

    public function test_advance_to_nearest_utility_insufficient_returns_deferred_rent()
    {
        $gameId = 1;
        $roller = 2;
        $targetSquare = 12; // utility

        $gameRepo = Mockery::mock(GameRepository::class);
        $chanceRepo = Mockery::mock(ChanceCardRepository::class);
        $communityRepo = Mockery::mock(CommunityChestCardRepository::class);
        $playerRepo = Mockery::mock(PlayerIconRepository::class);
        $invRepo = Mockery::mock(GameInvitationRepository::class);
        $propRepo = Mockery::mock(GamePropertyRepository::class);
        $pendingRepo = Mockery::mock(GamePendingBuildRepository::class);

        // Owner exists and is not mortgaged
        $propRepo->shouldReceive('findOwnerBySquare')->with($gameId, $targetSquare)->andReturn([
            'owner_join_order' => 3,
            'owner_name' => 'Owner',
            'is_mortgaged' => false,
        ]);

        // Player capital low so insufficient regardless of dice
        $playerRepo->shouldReceive('getPlayersForGame')->with($gameId)->andReturn([
            ['join_order' => $roller, 'capital' => 0],
            ['join_order' => 3, 'capital' => 500],
        ]);
        // Allow lifecycle mutations invoked by applyCardEffect
        $playerRepo->shouldReceive('setJailState')->with($gameId, $roller, false)->andReturnNull();
        $playerRepo->shouldReceive('updateSquareIndex')->with($gameId, $roller, Mockery::any())->andReturnNull();
        $playerRepo->shouldReceive('adjustCapital')->andReturnUsing(function ($g, $jo, $amt) use ($roller) {
            return $jo === $roller ? max(0, ($amt < 0 ? 0 : $amt)) : 500;
        });

        $mocks = [
            'gameRepository' => $gameRepo,
            'chanceCardRepository' => $chanceRepo,
            'communityChestCardRepository' => $communityRepo,
            'playerIconRepository' => $playerRepo,
            'invitationRepository' => $invRepo,
            'propertyRepository' => $propRepo,
            'pendingBuildRepository' => $pendingRepo,
        ];

        $svc = $this->makeService($mocks);

        // Use reflection to call private applyCardEffect
        $ref = new \ReflectionMethod(GameService::class, 'applyCardEffect');
        $ref->setAccessible(true);

        $card = ['action' => 'advance_to_nearest', 'target' => 'utility'];

        $result = $ref->invoke($svc, $gameId, $roller, $card, 10);

        $this->assertIsArray($result);
        $this->assertEquals('advance_to_nearest', $result['type']);
        $this->assertArrayHasKey('square_action', $result);
        $squareAction = $result['square_action'];
        $this->assertEquals('rent', $squareAction['type']);
        $this->assertArrayHasKey('rent', $squareAction);
        $this->assertEquals(3, $squareAction['owner_join_order']);
    }

    public function test_advance_to_nearest_railroad_insufficient_returns_deferred_rent()
    {
        $gameId = 1;
        $roller = 2;
        $targetSquare = 15; // railroad

        $gameRepo = Mockery::mock(GameRepository::class);
        $chanceRepo = Mockery::mock(ChanceCardRepository::class);
        $communityRepo = Mockery::mock(CommunityChestCardRepository::class);
        $playerRepo = Mockery::mock(PlayerIconRepository::class);
        $invRepo = Mockery::mock(GameInvitationRepository::class);
        $propRepo = Mockery::mock(GamePropertyRepository::class);
        $pendingRepo = Mockery::mock(GamePendingBuildRepository::class);

        // Owner exists and is not mortgaged
        $propRepo->shouldReceive('findOwnerBySquare')->with($gameId, $targetSquare)->andReturn([
            'owner_join_order' => 3,
            'owner_name' => 'Owner',
            'is_mortgaged' => false,
        ]);

        // Owner properties for computed railroad count
        $propRepo->shouldReceive('findPlayerProperties')->with($gameId, 3)->andReturn([
            ['square_index' => 5, 'is_mortgaged' => false],
            ['square_index' => 15, 'is_mortgaged' => false],
        ]);

        // Player capital low so insufficient
        $playerRepo->shouldReceive('getPlayersForGame')->with($gameId)->andReturn([
            ['join_order' => $roller, 'capital' => 0],
            ['join_order' => 3, 'capital' => 500],
        ]);
        // Allow lifecycle mutations invoked by applyCardEffect
        $playerRepo->shouldReceive('setJailState')->with($gameId, $roller, false)->andReturnNull();
        $playerRepo->shouldReceive('updateSquareIndex')->with($gameId, $roller, Mockery::any())->andReturnNull();
        $playerRepo->shouldReceive('adjustCapital')->andReturnUsing(function ($g, $jo, $amt) use ($roller) {
            return $jo === $roller ? max(0, ($amt < 0 ? 0 : $amt)) : 500;
        });

        $mocks = [
            'gameRepository' => $gameRepo,
            'chanceCardRepository' => $chanceRepo,
            'communityChestCardRepository' => $communityRepo,
            'playerIconRepository' => $playerRepo,
            'invitationRepository' => $invRepo,
            'propertyRepository' => $propRepo,
            'pendingBuildRepository' => $pendingRepo,
        ];

        $svc = $this->makeService($mocks);

        $ref = new \ReflectionMethod(GameService::class, 'applyCardEffect');
        $ref->setAccessible(true);

        $card = ['action' => 'advance_to_nearest', 'target' => 'railroad'];

        $result = $ref->invoke($svc, $gameId, $roller, $card, 10);

        $this->assertIsArray($result);
        $this->assertEquals('advance_to_nearest', $result['type']);
        $this->assertArrayHasKey('square_action', $result);
        $squareAction = $result['square_action'];
        $this->assertEquals('rent', $squareAction['type']);
        $this->assertArrayHasKey('rent', $squareAction);
        $this->assertEquals(3, $squareAction['owner_join_order']);
    }
}
