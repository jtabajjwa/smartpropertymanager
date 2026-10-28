<?php

namespace App\Services\TenantPortal;

use App\Mail\IssueReportedMail;
use App\Models\Issue;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * A tenant reporting an issue (from the portal or WhatsApp): saved with a
 * number and an opening "Reported" entry, and the landlord is emailed.
 *
 * Runs in the tenant's landlord scope.
 */
class IssueReporter
{
    /** @param  string|null  $photo  path of an already-stored photo on the public disk */
    public function report(Tenant $tenant, ?Lease $lease, string $category, string $title, string $description, ?string $photo = null): Issue
    {
        $issue = DB::transaction(function () use ($tenant, $lease, $category, $title, $description, $photo) {
            $issue = Issue::create([
                'tenant_id' => $tenant->id,
                'lease_id' => $lease?->id,
                'unit_id' => $lease?->unit_id,
                'category' => $category,
                'title' => $title,
                'description' => $description,
                'photo' => $photo,
                'status' => 'open',
            ]);
            $issue->update(['number' => app(NumberGenerator::class)->next($tenant->landlord_id, 'issue', 'ISS')]);
            $issue->updates()->create(['author' => 'tenant', 'status' => 'open', 'note' => 'Reported']);

            return $issue;
        });

        $this->notifyLandlord($issue->load(['tenant', 'unit.estate', 'landlord']));

        return $issue;
    }

    /** Email the landlord (their contact email, or their admins if none is set). */
    protected function notifyLandlord(Issue $issue): void
    {
        $landlord = $issue->landlord;
        $to = $landlord->contact_email
            ? [$landlord->contact_email]
            : User::where('landlord_id', $landlord->id)->where('role', 'admin')->where('is_active', true)->pluck('email')->all();

        if (!$to) {
            return;
        }

        try {
            Mail::to($to)->send(new IssueReportedMail($issue));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
