<?php

namespace App\Http\Controllers;

use App\Repositories\GameRepository;
use App\Services\GameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GameController extends Controller
{
    public function __construct(
        private readonly GameService    $gameService,
        private readonly GameRepository $gameRepository,
    ) {}

    /**
     * Create a new game for the authenticated user.
     *
     * Logic: Delegates game creation to GameService, which auto-names the game
     * based on the user's existing game count, then returns the created game
     * record as a JSON response with HTTP 201.
     *
     * @param  Request  $request  The incoming HTTP request (must be authenticated).
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $game = $this->gameService->createGame($request->user()->id);

            return response()->json(['game' => $game], 201);
        } catch (\Throwable $e) {
            Log::error('Failed to create game', [
                'user_id' => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to create game.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Draw the next Chance card for the given game.
     *
     * Logic: Looks up the game, verifies the authenticated user owns it, then
     * delegates the draw to GameService. The drawn card (the top of the deck by
     * sort_order) is returned and automatically moved to the bottom of the deck.
     *
     * @param  Request  $request  The incoming HTTP request (must be authenticated).
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function drawChanceCard(Request $request, int $gameId): JsonResponse
    {
        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            if ($game->user_id !== $request->user()->id) {
                return response()->json(['message' => 'Forbidden.', 'errors' => []], 403);
            }

            $card = $this->gameService->drawChanceCard($gameId);

            return response()->json(['card' => $card]);
        } catch (\Throwable $e) {
            Log::error('Failed to draw chance card', [
                'game_id'   => $gameId,
                'user_id'   => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to draw card.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Draw the next Community Chest card for the given game.
     *
     * Logic: Looks up the game, verifies the authenticated user owns it, then
     * delegates the draw to GameService. The drawn card (the top of the deck by
     * sort_order) is returned and automatically moved to the bottom of the deck.
     *
     * @param  Request  $request  The incoming HTTP request (must be authenticated).
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function drawCommunityChestCard(Request $request, int $gameId): JsonResponse
    {
        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            if ($game->user_id !== $request->user()->id) {
                return response()->json(['message' => 'Forbidden.', 'errors' => []], 403);
            }

            $card = $this->gameService->drawCommunityChestCard($gameId);

            return response()->json(['card' => $card]);
        } catch (\Throwable $e) {
            Log::error('Failed to draw community chest card', [
                'game_id'   => $gameId,
                'user_id'   => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to draw card.',
                'errors'  => [],
            ], 500);
        }
    }
}
