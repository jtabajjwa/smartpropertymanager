<?php

namespace App\Services;

use App\Models\Issue;
use App\Models\StaffUser;
use App\Models\VendorJob;

/**
 * Keeps a tenant's issue up to date as the job cards booked for it change:
 * a note in the issue's history (which the tenant sees) when a repair is
 * booked, moved, cancelled or completed. The date is always given; the
 * vendor's name only when the landlord chose to share it.
 *
 * Booking a job moves an open issue to In progress. Completing the jobs
 * never resolves the issue: the landlord is prompted to do that.
 */
class IssueJobUpdates
{
    /**
     * @param  array{issue_id: ?int, status: string, job_date: ?string}|null  $before  the job before this change (null = new)
     * @return string|null  a prompt for the landlord, when every job for the issue is done
     */
    public function sync(VendorJob $job, ?array $before): ?string
    {
        $issue = $job->issue_id ? Issue::find($job->issue_id) : null;
        if (!$issue) {
            return null;
        }

        $newlyLinked = $before === null || (int) $before['issue_id'] !== (int) $job->issue_id;
        $note = $issue->isOpen() ? $this->noteFor($job, $before, $newlyLinked) : null;

        if ($note) {
            $moveToInProgress = $issue->status === 'open' && $job->status !== 'cancelled';
            $user = auth()->user();
            $issue->updates()->create([
                'author' => $user instanceof StaffUser ? 'staff' : 'landlord',
                'user_id' => $user && !$user instanceof StaffUser ? $user->id : null,
                'staff_user_id' => $user instanceof StaffUser ? $user->id : null,
                'status' => $moveToInProgress ? 'in_progress' : null,
                'note' => $note,
            ]);
            if ($moveToInProgress) {
                $issue->update(['status' => 'in_progress']);
            }
        }

        $issue->load('jobs');

        return $issue->isOpen() && $issue->jobsAllDone()
            ? "Every job card for issue {$issue->number} is completed. Open the issue to mark it resolved."
            : null;
    }

    protected function noteFor(VendorJob $job, ?array $before, bool $newlyLinked): ?string
    {
        $date = $job->job_date?->format('j M Y');
        $vendor = $job->share_vendor ? $job->vendor?->name : null;

        if ($newlyLinked) {
            return match ($job->status) {
                'cancelled' => null,
                'completed' => 'Repair carried out'.($vendor ? " by {$vendor}" : '')." on {$this->completedOn($job)}.",
                default => "Repair booked for {$date}.".($vendor ? " {$vendor} will do the work." : ''),
            };
        }

        $statusChanged = $before['status'] !== $job->status;
        $dateChanged = ($before['job_date'] ?? null) !== $job->job_date?->toDateString();

        return match (true) {
            $statusChanged && $job->status === 'cancelled' => "The repair booked for {$date} was cancelled.",
            $statusChanged && $job->status === 'completed' => 'Repair completed'.($vendor ? " by {$vendor}" : '')." on {$this->completedOn($job)}.",
            $dateChanged && in_array($job->status, ['scheduled', 'in_progress'], true) => "The repair has moved to {$date}.",
            default => null,
        };
    }

    protected function completedOn(VendorJob $job): string
    {
        return ($job->completed_on ?? today())->format('j M Y');
    }
}
