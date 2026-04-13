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
     * Logic: Renders the Markdown Blade template, passing the join URL built
     * from the invitation token and the configured APP_URL.
     *
     * @return Content
     */
    public function content(): Content
    {
        $joinUrl = url('/join/' . $this->invitation->token);

        return new Content(
            markdown: 'emails.game-invitation',
            with: [
                'joinUrl'     => $joinUrl,
                'gameName'    => $this->invitation->game?->name ?? 'a game',
                'creatorName' => $this->invitation->game?->user?->name ?? 'A friend',
                'expiresAt'   => $this->invitation->expires_at->toFormattedDateString(),
            ],
        );
    }
}
