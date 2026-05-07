<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGameRequest;
use App\Repositories\GameRepository;
use App\Services\GameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class GameController extends Controller
{
    public function __construct(
        private readonly GameService    $gameService,
        private readonly GameRepository $gameRepository,
    ) {}

    /**
     * Create a new game for the authenticated user.
     *
     * Logic: Validates the request via StoreGameRequest, delegates game creation
     * to GameService, then calls GameService::getPlayersForGame to build the
     * initial `players` array ordered by join_order. Returns both `game` and
     * `players` as JSON 201.
     *
     * @param  StoreGameRequest  $request  The validated incoming HTTP request.
     * @return JsonResponse
     */
    public function store(StoreGameRequest $request): JsonResponse
    {
        try {
            $game = $this->gameService->createGame(
                $request->user()->id,
                (int) $request->validated('max_players'),
                (int) $request->validated('player_icon_id'),
            );

            $players = $this->gameService->getPlayersForGame($game->id);

            return response()->json(['game' => $game, 'players' => $players], 201);
        } catch (\Throwable $e) {
            Log::error('Failed to create game', [
                'user_id'   => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to create game.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Render the creator's game board page.
     *
     * Logic: Looks up the game by its primary key. Returns 404 when the game
     * does not exist. Returns 403 when the authenticated user is not the game
     * creator. Otherwise calls GameService::getPlayersForGame to retrieve the
     * full, join_order-sorted player list and renders the Inertia Game page
     * with both game and players props so that a page refresh re-hydrates the
     * board from the server without losing state.
     *
     * @param  Request  $request  The authenticated HTTP request.
     * @param  int      $gameId   The primary key of the game to display.
     * @return InertiaResponse|JsonResponse
     */
    public function show(Request $request, int $gameId): InertiaResponse|JsonResponse
    {
        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            if ($game->user_id !== $request->user()->id) {
                return response()->json(['message' => 'Forbidden.', 'errors' => []], 403);
            }

            $players = $this->gameService->getPlayersForGame($gameId);
            $pendingInvitations = $this->gameService->getPendingInvitationsForGame($gameId);

            return Inertia::render('Game', [
                'game'               => $game,
                'players'            => $players,
                'pendingInvitations' => $pendingInvitations,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to load game board', [
                'game_id'   => $gameId,
                'user_id'   => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to load game.',
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

    /**
     * Roll the dice for the authenticated player.
     *
     * Logic: Verifies the game exists. Delegates the roll to GameService which
     * validates it is the caller's turn, generates the dice values, advances the
     * turn, and dispatches the DiceRolled broadcast. Returns die1, die2, total,
     * and the new current_turn_join_order as JSON 200. Returns 422 when it is not
     * the caller's turn or the player is not a participant.
     *
     * @param  Request  $request  The authenticated HTTP request.
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function rollDice(Request $request, int $gameId): JsonResponse
    {
        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $result = $this->gameService->rollDiceForUser($gameId, $request->user()->id);

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to roll dice', [
                'game_id'   => $gameId,
                'user_id'   => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to roll dice.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Signal that the authenticated player has completed their turn.
     *
     * Logic: Verifies the game exists, then delegates to GameService::endTurnForUser
     * which validates it is the caller's turn, cyclically computes the next
     * join_order, persists the update, and dispatches the TurnAdvanced broadcast.
     * Returns 422 when it is not the caller's turn or they are not a participant.
     *
     * @param  Request  $request  The authenticated HTTP request.
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function endTurn(Request $request, int $gameId): JsonResponse
    {
        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $result = $this->gameService->endTurnForUser($gameId, $request->user()->id);

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to end turn', [
                'game_id'   => $gameId,
                'user_id'   => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to end turn.',
                'errors'  => [],
            ], 500);
        }
    }
}
