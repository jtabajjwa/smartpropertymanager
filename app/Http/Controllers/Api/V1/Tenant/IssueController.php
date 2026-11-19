<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Services\PhotoUploader;
use App\Services\TenantPortal\IssueReporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Tenants reporting repairs, requests and complaints, and following them up (as on the web tenant portal). */
class IssueController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(Issue::where('tenant_id', $request->user()->id)->with('unit.estate')->withCount('updates')
            ->orderByRaw("status in ('open', 'in_progress') desc")->latest()->paginate(20)->through(fn ($i) => self::summary($i)));
    }

    /** What the report form needs: the categories, and the units the tenant rents now. */
    public function options(Request $request)
    {
        return response()->json([
            'categories' => collect(Issue::CATEGORIES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'leases' => $request->user()->leases()->with('unit.estate')->where('status', 'active')->get()->map(fn ($l) => AccountController::lease($l))->values(),
        ]);
    }

    public function store(Request $request, PhotoUploader $photos, IssueReporter $reporter)
    {
        $tenant = $request->user();
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

        $issue = $reporter->report(
            $tenant,
            $leases->firstWhere('id', (int) ($data['lease_id'] ?? 0)) ?? $leases->first(),
            $data['category'], $data['title'], $data['description'],
            $request->hasFile('photo') ? $photos->store($request->file('photo'), 'issues') : null,
        );

        return response()->json(['id' => $issue->id, 'message' => 'Thank you. Your landlord has been notified.'], 201);
    }

    public function show(Request $request, int $issue)
    {
        $issue = Issue::where('tenant_id', $request->user()->id)->with(['unit.estate', 'updates' => fn ($q) => $q->oldest('id')])->findOrFail($issue);

        return response()->json(self::summary($issue) + [
            'description' => $issue->description,
            'photo_url' => $issue->photo ? Storage::disk('public')->url($issue->photo) : null,
            'can_comment' => $issue->status !== 'closed',
            'updates' => $issue->updates->map(fn ($u) => [
                'id' => $u->id,
                'by' => match ($u->author) {
                    'tenant' => 'You',
                    'staff' => 'Primora support',
                    default => 'Your landlord',
                },
                'mine' => $u->author === 'tenant',
                'status_label' => $u->status ? (Issue::STATUSES[$u->status][0] ?? $u->status) : null,
                'note' => $u->note,
                'at' => $u->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** A follow-up note (e.g. "still leaking"); re-opens a resolved issue. */
    public function comment(Request $request, int $issue, IssueReporter $reporter)
    {
        $issue = Issue::where('tenant_id', $request->user()->id)->findOrFail($issue);
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        if ($issue->status === 'closed') {
            throw ValidationException::withMessages(['note' => 'This issue is closed. Please report a new one.']);
        }

        $reopen = $reporter->addNote($issue, $data['note']);

        return response()->json(['message' => $reopen ? 'Your note was added and the issue re-opened.' : 'Your note was added.']);
    }

    public static function summary(Issue $i): array
    {
        return [
            'id' => $i->id,
            'number' => $i->number,
            'title' => $i->title,
            'category_label' => $i->categoryLabel(),
            'status' => $i->status,
            'status_label' => $i->statusLabel(),
            'unit' => $i->unit ? trim(($i->unit->estate?->name ? $i->unit->estate->name.' · ' : '').$i->unit->name) : null,
            'update_count' => (int) ($i->updates_count ?? 0),
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }
}
