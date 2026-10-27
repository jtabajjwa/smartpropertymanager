<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A short branded notice: heading, a few lines, one button. */
class NoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $subjectLine;

    /** @param  list<string>  $lines */
    public function __construct(
        string $subject,
        public string $heading,
        public array $lines,
        public ?string $actionText = null,
        public ?string $actionUrl = null,
    ) {
        $this->subjectLine = $subject;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.notice');
    }
}
