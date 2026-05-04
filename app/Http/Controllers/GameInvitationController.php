<?php

namespace App\Http\Controllers;

use App\Exceptions\IconConflictException;
use App\Http\Requests\AcceptGameInvitationRequest;
use App\Http\Requests\StoreGameInvitationsRequest;
use App\Repositories\PlayerIconRepository;
use App\Services\GameInvitationService;
use App\Services\GameService;
use Illuminate\Http\JsonResponse;
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
     * delegates the draw to GameService. No authentication required — the
     * accepted invitation token acts as the guest's credential.
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
     * delegates the draw to GameService. No authentication required — the
     * accepted invitation token acts as the guest's credential.
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
}
