<?php

namespace App\Services;

use App\Events\PlayerJoined;
use App\Exceptions\IconConflictException;
use App\Mail\GameInvitationMail;
use App\Models\GameInvitation;
use App\Repositories\GameInvitationRepository;
use App\Repositories\GameRepository;
use App\Repositories\PlayerIconRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;

class GameInvitationService
{
    public function __construct(
        private readonly GameRepository           $gameRepository,
        private readonly GameInvitationRepository $invitationRepository,
        private readonly PlayerIconRepository     $playerIconRepository,
    ) {}

    /**
     * Send email invitations for a game to a list of addresses.
     *
     * Logic: Verifies the requesting user owns the game and that the number of
     * unique emails does not exceed max_players − 1 (one slot is reserved for
     * the creator). For each email a UUID token and a 7-day expiry are generated,
     * the invitation record is persisted, and the GameInvitationMail is
     * dispatched. Mail failures are logged but do not abort the remaining sends.
     *
     * @param  int           $gameId  The ID of the game to invite players to.
     * @param  int           $userId  The ID of the authenticated creator.
     * @param  list<string>  $emails  Unique email addresses to invite.
     * @return list<GameInvitation>   The created invitation records.
     *
     * @throws InvalidArgumentException  When the user does not own the game or the
     *                                   email count exceeds the available slots.
     */
    public function sendInvitations(int $gameId, int $userId, array $emails): array
    {
        $game = $this->gameRepository->findById($gameId);

        if ($game === null || $game->user_id !== $userId) {
            throw new InvalidArgumentException('Game not found or you do not own this game.');
        }

        $maxGuests = $game->max_players - 1;

        if (count($emails) > $maxGuests) {
            throw new InvalidArgumentException(
                "You can invite at most {$maxGuests} player(s) to this game."
            );
        }

        $invitations = [];

        foreach ($emails as $email) {
            try {
                $token      = (string) Str::uuid();
                $expiresAt  = now()->addDays(7);
                $invitation = $this->invitationRepository->createForGame($gameId, $email, $token, $expiresAt);
                $invitations[] = $invitation;

                Mail::to($email)->send(new GameInvitationMail($invitation));
            } catch (\Throwable $e) {
                Log::error('Failed to send game invitation', [
                    'game_id'   => $gameId,
                    'email'     => $email,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $invitations;
    }

    /**
     * Re-send an invitation email to a previously joined invited player.
     *
     * Logic: Resolves the original invitation row, verifies the authenticated
     * user owns the related game, and rejects creator/self rows that do not
        * originate from an invitation. The original invitation row keeps its token
        * and accepted identity so any game_player_icons linkage remains intact;
        * only the expiry is extended before the standard GameInvitationMail is
        * sent again.
     *
     * @param  int  $gameId        The ID of the game the player belongs to.
     * @param  int  $userId        The authenticated creator requesting the re-invite.
     * @param  int  $invitationId  The original accepted invitation ID tied to the player card.
    * @return GameInvitation      The refreshed original invitation record.
     *
     * @throws InvalidArgumentException  When the game is not owned by the user or the target player is not re-invitable.
     */
    public function resendInvitation(int $gameId, int $userId, int $invitationId): GameInvitation
    {
        $game = $this->gameRepository->findById($gameId);

        if ($game === null || $game->user_id !== $userId) {
            throw new InvalidArgumentException('Game not found or you do not own this game.');
        }

        $invitation = $this->invitationRepository->findById($invitationId);

        if ($invitation === null || (int) $invitation->game_id !== $gameId) {
            throw new InvalidArgumentException('Invited player not found for this game.');
        }

        if ($invitation->email === null || trim($invitation->email) === '') {
            throw new InvalidArgumentException('This player cannot be re-invited.');
        }

        try {
            $expiresAt = now()->addDays(7);
            $invitation = $this->invitationRepository->refreshExpiry(
                $invitation->id,
                $expiresAt,
            );

            Mail::to($invitation->email)->send(new GameInvitationMail($invitation));

            return $invitation;
        } catch (\Throwable $e) {
            Log::error('Failed to resend game invitation', [
                'game_id'             => $gameId,
                'requested_by_user_id' => $userId,
                'original_invitation_id' => $invitationId,
                'email'               => $invitation->email,
                'exception'           => $e->getMessage(),
            ]);

            throw new InvalidArgumentException('Failed to send invitation.');
        }
    }

    /**
     * Find a pending invitation by token, validating it is not expired or accepted.
     *
     * Logic: Delegates the lookup to the repository (which eager-loads the game
     * and creator). Throws InvalidArgumentException for unknown, already-accepted,
     * or expired tokens so callers receive consistent typed errors.
     *
     * @param  string  $token  The UUID token from the join URL.
     * @return GameInvitation  The valid, pending invitation.
     *
     * @throws InvalidArgumentException  When the token is invalid, already accepted, or expired.
     */
    public function findPendingInvitation(string $token): GameInvitation
    {
        $invitation = $this->invitationRepository->findByToken($token);

        if ($invitation === null) {
            throw new InvalidArgumentException('Invitation not found.');
        }

        if ($invitation->accepted_at !== null) {
            throw new InvalidArgumentException('This invitation has already been used.');
        }

        if ($invitation->expires_at->isPast()) {
            throw new InvalidArgumentException('This invitation has expired.');
        }

        return $invitation;
    }

    /**
     * Find an accepted invitation by token.
     *
     * Logic: Delegates the lookup to the repository (which eager-loads the game
     * and creator). Throws InvalidArgumentException for unknown tokens or tokens
     * that have not yet been accepted, so callers can confirm the holder has
     * already joined before allowing guest gameplay actions.
     *
     * @param  string  $token  The UUID token from the join URL.
     * @return GameInvitation  The accepted invitation.
     *
     * @throws InvalidArgumentException  When the token is invalid or not yet accepted.
     */
    public function findAcceptedInvitation(string $token): GameInvitation
    {
        $invitation = $this->invitationRepository->findByToken($token);

        if ($invitation === null) {
            throw new InvalidArgumentException('Invitation not found.');
        }

        if ($invitation->accepted_at === null) {
            throw new InvalidArgumentException('This invitation has not been accepted yet.');
        }

        return $invitation;
    }

    /**
     * Accept a game invitation and assign the chosen player icon.
     *
     * Logic: Looks up the invitation by token, validates it is pending and not
     * expired. Wraps the icon assignment and invitation update in a DB transaction
     * with a SELECT … FOR UPDATE lock on the game_player_icons table to serialise
     * concurrent icon claims. If the unique constraint fires (two players raced to
     * the same icon) a QueryException is caught and re-thrown as IconConflictException
     * (HTTP 409) so the caller can prompt the guest to pick again. After the
     * transaction commits, broadcasts PlayerJoined on the public game channel so
     * all board viewers receive the updated player list in real time. A broadcast
     * failure is logged as a warning and does not abort the join.
     *
     * @param  string  $token         The UUID token from the join URL.
     * @param  int     $playerIconId  The ID of the PlayerIcon the guest selected.
     * @return GameInvitation         The accepted invitation record.
     *
     * @throws InvalidArgumentException  When the token is invalid, already accepted, or expired.
     * @throws IconConflictException     When another player claimed the same icon concurrently.
     */
    public function acceptInvitation(string $token, int $playerIconId): GameInvitation
    {
        $invitation = $this->findPendingInvitation($token);

        try {
            DB::transaction(function () use ($invitation, $playerIconId): void {
                // Lock existing rows for this game to serialise concurrent icon assignments.
                DB::table('game_player_icons')
                    ->where('game_id', $invitation->game_id)
                    ->lockForUpdate()
                    ->get(['player_icon_id']);

                $this->playerIconRepository->assignToGame(
                    $invitation->game_id,
                    null,
                    $playerIconId,
                    $invitation->id,
                );

                $this->invitationRepository->markAccepted($invitation->id);
            });
        } catch (QueryException $e) {
            Log::warning('Icon conflict during invitation acceptance', [
                'invitation_id'  => $invitation->id,
                'player_icon_id' => $playerIconId,
                'exception'      => $e->getMessage(),
            ]);

            throw new IconConflictException();
        }

        $accepted = $this->invitationRepository->findByToken($invitation->token);
        $players  = $this->playerIconRepository->getPlayersForGame($accepted->game_id);
        $pending  = $this->invitationRepository->getPendingForGame($accepted->game_id);

        try {
            PlayerJoined::dispatch($accepted->game_id, $players, $pending);
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast PlayerJoined event', [
                'game_id'   => $accepted->game_id,
                'exception' => $e->getMessage(),
            ]);
        }

        return $accepted;
    }
}
