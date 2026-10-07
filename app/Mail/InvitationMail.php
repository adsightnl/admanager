<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvitationMail extends Mailable
{
    public function __construct(public Invitation $invitation, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You have been invited to '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invitation', with: [
            'url' => $this->url,
            'inviter' => $this->invitation->inviter?->name,
            'days' => Invitation::VALID_DAYS,
        ]);
    }
}
