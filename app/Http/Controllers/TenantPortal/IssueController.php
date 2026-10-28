<?php

namespace App\Http\Controllers\TenantPortal;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Services\PhotoUploader;
use App\Services\TenantPortal\IssueReporter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tenants raising repairs, requests and complaints, and following their status. */
class IssueController extends Controller
{
    public function index(Request $request)
    {
        $tenant = $request->attributes->get('tenant');

        return view('tenant-portal.issues.index', [
            'issues' => Issue::where('tenant_id', $tenant->id)->withCount('updates')
                ->orderByRaw("status in ('open', 'in_progress') desc")->latest()->paginate(20),
        ]);
    }

    public function create(Request $request)
    {
        $tenant = $request->attributes->get('tenant');

        return view('tenant-portal.issues.create', [
            'leases' => $tenant->leases()->with('unit.estate')->where('status', 'active')->get(),
        ]);
    }

    public function store(Request $request, PhotoUploader $photos, IssueReporter $reporter)
    {
        $tenant = $request->attributes->get('tenant');
        $leases = $tenant->leases()->where('status', 'active')->get();

        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(Issue::CATEGORIES))],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:5000'],
            'lease_id' => ['nullable', Rule::in($leases->pluck('id')->all())],
            'photo' => PhotoUploader::RULES,
        ], [
            'title.required' => 'Give the issue a short title, e.g. "Leaking kitchen pipe".',
            'description.required' => 'Describe the issue so your landlord knows what to fix.',
        ]);

        $lease = $leases->firstWhere('id', (int) ($data['lease_id'] ?? 0)) ?? $leases->first();

        $issue = $reporter->report(
            $tenant, $lease, $data['category'], $data['title'], $data['description'],
            $request->hasFile('photo') ? $photos->store($request->file('photo'), 'issues') : null,
        );

        return redirect()->route('tenant.issues.show', $issue)->with('success', 'Thank you. Your landlord has been notified.');
    }

    public function show(Request $request, int $issue)
    {
        $issue = Issue::findOrFail($issue);
        abort_unless($issue->tenant_id === $request->attributes->get('tenant')->id, 404);

        return view('tenant-portal.issues.show', [
            'issue' => $issue->load(['unit.estate', 'updates' => fn ($q) => $q->oldest('id')]),
        ]);
    }

    /** The tenant adds a follow-up note (e.g. "still leaking"). Re-opens a resolved issue. */
    public function comment(Request $request, int $issue)
    {
        $issue = Issue::findOrFail($issue);
        abort_unless($issue->tenant_id === $request->attributes->get('tenant')->id, 404);

        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        if ($issue->status === 'closed') {
            return back()->with('error', 'This issue is closed. Please report a new one.');
        }

        $reopen = $issue->status === 'resolved';
        $issue->updates()->create(['author' => 'tenant', 'status' => $reopen ? 'open' : null, 'note' => $data['note']]);
        if ($reopen) {
            $issue->update(['status' => 'open', 'resolved_at' => null]);
        }

        return back()->with('success', $reopen ? 'Your note was added and the issue re-opened.' : 'Your note was added.');
    }
}
