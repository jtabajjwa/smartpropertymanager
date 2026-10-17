<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVendorJobRequest;
use App\Http\Requests\UpdateVendorJobRequest;
use App\Models\VendorJob;
use App\Services\CurrentLandlord;
use App\Services\NumberGenerator;
use Illuminate\Support\Facades\DB;

class VendorJobController extends Controller
{
    public function index()
    {
        $query = VendorJob::with(['vendor', 'estate', 'unit', 'expense']);

        if (request('vendor_id')) {
            $query->where('vendor_id', request('vendor_id'));
        }

        if (request('status')) {
            $query->where('status', request('status'));
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

        return view('vendor-jobs.index', compact('vendorJobs'));
    }

    public function store(StoreVendorJobRequest $request)
    {
        $data = $request->validated();

        DB::transaction(function () use ($data) {
            $vendorJob = VendorJob::create($data);
            $vendorJob->update(['number' => app(NumberGenerator::class)->next(CurrentLandlord::id(), 'vendor_job', 'JC')]);
        });

        return redirect()->back()->with('success', 'Job card created successfully.');
    }

    public function update(UpdateVendorJobRequest $request, VendorJob $vendor_job)
    {
        $vendor_job->update($request->validated());

        return redirect()->back()->with('success', 'Job card updated successfully.');
    }

    public function destroy(VendorJob $vendor_job)
    {
        $vendor_job->delete();

        return redirect()->back()->with('success', 'Job card deleted successfully.');
    }
}
