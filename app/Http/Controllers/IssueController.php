<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\StaffUser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Issues tenants raised through the tenant portal: the landlord's side. */
class IssueController extends Controller
{
    public function index(Request $request)
    {
        $query = Issue::with(['tenant', 'unit.estate'])->withCount('updates');

        $status = $request->input('status', 'active');
        match ($status) {
            'active' => $query->whereIn('status', ['open', 'in_progress']),
            'all' => null,
            default => $query->where('status', $status),
        };

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        if ($search = trim((string) $request->input('search'))) {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhere('number', 'like', "%{$search}%")
                ->orWhereHas('tenant', fn ($t) => $t->where('name', 'like', "%{$search}%")));
        }

        return view('issues.index', [
            'issues' => $query->orderByRaw("status = 'open' desc")->latest()->paginate(20)->withQueryString(),
            'status' => $status,
            'counts' => Issue::selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(Issue $issue)
    {
        return view('issues.show', [
            'issue' => $issue->load(['tenant', 'unit.estate', 'lease', 'jobs' => fn ($q) => $q->with('vendor')->oldest('job_date'), 'updates' => fn ($q) => $q->with(['user', 'staffUser'])->oldest('id')]),
            'canBookJobs' => \App\Models\Landlord::find(\App\Services\CurrentLandlord::id())?->hasFeature('vendors') ?? false,
        ]);
    }

    /** Change the status and/or add a note; the tenant sees both in their portal. */
    public function update(Request $request, Issue $issue, \App\Actions\RespondToIssue $respond)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Issue::STATUSES))],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $changed = $data['status'] !== $issue->status;
        try {
            $respond($issue, $data['status'], $data['note'] ?? null, auth()->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', $changed ? 'Status updated. The tenant can see it in their portal.' : 'Note added for the tenant.');
    }
}
