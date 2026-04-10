<?php

namespace App\Services;

use App\Models\Game;
use App\Repositories\GameRepository;

class GameService
{
    public function __construct(
        private readonly GameRepository $gameRepository,
    ) {}

    /**
     * Create a new game owned by the given user.
     *
     * Logic: Counts the user's existing games to derive the next sequential
     * number (e.g. "Game #1", "Game #2"), then delegates the actual database
     * insert to the repository and returns the freshly created Game model.
     *
     * @param  int  $userId  The authenticated user's ID.
     * @return Game
     */
    public function createGame(int $userId): Game
    {
        $count = $this->gameRepository->countByUser($userId);
        $name  = 'Game #' . ($count + 1);

        return $this->gameRepository->create($userId, $name);
    }
}
