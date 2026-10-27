<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A tenant portal sign-in code sent by email. Sent straight away (not queued): the tenant is waiting for it. */
class TenantLoginCodeMail extends Mailable
{
    public function __construct(
        public string $recipientName,
        public string $code,
        public int $minutes,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->code.' is your '.config('app.name', 'Primora').' sign-in code',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.tenant-login-code');
    }
}
