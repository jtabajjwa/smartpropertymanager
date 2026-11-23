<?php

namespace App\Actions;

use App\Models\Issue;
use App\Models\IssueUpdate;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The landlord (or staff on their behalf) changes an issue's status and/or
 * writes a note; the tenant sees both in their portal. Shared by the web
 * portal and the mobile app's API.
 */
class RespondToIssue
{
    public function __invoke(Issue $issue, string $status, ?string $note, User|StaffUser $by): IssueUpdate
    {
        $changed = $status !== $issue->status;
        if (!$changed && blank($note)) {
            throw ValidationException::withMessages(['note' => 'Choose a new status or write a note for the tenant.']);
        }

        $update = $issue->updates()->create([
            'author' => $by instanceof StaffUser ? 'staff' : 'landlord',
            'user_id' => $by instanceof StaffUser ? null : $by->id,
            'staff_user_id' => $by instanceof StaffUser ? $by->id : null,
            'status' => $changed ? $status : null,
            'note' => $note ?: null,
        ]);

        if ($changed) {
            $issue->update([
                'status' => $status,
                'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? ($issue->resolved_at ?? now()) : null,
            ]);
        }

        app(\App\Services\Push\PushNotifications::class)->issueUpdated($issue, $changed ? $status : null, $note ?: null);
        try {
            app(\App\Services\SmsNotificationService::class)->issueUpdated($issue, $changed ? $status : null, $note ?: null);
        } catch (\Throwable $e) {
            report($e); // the update is saved; a WhatsApp alert is a bonus
        }

        return $update;
    }
}
