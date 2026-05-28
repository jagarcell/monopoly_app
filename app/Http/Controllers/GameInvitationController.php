<?php

namespace App\Http\Controllers;

use App\Events\PlayerJoined;
use App\Exceptions\IconConflictException;
use App\Http\Requests\AcceptGameInvitationRequest;
use App\Http\Requests\StoreGameInvitationsRequest;
use App\Repositories\PlayerIconRepository;
use App\Services\GameInvitationService;
use App\Services\GameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;

class GameInvitationController extends Controller
{
    public function __construct(
        private readonly GameInvitationService $invitationService,
        private readonly PlayerIconRepository  $playerIconRepository,
        private readonly GameService           $gameService,
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
            $card       = $this->gameService->drawChanceCard($invitation->game_id);

            return response()->json(['card' => $card]);
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
            $card       = $this->gameService->drawCommunityChestCard($invitation->game_id);

            return response()->json(['card' => $card]);
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
     * Roll the dice on behalf of a guest player.
     *
     * Logic: Validates the token belongs to an accepted invitation, then
     * delegates the roll to GameService which validates it is the guest's turn,
     * generates the dice values, advances the turn, and dispatches the DiceRolled
     * broadcast. No authentication required — the accepted invitation token acts
     * as the guest's credential. Returns 422 when it is not the guest's turn.
     *
     * @param  string  $token  The UUID token from the invitation email link.
     * @return JsonResponse
     */
    public function guestRollDice(string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $result     = $this->gameService->rollDiceForGuest($invitation->game_id, $invitation->id);

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
            $backward   = $request->boolean('backward', false);
            $result     = $this->gameService->notifyTokenMovedForGuest($invitation->game_id, $invitation->id, $backward);

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
