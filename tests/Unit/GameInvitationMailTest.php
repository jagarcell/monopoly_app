<?php

namespace Tests\Unit;

use App\Mail\GameInvitationMail;
use App\Models\Game;
use App\Models\GameInvitation;
use App\Models\User;
use Tests\TestCase;

class GameInvitationMailTest extends TestCase
{
    /**
     * Ensure pending invitations still link to the token-selection join page.
     *
     * Logic: Renders the mailable with an unaccepted invitation and asserts the
     * generated email keeps the original /join/{token} route and pending-flow
     * copy so first-time guests still choose a token.
     *
     * @return void
     */
    public function test_pending_invitation_mail_links_to_join_page(): void
    {
        $mail = new GameInvitationMail($this->makeInvitation('pending-token', null));

        $content = $mail->content();
        $rendered = $mail->render();

        $this->assertStringEndsWith('/join/pending-token', $content->with['joinUrl']);
        $this->assertSame('Join the Game', $content->with['buttonLabel']);
        $this->assertStringContainsString('pick your player token and join the game', $rendered);
        $this->assertStringContainsString('Join the Game', $rendered);
    }

    /**
     * Ensure accepted invitations deep-link straight to the guest board.
     *
     * Logic: Renders the mailable with an accepted invitation and asserts the
     * generated email targets /join/{token}/game plus resume-specific copy,
     * confirming re-invited guests reuse their existing token and state.
     *
     * @return void
     */
    public function test_accepted_invitation_mail_links_to_guest_board(): void
    {
        $mail = new GameInvitationMail($this->makeInvitation('accepted-token', now()->subMinute()));

        $content = $mail->content();
        $rendered = $mail->render();

        $this->assertStringEndsWith('/join/accepted-token/game', $content->with['joinUrl']);
        $this->assertSame('Resume the Game', $content->with['buttonLabel']);
        $this->assertStringContainsString('go straight back to the game with your current token and saved player status', $rendered);
        $this->assertStringContainsString('Resume the Game', $rendered);
    }

    /**
     * Build a game invitation model graph for mail rendering.
     *
     * Logic: Creates unsaved User, Game, and GameInvitation models and wires
     * their relations together so the mailable can render creator/game fields
     * without requiring database I/O.
     *
     * @param  string     $token       The invitation token to expose in the email.
     * @param  \DateTimeInterface|null  $acceptedAt  The accepted timestamp, or null for pending invitations.
     * @return GameInvitation
     */
    private function makeInvitation(string $token, ?\DateTimeInterface $acceptedAt): GameInvitation
    {
        $creator = new User([
            'name' => 'Casey Creator',
            'email' => 'casey@example.com',
        ]);

        $game = new Game([
            'name' => 'Friday Night Monopoly',
        ]);
        $game->setRelation('user', $creator);

        $invitation = new GameInvitation([
            'email' => 'guest@example.com',
            'token' => $token,
            'accepted_at' => $acceptedAt,
            'expires_at' => now()->addDays(7),
        ]);
        $invitation->setRelation('game', $game);

        return $invitation;
    }
}