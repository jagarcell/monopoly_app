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
        private readonly \App\Repositories\GamePropertyRepository $gamePropertyRepository,
        private readonly \App\Repositories\GamePendingBuildRepository $pendingBuildRepository,
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

            $avail = $this->gameService->computeBankAvailability($invitation->game_id);
            $housesAvailable = $avail['houses_available'];
            $hotelsAvailable = $avail['hotels_available'];

            $gamePayload = array_merge($invitation->game->toArray(), [
                'bank_houses_available' => $housesAvailable,
                'bank_hotels_available'  => $hotelsAvailable,
            ]);

            return response()->json([
                'game'    => $gamePayload,
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

            $avail = $this->gameService->computeBankAvailability($invitation->game_id);
            $housesAvailable = $avail['houses_available'];
            $hotelsAvailable = $avail['hotels_available'];

            $gamePayload = array_merge($invitation->game->toArray(), [
                'bank_houses_available' => $housesAvailable,
                'bank_hotels_available'  => $hotelsAvailable,
            ]);

            return Inertia::render('GuestGame', [
                'token'               => $invitation->token,
                'game'                => $gamePayload,
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
     * Guest API methods moved to GuestInvitationController.
     *
     * The guest-facing JSON endpoints were split out to keep controllers thin
     * and focused. See App\Http\Controllers\GuestInvitationController for
     * the implementations of the guest endpoints.
     */
}
