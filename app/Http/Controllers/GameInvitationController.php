<?php

namespace App\Http\Controllers;

use App\Events\PlayerJoined;
use App\Events\PropertyBuilt;
use App\Exceptions\IconConflictException;
use App\Http\Requests\AcceptGameInvitationRequest;
use App\Http\Requests\StoreGameInvitationsRequest;
use App\Repositories\PlayerIconRepository;
use App\Services\GameInvitationService;
use App\Services\GameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;

class GameInvitationController extends Controller
{
    public function __construct(
        private readonly GameInvitationService $invitationService,
        private readonly PlayerIconRepository  $playerIconRepository,
        private readonly GameService           $gameService,
        private readonly \App\Services\BuildService $buildService,
        private readonly \App\Repositories\GameRepository $gameRepository,
    ) {}

    /**
     * Send game invitations to a list of email addresses.
     *
     * Logic: Validates the request via StoreGameInvitationsRequest, delegates
     * to GameInvitationService::sendInvitations which enforces ownership and
     * capacity, then returns the count of invitations sent as JSON 201.
     *
     * @param  StoreGameInvitationsRequest  $request  The validated request carrying emails[].
     * @param  int                          $gameId   The ID of the game to invite players to.
     * @return JsonResponse
     */
    public function store(StoreGameInvitationsRequest $request, int $gameId): JsonResponse
    {
        try {
            $invitations = $this->invitationService->sendInvitations(
                $gameId,
                $request->user()->id,
                $request->validated('emails'),
            );

            return response()->json([
                'invitations_sent' => count($invitations),
            ], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => [],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to send game invitations', [
                'game_id'   => $gameId,
                'user_id'   => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to send invitations.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Re-send an invitation email to a previously joined invited player.
     *
     * Logic: Requires an authenticated creator, resolves the target player via
     * their original invitation ID, delegates to GameInvitationService to create
     * and email a fresh invitation, then returns a small confirmation payload.
     *
     * @param  Request  $request       The authenticated HTTP request.
     * @param  int      $gameId        The ID of the game the player belongs to.
     * @param  int      $invitationId  The original invitation ID tied to the joined player.
     * @return JsonResponse
     */
    public function resend(Request $request, int $gameId, int $invitationId): JsonResponse
    {
        try {
            $invitation = $this->invitationService->resendInvitation(
                $gameId,
                $request->user()->id,
                $invitationId,
            );

            return response()->json([
                'invitation' => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                ],
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to resend game invitation', [
                'game_id' => $gameId,
                'invitation_id' => $invitationId,
                'user_id' => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to send invitation.',
                'errors' => [],
            ], 500);
        }
    }

    /**
     * Render the game join page for an invited guest.
     *
     * Logic: Looks up the invitation by token (eager-loading the game and
     * creator user), validates it is still pending and not expired, fetches the
     * icons not yet taken by existing players, then returns an Inertia response
     * so the guest can pick their token and join. No authentication required.
     *
     * @param  string  $token  The UUID token from the invitation email link.
     * @return InertiaResponse|JsonResponse
     */
    public function show(string $token): InertiaResponse|JsonResponse
    {
        try {
            $invitation = $this->invitationService->findPendingInvitation($token);

            $availableIcons = $this->playerIconRepository
                ->getAvailableForGame($invitation->game_id);

            return Inertia::render('GameJoin', [
                'token'          => $invitation->token,
                'gameName'       => $invitation->game->name,
                'creatorName'    => $invitation->game->user->name,
                'availableIcons' => $availableIcons->values(),
            ]);
        } catch (InvalidArgumentException $e) {
            return Inertia::render('GameJoin', [
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to load game join page', [
                'token'     => $token,
                'exception' => $e->getMessage(),
            ]);

            return Inertia::render('GameJoin', [
                'error' => 'Unable to load this invitation. Please try again.',
            ]);
        }
    }

    /**
     * Accept a game invitation and assign the chosen player icon.
     *
     * Logic: Validates the player_icon_id via AcceptGameInvitationRequest,
     * delegates to GameInvitationService::acceptInvitation which runs the icon
     * assignment inside a serialised DB transaction. On success returns the
     * updated game data and the full players array (ordered by join_order) as
     * JSON 200. On icon conflict returns 409 so the frontend can re-fetch
     * available icons and prompt the guest to re-pick.
     * No authentication required — the token acts as the credential.
     *
     * @param  AcceptGameInvitationRequest  $request  The validated request with player_icon_id.
     * @param  string                       $token    The UUID token from the join URL.
     * @return JsonResponse
     */
    public function accept(AcceptGameInvitationRequest $request, string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->acceptInvitation(
                $token,
                (int) $request->validated('player_icon_id'),
            );

            $players = $this->gameService->getPlayersForGame($invitation->game_id);

            return response()->json([
                'game'    => $invitation->game,
                'players' => $players,
            ]);
        } catch (IconConflictException $e) {
            try {
                $pending   = $this->invitationService->findPendingInvitation($token);
                $available = $this->playerIconRepository->getAvailableForGame($pending->game_id);
            } catch (\Throwable $innerE) {
                Log::warning('Could not re-fetch available icons after conflict', [
                    'token'     => $token,
                    'exception' => $innerE->getMessage(),
                ]);
                $available = collect();
            }

            return response()->json([
                'message'        => $e->getMessage(),
                'errors'         => [],
                'availableIcons' => $available->values(),
            ], 409);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => [],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to accept game invitation', [
                'token'     => $token,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to join the game. Please try again.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Render the guest game board for a player who accepted an invitation.
     *
     * Logic: Validates that the token belongs to an already-accepted invitation,
     * then returns an Inertia response that renders the GuestGame page with the
     * game data, the token, and the full players array (ordered by join_order)
        * so all joined players are visible in the side panels at load time.
        * Before rendering, re-broadcasts PlayerJoined with the authoritative
        * players and pending invitation lists so already-open boards can clear any
        * stale waiting-room entries when a guest resumes from a re-invite link.
     * No authentication required — possession of an accepted token is the credential.
     *
     * @param  string  $token  The UUID token from the invitation email link.
     * @return InertiaResponse|JsonResponse
     */
    public function game(string $token): InertiaResponse|JsonResponse
    {
        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);

            $players            = $this->gameService->getPlayersForGame($invitation->game_id);
            $pendingInvitations = $this->gameService->getPendingInvitationsForGame($invitation->game_id);

            try {
                PlayerJoined::dispatch($invitation->game_id, $players, $pendingInvitations);
            } catch (\Throwable $e) {
                Log::warning('Failed to broadcast guest rejoin event', [
                    'game_id' => $invitation->game_id,
                    'invitation_id' => $invitation->id,
                    'exception' => $e->getMessage(),
                ]);
            }

            return Inertia::render('GuestGame', [
                'token'               => $invitation->token,
                'game'                => $invitation->game,
                'players'             => $players,
                'pendingInvitations'  => $pendingInvitations,
                'currentInvitationId' => $invitation->id,
                'error'               => null,
            ]);
        } catch (InvalidArgumentException $e) {
            return Inertia::render('GuestGame', [
                'token' => null,
                'game'  => null,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to load guest game page', [
                'token'     => $token,
                'exception' => $e->getMessage(),
            ]);

            return Inertia::render('GuestGame', [
                'token' => null,
                'game'  => null,
                'error' => 'Unable to load the game. Please try again.',
            ]);
        }
    }

    /**
     * Draw the next Chance card on behalf of a guest player.
     *
     * Logic: Validates the token belongs to an accepted invitation, then
        * delegates the draw to GameService. The next active card is returned,
        * excluding any get-out-of-jail-free card currently held by a player. No
        * authentication required — the accepted invitation token acts as the
        * guest's credential.
     *
     * @param  string  $token  The UUID token from the invitation email link.
     * @return JsonResponse
     */
    public function drawGuestChanceCard(string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            try {
                $result = $this->gameService->drawChanceCardForGuest($invitation->game_id, $invitation->id);
                return response()->json($result);
            } catch (InvalidArgumentException $e) {
                // If the invitation is accepted but the guest has not been
                // assigned a player row yet, fall back to returning the public
                // deck's next card (card-only) to preserve the previous API
                // behaviour expected by existing callers and tests.
                if ($e->getMessage() === 'You are not a participant of this game.') {
                    $result = $this->gameService->drawChanceCard($invitation->game_id);
                    return response()->json(['card' => $result, 'effect' => null]);
                }

                throw $e;
            }
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => [],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to draw guest chance card', [
                'token'     => $token,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to draw card.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Draw the next Community Chest card on behalf of a guest player.
     *
     * Logic: Validates the token belongs to an accepted invitation, then
        * delegates the draw to GameService. The next active card is returned,
        * excluding any get-out-of-jail-free card currently held by a player. No
        * authentication required — the accepted invitation token acts as the
        * guest's credential.
     *
     * @param  string  $token  The UUID token from the invitation email link.
     * @return JsonResponse
     */
    public function drawGuestCommunityChestCard(string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            try {
                $result = $this->gameService->drawCommunityChestCardForGuest($invitation->game_id, $invitation->id);
                return response()->json($result);
            } catch (InvalidArgumentException $e) {
                if ($e->getMessage() === 'You are not a participant of this game.') {
                    $result = $this->gameService->drawCommunityChestCard($invitation->game_id);
                    return response()->json(['card' => $result, 'effect' => null]);
                }

                throw $e;
            }
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => [],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to draw guest community chest card', [
                'token'     => $token,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to draw card.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Return the ordered Chance deck for a guest (debug only).
     */
    public function guestListChanceDeck(string $token): JsonResponse
    {
        if (!(bool) config('app.debug_mode')) {
            return response()->json(['message' => 'Debug mode is disabled.', 'errors' => []], 403);
        }

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($invitation->game_id, $invitation->id);
            if ($joinOrder === null) {
                return response()->json(['message' => 'Forbidden.', 'errors' => []], 403);
            }

            $current = $this->gameService->getCurrentTurnJoinOrderForGame($invitation->game_id);
            if ($current === null || (int) $current !== $joinOrder) {
                return response()->json(['message' => 'It is not your turn to draw a card.', 'errors' => []], 403);
            }

            $deck = $this->gameService->listChanceDeckForGame($invitation->game_id);
            return response()->json(['cards' => $deck]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to list guest chance deck', ['token' => $token, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to list cards.', 'errors' => []], 500);
        }
    }

    /**
     * Emulate drawing a specific Chance card for a guest (debug only).
     */
    public function guestEmulateChanceCard(Request $request, string $token): JsonResponse
    {
        if (!(bool) config('app.debug_mode')) {
            return response()->json(['message' => 'Debug mode is disabled.', 'errors' => []], 403);
        }

        $request->validate(['card_id' => ['required', 'integer', 'min:1']]);

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);

            $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($invitation->game_id, $invitation->id);
            if ($joinOrder === null) {
                return response()->json(['message' => 'Forbidden.', 'errors' => []], 403);
            }

            $current = $this->gameService->getCurrentTurnJoinOrderForGame($invitation->game_id);
            if ($current === null || (int) $current !== $joinOrder) {
                return response()->json(['message' => 'It is not your turn to draw a card.', 'errors' => []], 403);
            }

            $result = $this->gameService->emulateChanceCardForUser($invitation->game_id, $invitation->id, (int) $request->input('card_id'));
            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to emulate guest chance card', ['token' => $token, 'card_id' => $request->input('card_id'), 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to emulate card.', 'errors' => []], 500);
        }
    }

    /**
     * Return the ordered Community Chest deck for a guest (debug only).
     */
    public function guestListCommunityDeck(string $token): JsonResponse
    {
        if (!(bool) config('app.debug_mode')) {
            return response()->json(['message' => 'Debug mode is disabled.', 'errors' => []], 403);
        }

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($invitation->game_id, $invitation->id);
            if ($joinOrder === null) {
                return response()->json(['message' => 'Forbidden.', 'errors' => []], 403);
            }

            $current = $this->gameService->getCurrentTurnJoinOrderForGame($invitation->game_id);
            if ($current === null || (int) $current !== $joinOrder) {
                return response()->json(['message' => 'It is not your turn to draw a card.', 'errors' => []], 403);
            }

            $deck = $this->gameService->listCommunityDeckForGame($invitation->game_id);
            return response()->json(['cards' => $deck]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to list guest community deck', ['token' => $token, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to list cards.', 'errors' => []], 500);
        }
    }

    /**
     * Emulate drawing a specific Community Chest card for a guest (debug only).
     */
    public function guestEmulateCommunityCard(Request $request, string $token): JsonResponse
    {
        if (!(bool) config('app.debug_mode')) {
            return response()->json(['message' => 'Debug mode is disabled.', 'errors' => []], 403);
        }

        $request->validate(['card_id' => ['required', 'integer', 'min:1']]);

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);

            $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($invitation->game_id, $invitation->id);
            if ($joinOrder === null) {
                return response()->json(['message' => 'Forbidden.', 'errors' => []], 403);
            }

            $current = $this->gameService->getCurrentTurnJoinOrderForGame($invitation->game_id);
            if ($current === null || (int) $current !== $joinOrder) {
                return response()->json(['message' => 'It is not your turn to draw a card.', 'errors' => []], 403);
            }

            $result = $this->gameService->emulateCommunityCardForUser($invitation->game_id, $invitation->id, (int) $request->input('card_id'));
            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to emulate guest community card', ['token' => $token, 'card_id' => $request->input('card_id'), 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to emulate card.', 'errors' => []], 500);
        }
    }

    /**
     * Roll the dice on behalf of a guest player.
     *
     * Logic: Validates the token belongs to an accepted invitation, then
     * delegates the roll to GameService which validates it is the guest's turn,
     * generates the dice values, advances the turn, and dispatches the DiceRolled
    * broadcast. In debug mode, optionally accepts forced_die1/forced_die2 and
    * forwards them through the same roll workflow. No authentication required — the accepted invitation token acts
     * as the guest's credential. Returns 422 when it is not the guest's turn.
     *
    * @param  Request  $request  The HTTP request carrying optional forced dice.
    * @param  string   $token    The UUID token from the invitation email link.
     * @return JsonResponse
     */
    public function guestRollDice(Request $request, string $token): JsonResponse
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

            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $result     = $this->gameService->rollDiceForGuest(
                $invitation->game_id,
                $invitation->id,
                $forcedDice,
            );

            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => [],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to roll dice for guest', [
                'token'     => $token,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to roll dice.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Move a guest player's token to a selected square in debug mode.
     *
     * Logic: Rejects when DEBUG_MODE is disabled, validates the invitation
     * token belongs to an accepted invitation, and delegates to
     * GameService::debugMoveToSquareForGuest.
     *
     * @param  \Illuminate\Http\Request  $request  The HTTP request carrying target_square_index.
     * @param  string                    $token    The UUID token from the invitation email link.
     * @return JsonResponse
     */
    public function guestDebugMoveToSquare(\Illuminate\Http\Request $request, string $token): JsonResponse
    {
        $request->validate(['target_square_index' => ['required', 'integer', 'min:0', 'max:39']]);

        if (!(bool) config('app.debug_mode')) {
            return response()->json(['message' => 'Debug mode is disabled.', 'errors' => []], 403);
        }

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $result     = $this->gameService->debugMoveToSquareForGuest(
                $invitation->game_id,
                $invitation->id,
                (int) $request->input('target_square_index'),
            );

            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => [],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed debug move to square for guest', [
                'token'               => $token,
                'target_square_index' => $request->input('target_square_index'),
                'exception'           => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to move token.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Signal that a guest player has completed their turn.
     *
     * Logic: Validates the token belongs to an accepted invitation, then
     * delegates to GameService::endTurnForGuest which validates it is the
     * guest's turn, cyclically computes the next join_order, persists the
     * update, and dispatches the TurnAdvanced broadcast. Returns 422 when it
     * is not the guest's turn or the token is invalid.
     *
     * @param  string  $token  The UUID token from the invitation email link.
     * @return JsonResponse
     */
    public function guestEndTurn(string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $result     = $this->gameService->endTurnForGuest($invitation->game_id, $invitation->id);

            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => [],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to end turn for guest', [
                'token'     => $token,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to end turn.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Notify all other board observers that a guest player's token has finished moving.
     *
     * Logic: Validates the token belongs to an accepted invitation, then delegates
     * to GameService::notifyTokenMovedForGuest which reads the authoritative
     * square_index from the database and dispatches the TokenMoved broadcast event.
     * Called by the guest's board after the local step-by-step animation completes
     * so observer boards animate to the correct position. Returns 422 when the
     * token is invalid or the guest is not a participant.
     *
     * @param  string  $token  The UUID token from the invitation email link.
     * @return JsonResponse
     */
    public function guestNotifyTokenMoved(Request $request, string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $backward = $request->boolean('backward', false);
            $jailAnimationSource = $request->input('jail_animation_source');
            if (!in_array($jailAnimationSource, ['square', 'card'], true)) {
                $jailAnimationSource = null;
            }

            $result = $this->gameService->notifyTokenMovedForGuest(
                $invitation->game_id,
                $invitation->id,
                $backward,
                $jailAnimationSource,
            );

            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => [],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to notify guest token moved', [
                'token'     => $token,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to notify token movement.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Purchase a property on behalf of a guest player.
     *
     * Logic: Validates the invitation token belongs to an accepted guest, then
     * delegates to GameService::purchasePropertyForGuest which validates the
     * square is purchasable and unowned, records ownership, and deducts the
     * purchase price from the player's capital. Returns the player's updated
     * capital. Returns 422 when the square is already owned or the token is
     * invalid.
     *
     * @param  \Illuminate\Http\Request  $request  The HTTP request carrying square_index.
     * @param  string                    $token    The UUID token from the invitation email link.
     * @return JsonResponse
    *
    * Logic: Accepts optional mortgage_square_indices for a payment-scoped
    * mortgage session and forwards them to the service layer in the same
    * purchase request.
     */
    public function guestPurchaseProperty(\Illuminate\Http\Request $request, string $token): JsonResponse
    {
        $request->validate([
            'square_index' => ['required', 'integer', 'min:0', 'max:39'],
            'mortgage_square_indices' => ['sometimes', 'array'],
            'mortgage_square_indices.*' => ['integer', 'min:0', 'max:39', 'distinct'],
        ]);

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $result     = $this->gameService->purchasePropertyForGuest(
                $invitation->game_id,
                $invitation->id,
                (int) $request->input('square_index'),
                (array) $request->input('mortgage_square_indices', []),
            );

            return response()->json(['player' => $result]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => [],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to purchase property for guest', [
                'token'        => $token,
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
     * Build houses or hotels on a property on behalf of a guest player.
     *
     * Logic: Validates the invitation token belongs to an accepted guest,
     * resolves the guest's join_order, enforces the same validations as the
     * authenticated build endpoint, delegates to BuildService, and broadcasts
     * a PropertyBuilt event so observer boards update reactively.
     *
     * @param  Request  $request  The HTTP request carrying square_index, action, and optional price_per_unit.
     * @param  string   $token    The UUID token from the invitation email link.
     * @return JsonResponse
     * Logic: Resolves invitation -> join_order -> calls BuildService::buildHouse/buildHotel
     */
    public function guestBuildProperty(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'square_index' => ['required', 'integer', 'min:0', 'max:39'],
            'action' => ['required', 'in:house,hotel'],
            'price_per_unit' => ['sometimes', 'integer', 'min:0'],
        ]);

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);

            $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($invitation->game_id, $invitation->id);
            if ($joinOrder === null) {
                throw new \InvalidArgumentException('You are not a participant of this game.');
            }

            // Enforce that only the player whose turn it currently is may sell buildings.
            $game = $this->gameRepository->findById($invitation->game_id);
            if ($game === null) {
                throw new \InvalidArgumentException('Game not found.');
            }

            if ((int) ($game->current_turn_join_order ?? 0) !== (int) $joinOrder) {
                throw new \InvalidArgumentException('It is not your turn.');
            }

            $sq = (int) $request->input('square_index');
            $action = $request->input('action');
            $price = $request->filled('price_per_unit') ? (int) $request->input('price_per_unit') : 0;

            if ($price === 0) {
                $row = DB::table('game_properties')
                    ->where('game_id', $invitation->game_id)
                    ->where('square_index', $sq)
                    ->select(['purchase_price'])
                    ->first();

                if ($row !== null) {
                    $price = (int) intdiv((int) $row->purchase_price, 2);
                }
            }

            if ($action === 'house') {
                $result = $this->buildService->buildHouse($invitation->game_id, $joinOrder, $sq, $price);
            } else {
                $result = $this->buildService->buildHotel($invitation->game_id, $joinOrder, $sq, $price);
            }

            if (isset($joinOrder)) {
                event(new PropertyBuilt(
                    $invitation->game_id,
                    $joinOrder,
                    $sq,
                    null,
                    null,
                    $result['new_capital'] ?? null,
                ));
            }

            return response()->json(['result' => $result]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to build property for guest', [
                'token' => $token,
                'exception' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to build property.', 'errors' => []], 500);
        }
    }

    /**
     * Sell houses or hotels on a property on behalf of a guest player.
     *
     * Logic: Validates the invitation token belongs to an accepted guest,
     * resolves the guest's join_order, delegates to BuildService::sellHouse
     * or ::sellHotel, then broadcasts a PropertyBuilt event with updated
     * building counts and owner capital.
     *
     * @param  Request  $request  The HTTP request carrying square_index and action.
     * @param  string   $token    The UUID token from the invitation email link.
     * @return JsonResponse
     * Logic: Resolves invitation -> join_order -> calls BuildService::sellHouse/sellHotel
     */
    public function guestSellProperty(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'square_index' => ['required', 'integer', 'min:0', 'max:39'],
            'action' => ['required', 'in:house,hotel'],
        ]);

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);

            $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($invitation->game_id, $invitation->id);
            if ($joinOrder === null) {
                throw new \InvalidArgumentException('You are not a participant of this game.');
            }

            // Enforce that only the player whose turn it currently is may sell buildings.
            $game = $this->gameRepository->findById($invitation->game_id);
            if ($game === null) {
                throw new \InvalidArgumentException('Game not found.');
            }

            if ((int) ($game->current_turn_join_order ?? 0) !== (int) $joinOrder) {
                throw new \InvalidArgumentException('It is not your turn.');
            }

            $sq = (int) $request->input('square_index');
            $action = $request->input('action');

            if ($action === 'house') {
                $result = $this->buildService->sellHouse($invitation->game_id, $joinOrder, $sq);
            } else {
                $result = $this->buildService->sellHotel($invitation->game_id, $joinOrder, $sq);
            }

            $row = DB::table('game_properties')
                ->where('game_id', $invitation->game_id)
                ->where('square_index', $sq)
                ->select(['houses_count', 'has_hotel'])
                ->first();

            $housesCount = $row?->houses_count ?? null;
            $hasHotel = isset($row->has_hotel) ? (bool) $row->has_hotel : null;

            if (isset($joinOrder)) {
                event(new PropertyBuilt(
                    $invitation->game_id,
                    $joinOrder,
                    $sq,
                    $housesCount,
                    $hasHotel,
                    $result['new_capital'] ?? null,
                ));
            }

            return response()->json(['result' => $result]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to sell property for guest', [
                'token' => $token,
                'exception' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to sell property.', 'errors' => []], 500);
        }
    }

    /**
     * Return the guest player's owned properties for mortgage actions.
     *
     * Logic: Resolves the accepted invitation token and delegates to GameService
     * so the frontend can render the guest's mortgage options.
     *
     * @param  string  $token  The UUID token from the invitation email link.
     * @return JsonResponse
     */
    public function guestGetPlayerProperties(string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $properties = $this->gameService->getPlayerPropertiesForGuest($invitation->game_id, $invitation->id);

            return response()->json(['properties' => $properties]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to load guest player properties', [
                'token'     => $token,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to load player properties.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Mortgage one of the guest player's properties.
     *
     * Logic: Resolves the accepted invitation token, validates the selected
     * square, and delegates to GameService so the property can be mortgaged
     * and the guest's capital updated reactively.
     *
     * @param  \Illuminate\Http\Request  $request  The HTTP request carrying square_index.
     * @param  string                      $token    The UUID token from the invitation email link.
     * @return JsonResponse
     */
    public function guestMortgageProperty(\Illuminate\Http\Request $request, string $token): JsonResponse
    {
        $request->validate(['square_index' => ['required', 'integer', 'min:0', 'max:39']]);

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $player     = $this->gameService->mortgagePropertyForGuest(
                $invitation->game_id,
                $invitation->id,
                (int) $request->input('square_index'),
            );

            return response()->json(['player' => $player]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to mortgage property for guest', [
                'token'        => $token,
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
     * Unmortgage one of the guest player's properties.
     *
     * Logic: Resolves the accepted invitation token, validates the selected
     * square, and delegates to GameService so the property can be unmortgaged
     * and the guest's capital updated reactively.
     *
     * @param  \Illuminate\Http\Request  $request  The HTTP request carrying square_index.
     * @param  string                      $token    The UUID token from the invitation email link.
     * @return JsonResponse
     */
    public function guestUnmortgageProperty(\Illuminate\Http\Request $request, string $token): JsonResponse
    {
        $request->validate(['square_index' => ['required', 'integer', 'min:0', 'max:39']]);

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $player     = $this->gameService->unmortgagePropertyForGuest(
                $invitation->game_id,
                $invitation->id,
                (int) $request->input('square_index'),
            );

            return response()->json(['player' => $player]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to unmortgage property for guest', [
                'token'        => $token,
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
     * Use a guest player's held get-out-of-jail-free card.
     *
     * Logic: Resolves the accepted invitation token, then delegates to
     * GameService which validates jail/card ownership, returns the card to
     * deck bottom, and clears jail state.
     *
     * @param  string  $token  The UUID token from the invitation email link.
     * @return JsonResponse
     */
    public function guestUseGetOutOfJailCard(string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $jailRelease = $this->gameService->useGetOutOfJailCardForGuest(
                $invitation->game_id,
                $invitation->id,
            );

            return response()->json(['jail_release' => $jailRelease]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => [],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to use get out of jail card for guest', [
                'token'     => $token,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to use get out of jail card.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Pay the guest player's $50 jail-release fee.
     *
     * Logic: Resolves the accepted invitation token, then delegates to
     * GameService which validates jail/payment state, deducts $50, and marks
     * the guest as paid to leave jail on the next roll.
     *
     * @param  string  $token  The UUID token from the invitation email link.
     * @return JsonResponse
     */
    public function guestPayJailRelease(string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $jailRelease = $this->gameService->payJailReleaseForGuest(
                $invitation->game_id,
                $invitation->id,
            );

            return response()->json(['jail_release' => $jailRelease]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => [],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to pay jail release for guest', [
                'token'     => $token,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to pay jail release.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Pay rent on behalf of a guest player landing on an owned property.
     *
     * Logic: Validates the invitation token belongs to an accepted guest, then
     * delegates to GameService::payRentForGuest which validates the square has
     * an owner, deducts the rent from the payer's capital, and adds it to the
     * owner's capital. Returns both players' updated capitals so the frontend
     * can update the player panels reactively. Returns 422 when the square has
     * no owner or the token is invalid.
     *
     * @param  \Illuminate\Http\Request  $request  The HTTP request carrying square_index.
     * @param  string                    $token    The UUID token from the invitation email link.
     * @return JsonResponse
    *
    * Logic: Accepts optional mortgage_square_indices for a payment-scoped
    * mortgage session and forwards them to the service layer in the same
    * rent request.
     */
    public function guestPayRent(\Illuminate\Http\Request $request, string $token): JsonResponse
    {
        $request->validate([
            'square_index' => ['required', 'integer', 'min:0', 'max:39'],
            'mortgage_square_indices' => ['sometimes', 'array'],
            'mortgage_square_indices.*' => ['integer', 'min:0', 'max:39', 'distinct'],
        ]);

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $result     = $this->gameService->payRentForGuest(
                $invitation->game_id,
                $invitation->id,
                (int) $request->input('square_index'),
                (array) $request->input('mortgage_square_indices', []),
            );

            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => [],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to pay rent for guest', [
                'token'        => $token,
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
     * Signal that a guest drawing player has accepted their card.
     *
     * Logic: Validates the token belongs to an accepted invitation, then
     * delegates to GameService::acceptCardForGuest which validates participation
    * and dispatches the CardAccepted broadcast so observer boards auto-close
    * their card-drawn notification. If the guest is holding a get-out-of-jail-
    * free card, it is first returned to the bottom of its deck. Returns 422
    * when the token is invalid or the guest is not a participant.
     *
     * @param  string  $token  The UUID token from the invitation email link.
     * @return JsonResponse
     */
    public function guestAcceptCard(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'mortgage_square_indices' => ['sometimes', 'array'],
            'mortgage_square_indices.*' => ['integer', 'min:0', 'max:39', 'distinct'],
            'card_payment_type' => ['sometimes', 'nullable', 'in:pay,pay_each_player'],
            'card_payment_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $result     = $this->gameService->acceptCardForGuest(
                $invitation->game_id,
                $invitation->id,
                (array) $request->input('mortgage_square_indices', []),
                $request->input('card_payment_type'),
                $request->filled('card_payment_amount') ? (int) $request->input('card_payment_amount') : null,
            );

            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => [],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to accept card for guest', [
                'token'     => $token,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to accept card.',
                'errors'  => [],
            ], 500);
        }
    }
}
