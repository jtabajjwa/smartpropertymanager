<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVendorJobRequest;
use App\Http\Requests\UpdateVendorJobRequest;
use App\Models\ExpenseItems;
use App\Models\VendorJob;
use App\Services\CurrentLandlord;
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
        $query = VendorJob::with(['vendor', 'estate', 'unit', 'expense.expenseItem']);

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

        return view('vendor-jobs.index', compact('vendorJobs', 'unrecorded', 'defaultItem'));
    }

    public function store(StoreVendorJobRequest $request, JobCosting $costing)
    {
        $data = $request->validated();

        $note = DB::transaction(function () use ($data, $costing) {
            $vendorJob = VendorJob::create(Arr::except($data, self::COSTING_FIELDS));
            $vendorJob->update(['number' => app(NumberGenerator::class)->next(CurrentLandlord::id(), 'vendor_job', 'JC')]);

            return $costing->sync($vendorJob, false, $this->costing($data));
        });

        return redirect()->back()->with('success', trim('Job card created successfully. '.$note));
    }

    public function update(UpdateVendorJobRequest $request, VendorJob $vendor_job, JobCosting $costing)
    {
        $data = $request->validated();

        $note = DB::transaction(function () use ($data, $vendor_job, $costing) {
            $wasCompleted = $vendor_job->status === 'completed';
            $vendor_job->update(Arr::except($data, self::COSTING_FIELDS));

            return $costing->sync($vendor_job, $wasCompleted, $this->costing($data));
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
