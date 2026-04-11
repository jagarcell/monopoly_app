<?php

namespace App\Services;

use App\Models\Game;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use App\Repositories\GameRepository;

class GameService
{
    public function __construct(
        private readonly GameRepository $gameRepository,
        private readonly ChanceCardRepository $chanceCardRepository,
        private readonly CommunityChestCardRepository $communityChestCardRepository,
    ) {}

    /**
     * Create a new game owned by the given user.
     *
     * Logic: Counts the user's existing games to derive the next sequential
     * number (e.g. "Game #1", "Game #2"), delegates the actual database insert
     * to the game repository, then creates a freshly shuffled Chance deck and a
     * freshly shuffled Community Chest deck for the new game.
     *
     * @param  int  $userId      The authenticated user's ID.
     * @param  int  $maxPlayers  The maximum number of players for the game (2–8).
     * @return Game
     */
    public function createGame(int $userId, int $maxPlayers): Game
    {
        $count = $this->gameRepository->countByUser($userId);
        $name  = 'Game #' . ($count + 1);

        $game = $this->gameRepository->create($userId, $name, $maxPlayers);

        $this->chanceCardRepository->createDeckForGame($game->id);
        $this->communityChestCardRepository->createDeckForGame($game->id);

        return $game;
    }

    /**
     * Draw the next Chance card for the given game.
     *
     * Logic: Delegates to ChanceCardRepository::drawTopCard, which draws the
     * card with the lowest sort_order and moves it to the bottom (sort_order 16)
     * so the deck cycles correctly over repeated plays.
     *
     * @param  int  $gameId  The ID of the game.
     * @return array<string, mixed>
     */
    public function drawChanceCard(int $gameId): array
    {
        return $this->chanceCardRepository->drawTopCard($gameId);
    }

    /**
     * Draw the next Community Chest card for the given game.
     *
     * Logic: Delegates to CommunityChestCardRepository::drawTopCard, which draws
     * the card with the lowest sort_order and moves it to the bottom (sort_order 16)
     * so the deck cycles correctly over repeated plays.
     *
     * @param  int  $gameId  The ID of the game.
     * @return array<string, mixed>
     */
    public function drawCommunityChestCard(int $gameId): array
    {
        return $this->communityChestCardRepository->drawTopCard($gameId);
    }
}
