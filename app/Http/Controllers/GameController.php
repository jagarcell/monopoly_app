<?php

namespace App\Http\Controllers;

use App\Services\GameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GameController extends Controller
{
    public function __construct(
        private readonly GameService $gameService,
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
}
