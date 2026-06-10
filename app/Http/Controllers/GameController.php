<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGameRequest;
use App\Repositories\GameRepository;
use App\Repositories\PlayerIconRepository;
use App\Services\GameService;
use App\Events\PropertyBuilt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class GameController extends Controller
{
    public function __construct(
        private readonly GameService    $gameService,
        private readonly GameRepository $gameRepository,
        private readonly \App\Services\BuildService $buildService,
        private readonly PlayerIconRepository $playerIconRepository,
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
        * delegates the draw to GameService. The next active card (excluding any
        * get-out-of-jail-free card currently held by a player) is returned.
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

            $result = $this->gameService->drawChanceCardForUser($gameId, $request->user()->id);

            return response()->json(['card' => $result['card'], 'effect' => $result['effect']]);
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
        * delegates the draw to GameService. The next active card (excluding any
        * get-out-of-jail-free card currently held by a player) is returned.
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

            $result = $this->gameService->drawCommunityChestCardForUser($gameId, $request->user()->id);

            return response()->json(['card' => $result['card'], 'effect' => $result['effect']]);
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
     * Return the ordered Chance deck for debugging.
     *
     * Logic: Verifies debug_mode is enabled, confirms the caller is a participant
     * and that it is their turn, then returns the game's Chance deck ordering
     * via GameService for inspection.
     *
     * @param  Request  $request  The authenticated HTTP request.
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function listChanceDeck(Request $request, int $gameId): JsonResponse
    {
        if (!(bool) config('app.debug_mode')) {
            return response()->json(['message' => 'Debug mode is disabled.', 'errors' => []], 403);
        }

        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $players = $this->gameService->getPlayersForGame($gameId);
            $joinOrder = null;
            foreach ($players as $p) {
                if ($p['user_id'] !== null && (int) $p['user_id'] === $request->user()->id) {
                    $joinOrder = $p['join_order'];
                    break;
                }
            }

            if ($joinOrder === null) {
                return response()->json(['message' => 'Forbidden.', 'errors' => []], 403);
            }

            if ((int) $game->current_turn_join_order !== $joinOrder) {
                return response()->json(['message' => 'It is not your turn to draw a card.', 'errors' => []], 403);
            }

            $deck = $this->gameService->listChanceDeckForGame($gameId);

            return response()->json(['cards' => $deck]);
        } catch (\Throwable $e) {
            Log::error('Failed to list chance deck', [
                'game_id' => $gameId,
                'user_id' => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to list cards.', 'errors' => []], 500);
        }
    }

    /**
     * Emulate drawing a specific Chance card (debug only).
     *
     * Logic: Validates the requested `card_id`, ensures debug_mode is enabled,
     * and delegates to GameService to emulate drawing the specific Chance card
     * for the caller so debug behaviour may be inspected.
     *
     * @param  Request  $request  The authenticated HTTP request.
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function emulateChanceCard(Request $request, int $gameId): JsonResponse
    {
        if (!(bool) config('app.debug_mode')) {
            return response()->json(['message' => 'Debug mode is disabled.', 'errors' => []], 403);
        }

        $request->validate(['card_id' => ['required', 'integer', 'min:1']]);

        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $result = $this->gameService->emulateChanceCardForUser($gameId, $request->user()->id, (int) $request->input('card_id'));

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to emulate chance card', [
                'game_id' => $gameId,
                'user_id' => $request->user()?->id,
                'card_id' => $request->input('card_id'),
                'exception' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to emulate card.', 'errors' => []], 500);
        }
    }

    /**
     * Return the ordered Community Chest deck for debugging.
     *
     * Logic: Verifies debug_mode is enabled, confirms the caller is a participant
     * and that it is their turn, then returns the game's Community Chest deck
     * ordering via GameService for inspection.
     *
     * @param  Request  $request  The authenticated HTTP request.
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function listCommunityDeck(Request $request, int $gameId): JsonResponse
    {
        if (!(bool) config('app.debug_mode')) {
            return response()->json(['message' => 'Debug mode is disabled.', 'errors' => []], 403);
        }

        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }


            $players = $this->gameService->getPlayersForGame($gameId);
            $joinOrder = null;
            foreach ($players as $p) {
                if ($p['user_id'] !== null && (int) $p['user_id'] === $request->user()->id) {
                    $joinOrder = $p['join_order'];
                    break;
                }
            }

            if ($joinOrder === null) {
                return response()->json(['message' => 'Forbidden.', 'errors' => []], 403);
            }

            if ((int) $game->current_turn_join_order !== $joinOrder) {
                return response()->json(['message' => 'It is not your turn to draw a card.', 'errors' => []], 403);
            }

            $deck = $this->gameService->listCommunityDeckForGame($gameId);

            return response()->json(['cards' => $deck]);
        } catch (\Throwable $e) {
            Log::error('Failed to list community deck', [
                'game_id' => $gameId,
                'user_id' => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to list cards.', 'errors' => []], 500);
        }
    }

    /**
     * Emulate drawing a specific Community Chest card (debug only).
     *
     * Logic: Validates the requested `card_id`, ensures debug_mode is enabled,
     * and delegates to GameService to emulate drawing the specific Community
     * Chest card for the caller so debug behaviour may be inspected.
     *
     * @param  Request  $request  The authenticated HTTP request.
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function emulateCommunityCard(Request $request, int $gameId): JsonResponse
    {
        if (!(bool) config('app.debug_mode')) {
            return response()->json(['message' => 'Debug mode is disabled.', 'errors' => []], 403);
        }

        $request->validate(['card_id' => ['required', 'integer', 'min:1']]);

        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $result = $this->gameService->emulateCommunityCardForUser($gameId, $request->user()->id, (int) $request->input('card_id'));

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to emulate community card', [
                'game_id' => $gameId,
                'user_id' => $request->user()?->id,
                'card_id' => $request->input('card_id'),
                'exception' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to emulate card.', 'errors' => []], 500);
        }
    }

    /**
     * Roll the dice for the authenticated player.
     *
     * Logic: Verifies the game exists. Delegates the roll to GameService which
     * validates it is the caller's turn, generates the dice values, advances the
     * turn, and dispatches the DiceRolled broadcast. Returns die1, die2, total,
    * and the new current_turn_join_order as JSON 200. In debug mode, optionally
    * accepts forced_die1/forced_die2 and forwards them through the same roll
    * workflow. Returns 422 when it is not the caller's turn or the player is not
    * a participant.
     *
     * @param  Request  $request  The authenticated HTTP request.
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function rollDice(Request $request, int $gameId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'forced_die1' => ['nullable', 'integer', 'min:1', 'max:6', 'required_with:forced_die2'],
                'forced_die2' => ['nullable', 'integer', 'min:1', 'max:6', 'required_with:forced_die1'],
            ]);

            $hasForcedDice = array_key_exists('forced_die1', $validated)
                || array_key_exists('forced_die2', $validated);

            if ($hasForcedDice && !(bool) config('app.debug_mode')) {
                return response()->json([
                    'message' => 'Forced dice are only allowed in debug mode.',
                    'errors' => [],
                ], 403);
            }

            $forcedDice = $hasForcedDice
                ? [
                    'die1' => (int) $validated['forced_die1'],
                    'die2' => (int) $validated['forced_die2'],
                ]
                : null;

            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $result = $this->gameService->rollDiceForUser(
                $gameId,
                $request->user()->id,
                $forcedDice,
            );

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
     * Move the authenticated player's token to a selected square in debug mode.
     *
     * Logic: Rejects when DEBUG_MODE is disabled, validates the game exists,
     * and delegates to GameService::debugMoveToSquareForUser which enforces
     * turn ownership and applies normal landing side effects.
     *
     * @param  Request  $request  The authenticated HTTP request carrying target_square_index.
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function debugMoveToSquare(Request $request, int $gameId): JsonResponse
    {
        $request->validate(['target_square_index' => ['required', 'integer', 'min:0', 'max:39']]);

        if (!(bool) config('app.debug_mode')) {
            return response()->json(['message' => 'Debug mode is disabled.', 'errors' => []], 403);
        }

        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $result = $this->gameService->debugMoveToSquareForUser(
                $gameId,
                $request->user()->id,
                (int) $request->input('target_square_index'),
            );

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed debug move to square', [
                'game_id'              => $gameId,
                'user_id'              => $request->user()?->id,
                'target_square_index'  => $request->input('target_square_index'),
                'exception'            => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to move token.',
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

    /**
     * Notify all other board observers that the authenticated player's token has finished moving.
     *
     * Logic: Verifies the game exists, then delegates to GameService::notifyTokenMovedForUser
     * which reads the authoritative square_index from the database and dispatches the
     * TokenMoved broadcast event. Called by the rolling player's board after the local
     * step-by-step animation completes so observer boards animate to the correct position.
     * Returns 422 when the caller is not a participant.
     *
     * @param  Request  $request  The authenticated HTTP request.
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function notifyTokenMoved(Request $request, int $gameId): JsonResponse
    {
        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $backward = $request->boolean('backward', false);
            $jailAnimationSource = $request->input('jail_animation_source');
            if (!in_array($jailAnimationSource, ['square', 'card'], true)) {
                $jailAnimationSource = null;
            }

            $result = $this->gameService->notifyTokenMovedForUser(
                $gameId,
                $request->user()->id,
                $backward,
                $jailAnimationSource,
            );

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to notify token moved', [
                'game_id'   => $gameId,
                'user_id'   => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to notify token movement.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Purchase a property for the authenticated player.
     *
     * Logic: Verifies the game exists, delegates to
     * GameService::purchasePropertyForUser which validates the square is
     * purchasable and unowned, records ownership, and deducts the purchase
     * price from the player's capital. Accepts optional
     * `mortgage_square_indices` for a payment-scoped mortgage session and
     * forwards them to the service layer in the same purchase request. Returns
     * the updated player so the frontend can refresh the player's capital.
     *
     * @param  Request  $request  The authenticated HTTP request carrying square_index.
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function purchaseProperty(Request $request, int $gameId): JsonResponse
    {
        $request->validate([
            'square_index' => ['required', 'integer', 'min:0', 'max:39'],
            'mortgage_square_indices' => ['sometimes', 'array'],
            'mortgage_square_indices.*' => ['integer', 'min:0', 'max:39', 'distinct'],
        ]);

        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $result = $this->gameService->purchasePropertyForUser(
                $gameId,
                $request->user()->id,
                (int) $request->input('square_index'),
                (array) $request->input('mortgage_square_indices', []),
            );

            return response()->json(['player' => $result]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to purchase property', [
                'game_id'      => $gameId,
                'user_id'      => $request->user()?->id,
                'square_index' => $request->input('square_index'),
                'exception'    => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to purchase property.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Build houses or hotels on a property following Monopoly rules.
     *
     * Logic: Validates the request payload (`square_index`, `action`, optional
     * `price_per_unit`), derives a default unit price if none provided, and
     * delegates to BuildService to perform the build action. Broadcasts an
     * updated capital event for observers and returns the build result.
     *
     * @param  Request  $request  The authenticated HTTP request carrying build data.
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function buildProperty(Request $request, int $gameId): JsonResponse
    {
        $request->validate([
            'square_index' => ['required', 'integer', 'min:0', 'max:39'],
            'action' => ['required', 'in:house,hotel'],
            'price_per_unit' => ['sometimes', 'integer', 'min:0'],
        ]);

        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $userId = $request->user()->id;
            $sq = (int) $request->input('square_index');
            $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);
            if ($joinOrder === null) {
                throw new \InvalidArgumentException('You are not a participant of this game.');
            }

            // Enforce that only the player whose turn it currently is may sell buildings.
            if ((int) ($game->current_turn_join_order ?? 0) !== (int) $joinOrder) {
                throw new \InvalidArgumentException('It is not your turn.');
            }

            // Enforce that only the player whose turn it currently is may sell buildings.
            if ((int) ($game->current_turn_join_order ?? 0) !== (int) $joinOrder) {
                throw new \InvalidArgumentException('It is not your turn.');
            }

            // Enforce that only the player whose turn it currently is may sell buildings.
            if ((int) ($game->current_turn_join_order ?? 0) !== (int) $joinOrder) {
                throw new \InvalidArgumentException('It is not your turn.');
            }

            // Enforce that only the player whose turn it currently is may sell buildings.
            if ((int) ($game->current_turn_join_order ?? 0) !== (int) $joinOrder) {
                throw new \InvalidArgumentException('It is not your turn.');
            }
            $action = $request->input('action');
            $price = $request->filled('price_per_unit') ? (int) $request->input('price_per_unit') : 0;

            // If no explicit price is provided, attempt to derive one from the property's purchase price.
            if ($price === 0) {
                $row = DB::table('game_properties')
                    ->where('game_id', $gameId)
                    ->where('square_index', $sq)
                    ->select(['purchase_price'])
                    ->first();

                if ($row !== null) {
                    // Default house/hotel unit price: half of purchase price (round down).
                    $price = (int) intdiv((int) $row->purchase_price, 2);
                }
            }

            if ($action === 'house') {
                $result = $this->buildService->buildHouse($gameId, $joinOrder, $sq, $price);
            } else {
                $result = $this->buildService->buildHotel($gameId, $joinOrder, $sq, $price);
            }

            // Resolve the owner's join_order (works for authenticated users
            // and guest invitation flows where the caller passes an invitation id)
            // Broadcast building update so all connected boards update in real-time
            if (isset($joinOrder)) {
                // Do NOT broadcast the new houses/hotel counts yet — buildings
                // only become active when the player's turn ends. Broadcast
                // the owner's updated capital so clients stay in sync.
                event(new PropertyBuilt(
                    $gameId,
                    $joinOrder,
                    $sq,
                    null,
                    null,
                    $result['new_capital'] ?? null,
                ));
            }

            return response()->json(['result' => $result]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to build property', [
                'game_id' => $gameId,
                'user_id' => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to build property.', 'errors' => []], 500);
        }
    }

    /**
     * Sell houses or hotels on a property back to the bank.
     *
     * Logic: Validates the requested `square_index` and `action`, ensures the
     * caller is a participant, delegates to BuildService to perform the sale,
     * fetches the updated building counts, broadcasts the update, and returns
     * the result including updated player capital.
     *
     * @param  Request  $request  The authenticated HTTP request carrying square_index.
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function sellProperty(Request $request, int $gameId): JsonResponse
    {
        $request->validate([
            'square_index' => ['required', 'integer', 'min:0', 'max:39'],
            'action' => ['required', 'in:house,hotel'],
        ]);

        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $userId = $request->user()->id;
            $sq = (int) $request->input('square_index');
            $action = $request->input('action');

            $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);
            if ($joinOrder === null) {
                throw new \InvalidArgumentException('You are not a participant of this game.');
            }

            // Enforce that only the player whose turn it currently is may sell buildings.
            if ((int) ($game->current_turn_join_order ?? 0) !== (int) $joinOrder) {
                throw new \InvalidArgumentException('It is not your turn.');
            }

            if ($action === 'house') {
                $result = $this->buildService->sellHouse($gameId, $joinOrder, $sq);
            } else {
                $result = $this->buildService->sellHotel($gameId, $joinOrder, $sq);
            }

            // Fetch updated building counts to broadcast
            $row = DB::table('game_properties')
                ->where('game_id', $gameId)
                ->where('square_index', $sq)
                ->select(['houses_count', 'has_hotel'])
                ->first();

            $housesCount = $row?->houses_count ?? null;
            $hasHotel = isset($row->has_hotel) ? (bool) $row->has_hotel : null;

            if (isset($joinOrder)) {
                event(new PropertyBuilt(
                    $gameId,
                    $joinOrder,
                    $sq,
                    $housesCount,
                    $hasHotel,
                    $result['new_capital'] ?? null,
                ));
            }

            return response()->json(['result' => $result]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to sell property', [
                'game_id' => $gameId,
                'user_id' => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to sell property.', 'errors' => []], 500);
        }
    }

    /**
     * Return the authenticated player's owned properties for mortgage actions.
     *
     * Logic: Verifies the game exists, then delegates to GameService so the
     * frontend can render a list of mortgage options for the current player.
     *
     * @param  Request  $request  The authenticated HTTP request.
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function getPlayerProperties(Request $request, int $gameId): JsonResponse
    {
        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $properties = $this->gameService->getPlayerPropertiesForUser($gameId, $request->user()->id);

            return response()->json(['properties' => $properties]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to load player properties', [
                'game_id'   => $gameId,
                'user_id'   => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to load player properties.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Mortgage one of the authenticated player's properties.
     *
     * Logic: Validates the requested `square_index`, confirms the game exists,
     * and delegates to GameService::mortgagePropertyForUser which marks the
     * property as mortgaged and updates the player's capital reactively. Can be
     * used as part of a payment-scoped mortgage session when provided alongside
     * other mortgage indices.
     *
     * @param  Request  $request  The authenticated HTTP request carrying square_index.
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function mortgageProperty(Request $request, int $gameId): JsonResponse
    {
        $request->validate(['square_index' => ['required', 'integer', 'min:0', 'max:39']]);

        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $player = $this->gameService->mortgagePropertyForUser(
                $gameId,
                $request->user()->id,
                (int) $request->input('square_index'),
            );

            return response()->json(['player' => $player]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to mortgage property', [
                'game_id'      => $gameId,
                'user_id'      => $request->user()?->id,
                'square_index' => $request->input('square_index'),
                'exception'    => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to mortgage property.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Unmortgage one of the authenticated player's properties.
     *
     * Logic: Validates the requested square, confirms the game exists, then
     * delegates to GameService so the property can be unmortgaged and the
     * player capital updated reactively.
     *
     * @param  Request  $request  The authenticated HTTP request carrying square_index.
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function unmortgageProperty(Request $request, int $gameId): JsonResponse
    {
        $request->validate(['square_index' => ['required', 'integer', 'min:0', 'max:39']]);

        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $player = $this->gameService->unmortgagePropertyForUser(
                $gameId,
                $request->user()->id,
                (int) $request->input('square_index'),
            );

            return response()->json(['player' => $player]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to unmortgage property', [
                'game_id'      => $gameId,
                'user_id'      => $request->user()?->id,
                'square_index' => $request->input('square_index'),
                'exception'    => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to unmortgage property.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Use an authenticated player's held get-out-of-jail-free card.
     *
     * Logic: Verifies the game exists, then delegates to GameService which
     * validates jail/card ownership, returns the card to deck bottom, and
     * clears jail state for the player.
     *
     * @param  Request  $request  The authenticated HTTP request.
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function useGetOutOfJailCard(Request $request, int $gameId): JsonResponse
    {
        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $jailRelease = $this->gameService->useGetOutOfJailCardForUser($gameId, $request->user()->id);

            return response()->json(['jail_release' => $jailRelease]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to use get out of jail card', [
                'game_id'   => $gameId,
                'user_id'   => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to use get out of jail card.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Pay the authenticated player's $50 jail-release fee.
     *
     * Logic: Verifies the game exists, then delegates to GameService which
     * validates jail/payment state, deducts $50, and marks the player as paid
     * to leave jail on the next roll.
     *
     * @param  Request  $request  The authenticated HTTP request.
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function payJailRelease(Request $request, int $gameId): JsonResponse
    {
        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $jailRelease = $this->gameService->payJailReleaseForUser($gameId, $request->user()->id);

            return response()->json(['jail_release' => $jailRelease]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to pay jail release', [
                'game_id'   => $gameId,
                'user_id'   => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to pay jail release.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Pay rent for the authenticated player landing on an owned property.
     *
     * Logic: Verifies the game exists, then delegates to
     * GameService::payRentForUser which validates the square has an owner,
     * deducts the rent from the payer's capital, and adds it to the owner's
     * capital. Returns both players' updated capitals so the frontend can
     * update the player panels reactively. Returns 422 when the square has no
     * owner or the player is not a participant.
     *
     * @param  Request  $request  The authenticated HTTP request carrying square_index.
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function payRent(Request $request, int $gameId): JsonResponse
    {
        $request->validate([
            'square_index' => ['required', 'integer', 'min:0', 'max:39'],
            'mortgage_square_indices' => ['sometimes', 'array'],
            'mortgage_square_indices.*' => ['integer', 'min:0', 'max:39', 'distinct'],
        ]);

        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $result = $this->gameService->payRentForUser(
                $gameId,
                $request->user()->id,
                (int) $request->input('square_index'),
                (array) $request->input('mortgage_square_indices', []),
            );

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to pay rent', [
                'game_id'      => $gameId,
                'user_id'      => $request->user()?->id,
                'square_index' => $request->input('square_index'),
                'exception'    => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to pay rent.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Signal that the authenticated drawing player has accepted their card.
     *
     * Logic: Verifies the game exists, then delegates to
     * GameService::acceptCardForUser which validates participation and dispatches
    * the CardAccepted broadcast so observer boards auto-close their card-drawn
    * notification. If the player is holding a get-out-of-jail-free card, it is
    * first returned to the bottom of its deck. Returns 422 when the caller is
    * not a participant.
     *
     * @param  Request  $request  The authenticated HTTP request.
     * @param  int      $gameId   The primary key of the game.
     * @return JsonResponse
     */
    public function acceptCard(Request $request, int $gameId): JsonResponse
    {
        $request->validate([
            'mortgage_square_indices' => ['sometimes', 'array'],
            'mortgage_square_indices.*' => ['integer', 'min:0', 'max:39', 'distinct'],
            'card_payment_type' => ['sometimes', 'nullable', 'in:pay,pay_each_player'],
            'card_payment_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $result = $this->gameService->acceptCardForUser(
                $gameId,
                $request->user()->id,
                (array) $request->input('mortgage_square_indices', []),
                $request->input('card_payment_type'),
                $request->filled('card_payment_amount') ? (int) $request->input('card_payment_amount') : null,
            );

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to accept card', [
                'game_id'   => $gameId,
                'user_id'   => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to accept card.',
                'errors'  => [],
            ], 500);
        }
    }
}
