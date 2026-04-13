<?php

namespace App\Repositories;

use App\Models\GameInvitation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class GameInvitationRepository
{
    /**
     * Persist a new game invitation record.
     *
     * Logic: Creates a GameInvitation row with the supplied game ID, email,
     * UUID token, and expiry timestamp. Returns the refreshed model so all
     * DB-defaulted columns are available to the caller.
     *
     * @param  int     $gameId     The ID of the game being joined.
     * @param  string  $email      The email address of the invited player.
     * @param  string  $token      A unique UUID used as the join-link token.
     * @param  Carbon  $expiresAt  The timestamp at which the invitation expires.
     * @return GameInvitation
     */
    public function createForGame(int $gameId, string $email, string $token, Carbon $expiresAt): GameInvitation
    {
        $invitation = GameInvitation::create([
            'game_id'    => $gameId,
            'email'      => $email,
            'token'      => $token,
            'expires_at' => $expiresAt,
        ]);

        Log::info('Game invitation created', [
            'game_id' => $gameId,
            'email'   => $email,
            'token'   => $token,
        ]);

        return $invitation->refresh();
    }

    /**
     * Find a game invitation by its join token.
     *
     * Logic: Looks up a single GameInvitation whose token matches and eagerly
     * loads the related game (with the creator user) so callers do not issue
     * additional queries. Returns null when no matching row exists.
     *
     * @param  string  $token  The UUID token from the join URL.
     * @return GameInvitation|null
     */
    public function findByToken(string $token): ?GameInvitation
    {
        return GameInvitation::with(['game.user'])
            ->where('token', $token)
            ->first();
    }

    /**
     * Mark an invitation as accepted at the current timestamp.
     *
     * Logic: Sets accepted_at to the current time and persists the change.
     * Returns the updated model.
     *
     * @param  int  $id  The primary key of the GameInvitation to mark accepted.
     * @return GameInvitation
     */
    public function markAccepted(int $id): GameInvitation
    {
        $invitation = GameInvitation::findOrFail($id);
        $invitation->accepted_at = now();
        $invitation->save();

        Log::info('Game invitation accepted', [
            'invitation_id' => $id,
            'game_id'       => $invitation->game_id,
            'email'         => $invitation->email,
        ]);

        return $invitation;
    }

    /**
     * Count the number of accepted invitations for a game.
     *
     * Logic: Counts rows in game_invitations for the given game_id where
     * accepted_at is not null, used to guard against over-capacity joins.
     *
     * @param  int  $gameId  The ID of the game.
     * @return int
     */
    public function countAcceptedByGame(int $gameId): int
    {
        return GameInvitation::where('game_id', $gameId)
            ->whereNotNull('accepted_at')
            ->count();
    }
}
