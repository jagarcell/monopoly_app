<?php

namespace App\Mail;

use App\Models\GameInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GameInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * Logic: Stores the GameInvitation model so the Blade view can access the
     * game name, creator name, token, and expiry date.
     *
     * @param  GameInvitation  $invitation  The invitation record to include in the email.
     */
    public function __construct(public readonly GameInvitation $invitation)
    {
    }

    /**
     * Get the message envelope.
     *
     * Logic: Sets the subject line using the creator's name and the game name
     * so the recipient has immediate context.
     *
     * @return Envelope
     */
    public function envelope(): Envelope
    {
        $creatorName = $this->invitation->game?->user?->name ?? 'A friend';
        $gameName    = $this->invitation->game?->name ?? 'a game';

        return new Envelope(
            subject: "{$creatorName} invited you to play {$gameName} on Monopoly!",
        );
    }

    /**
     * Get the message content definition.
     *
     * Logic: Renders the Markdown Blade template, passing either the pending
     * token-picker route or the accepted guest-board route based on the
     * invitation state. This keeps first-time invites on the join flow while
     * letting re-invited guests resume the game with their existing token.
     *
     * @return Content
     */
    public function content(): Content
    {
        $hasAccepted = $this->invitation->accepted_at !== null;
        $joinUrl = url($hasAccepted
            ? '/join/' . $this->invitation->token . '/game'
            : '/join/' . $this->invitation->token);

        return new Content(
            markdown: 'emails.game-invitation',
            with: [
                'joinUrl'          => $joinUrl,
                'gameName'         => $this->invitation->game?->name ?? 'a game',
                'creatorName'      => $this->invitation->game?->user?->name ?? 'A friend',
                'expiresAt'        => $this->invitation->expires_at->toFormattedDateString(),
                'hasAccepted'      => $hasAccepted,
                'instructionText'  => $hasAccepted
                    ? 'Click the button below to go straight back to the game with your current token and saved player status.'
                    : 'Click the button below to pick your player token and join the game. No account required.',
                'buttonLabel'      => $hasAccepted ? 'Resume the Game' : 'Join the Game',
            ],
        );
    }
}
