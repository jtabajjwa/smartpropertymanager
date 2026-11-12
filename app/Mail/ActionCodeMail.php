<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A one-time code confirming a sensitive action. Sent straight away (not queued): the user is waiting for it. */
class ActionCodeMail extends Mailable
{
    public function __construct(
        public string $recipientName,
        public string $code,
        public string $action,
        public int $minutes,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->code.' is your '.config('app.name', 'Primora').' confirmation code');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.action-code');
    }
}
