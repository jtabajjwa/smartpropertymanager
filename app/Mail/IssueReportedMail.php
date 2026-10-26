<?php

namespace App\Mail;

use App\Models\Issue;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/** Tells a landlord that a tenant reported an issue (with the photo attached, if any). */
class IssueReportedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Issue $issue)
    {
    }

    public function envelope(): Envelope
    {
        $where = $this->issue->unit ? " ({$this->issue->unit->name})" : '';

        return new Envelope(
            subject: "New {$this->issue->categoryLabel()} from {$this->issue->tenant->name}{$where}: {$this->issue->title}",
            replyTo: $this->issue->tenant->email ? [$this->issue->tenant->email] : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.issue-reported', with: ['issue' => $this->issue]);
    }

    public function attachments(): array
    {
        if (!$this->issue->photo || !Storage::disk('public')->exists($this->issue->photo)) {
            return [];
        }

        return [Attachment::fromStorageDisk('public', $this->issue->photo)->as('photo-'.$this->issue->number.'.'.pathinfo($this->issue->photo, PATHINFO_EXTENSION))];
    }
}
