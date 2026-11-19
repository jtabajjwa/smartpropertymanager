<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RespondToIssue;
use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\IssueUpdate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Issues tenants raised (repairs, requests, complaints): list, one with its history, and answering it. */
class IssueController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->input('status', 'active');
        $search = trim((string) $request->input('search'));

        $issues = Issue::with(['tenant', 'unit.estate'])->withCount('updates')
            ->when($status === 'active', fn ($q) => $q->whereIn('status', ['open', 'in_progress']))
            ->when(!in_array($status, ['active', 'all'], true), fn ($q) => $q->where('status', $status))
            ->when($request->input('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('title', 'like', "%{$search}%")->orWhere('number', 'like', "%{$search}%")
                ->orWhereHas('tenant', fn ($t) => $t->where('name', 'like', "%{$search}%"))))
            ->orderByRaw("status = 'open' desc")->latest()->paginate(30);

        return response()->json([
            'counts' => Issue::selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
        ] + $issues->through(fn (Issue $i) => self::summary($i))->toArray());
    }

    public function show(Issue $issue)
    {
        $issue->load(['tenant', 'unit.estate', 'updates' => fn ($q) => $q->with(['user', 'staffUser'])->oldest('id')]);

        return response()->json(self::summary($issue) + [
            'description' => $issue->description,
            'photo_url' => $issue->photo ? Storage::disk('public')->url($issue->photo) : null,
            'tenant_id' => $issue->tenant_id,
            'tenant_phone' => $issue->tenant?->primary_phone,
            'lease_id' => $issue->lease_id,
            'updates' => $issue->updates->map(fn (IssueUpdate $u) => [
                'id' => $u->id,
                'author' => $u->author,
                'by' => match ($u->author) {
                    'tenant' => $issue->tenant?->name ?? 'Tenant',
                    'staff' => 'Primora support',
                    default => $u->user?->name ?? 'Landlord',
                },
                'status' => $u->status,
                'status_label' => $u->status ? (Issue::STATUSES[$u->status][0] ?? $u->status) : null,
                'note' => $u->note,
                'at' => $u->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** Change the status and/or write to the tenant; they see it in their portal. */
    public function respond(Request $request, Issue $issue, RespondToIssue $respond)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Issue::STATUSES))],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $respond($issue, $data['status'], $data['note'] ?? null, $request->user());

        return $this->show($issue->fresh());
    }

    public static function summary(Issue $i): array
    {
        return [
            'id' => $i->id,
            'number' => $i->number,
            'title' => $i->title,
            'category' => $i->category,
            'category_label' => $i->categoryLabel(),
            'status' => $i->status,
            'status_label' => $i->statusLabel(),
            'tenant' => $i->tenant?->name,
            'unit' => $i->unit ? trim(($i->unit->estate?->name ? $i->unit->estate->name.' · ' : '').$i->unit->name) : null,
            'update_count' => (int) ($i->updates_count ?? 0),
            'created_at' => $i->created_at?->toIso8601String(),
            'resolved_at' => $i->resolved_at?->toIso8601String(),
        ];
    }
}
