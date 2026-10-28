<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVendorJobRequest;
use App\Http\Requests\UpdateVendorJobRequest;
use App\Models\ExpenseItems;
use App\Models\Issue;
use App\Models\VendorJob;
use App\Services\CurrentLandlord;
use App\Services\IssueJobUpdates;
use App\Services\JobCosting;
use App\Services\NumberGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class VendorJobController extends Controller
{
    /** Form fields that say how a completed job's cost is recorded, rather than describe the job. */
    protected const COSTING_FIELDS = ['record', 'expense_item_id', 'paid_on', 'expense_id', 'remove_expense'];

    public function index()
    {
        $query = VendorJob::with(['vendor', 'estate', 'unit', 'issue:id,number,title', 'expense.expenseItem']);

        if (request('vendor_id')) {
            $query->where('vendor_id', request('vendor_id'));
        }

        if (request('status')) {
            $query->where('status', request('status'));
        }

        // Completed jobs whose cost isn't in the books yet.
        if (request('costing') === 'unrecorded') {
            $query->where('status', 'completed')->whereNull('expense_id')->where('no_charge', false);
        }

        if (request('search')) {
            $search = request('search');
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', "%{$search}%"));
            });
        }

        $vendorJobs = $query->latest('job_date')->paginate(15)->withQueryString();
        $unrecorded = VendorJob::where('status', 'completed')->whereNull('expense_id')->where('no_charge', false)->count();
        $defaultItem = ExpenseItems::where('name', 'Repairs & maintenance')->first(['id', 'name']);

        // "Create job card" on an issue opens the form filled in from it.
        $fromIssue = null;
        if (request('issue') && ($issue = Issue::with('unit.estate')->find(request('issue')))) {
            $fromIssue = [
                'issue_id' => $issue->id,
                'issue_label' => "{$issue->number} — {$issue->title}",
                'title' => mb_substr($issue->title, 0, 255),
                'description' => $issue->description,
                'unit_id' => $issue->unit_id,
                'unit_name' => $issue->unit?->name,
                'estate_id' => $issue->unit?->estate_id,
                'estate_name' => $issue->unit?->estate?->name,
            ];
        }

        return view('vendor-jobs.index', compact('vendorJobs', 'unrecorded', 'defaultItem', 'fromIssue'));
    }

    public function store(StoreVendorJobRequest $request, JobCosting $costing, IssueJobUpdates $issues)
    {
        $data = $request->validated() + ['share_vendor' => false];

        $note = DB::transaction(function () use ($data, $costing, $issues) {
            $vendorJob = VendorJob::create(Arr::except($data, self::COSTING_FIELDS));
            $vendorJob->update(['number' => app(NumberGenerator::class)->next(CurrentLandlord::id(), 'vendor_job', 'JC')]);

            $note = $costing->sync($vendorJob, false, $this->costing($data));

            return trim($note.' '.$issues->sync($vendorJob, null));
        });

        // Started from an issue: go back to it, where the new job is listed.
        $back = $request->boolean('from_issue') && !empty($data['issue_id'])
            ? redirect()->route('issues.show', $data['issue_id'])
            : redirect()->back();

        return $back->with('success', trim('Job card created successfully. '.$note));
    }

    public function update(UpdateVendorJobRequest $request, VendorJob $vendor_job, JobCosting $costing, IssueJobUpdates $issues)
    {
        $data = $request->validated() + ['share_vendor' => false];

        $note = DB::transaction(function () use ($data, $vendor_job, $costing, $issues) {
            $wasCompleted = $vendor_job->status === 'completed';
            $before = ['issue_id' => $vendor_job->issue_id, 'status' => $vendor_job->status, 'job_date' => $vendor_job->job_date?->toDateString()];
            $vendor_job->update(Arr::except($data, self::COSTING_FIELDS));

            $note = $costing->sync($vendor_job, $wasCompleted, $this->costing($data));

            return trim($note.' '.$issues->sync($vendor_job->fresh(), $before));
        });

        return redirect()->back()->with('success', trim('Job card updated successfully. '.$note));
    }

    public function destroy(VendorJob $vendor_job)
    {
        $vendor_job->delete();

        return redirect()->back()->with('success', 'Job card deleted successfully.');
    }

    /** Picking an expense without saying how means "it's already recorded". */
    protected function costing(array $data): array
    {
        $costing = Arr::only($data, self::COSTING_FIELDS);
        $costing['record'] ??= !empty($data['expense_id']) ? 'existing' : 'expense';

        return $costing;
    }
}
