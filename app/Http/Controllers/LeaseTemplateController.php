<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeaseTemplateRequest;
use App\Http\Requests\UpdateLeaseTemplateRequest;
use App\Models\LeaseTemplate;
use App\Services\CurrentLandlord;
use App\Services\StandardLeaseTemplate;
use Illuminate\Support\Facades\DB;

class LeaseTemplateController extends Controller
{
    public function index()
    {
        $leaseTemplates = LeaseTemplate::latest()->paginate(15);

        return view('lease-templates.index', compact('leaseTemplates'));
    }

    public function store(StoreLeaseTemplateRequest $request)
    {
        $data = $request->validated();
        $data['is_default'] = $request->boolean('is_default');

        DB::transaction(function () use ($data) {
            if ($data['is_default']) {
                LeaseTemplate::where('is_default', true)->update(['is_default' => false]);
            }

            LeaseTemplate::create($data);
        });

        return redirect()->back()->with('success', 'Lease template created successfully.');
    }

    public function update(UpdateLeaseTemplateRequest $request, LeaseTemplate $lease_template)
    {
        $data = $request->validated();
        $data['is_default'] = $request->boolean('is_default');

        DB::transaction(function () use ($data, $lease_template) {
            if ($data['is_default']) {
                LeaseTemplate::where('id', '!=', $lease_template->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $lease_template->update($data);
        });

        return redirect()->back()->with('success', 'Lease template updated successfully.');
    }

    /** Adds a fresh copy of the ready-made agreement (e.g. after the original was edited or deleted). */
    public function addStandard(StandardLeaseTemplate $standard)
    {
        $template = $standard->installFor(CurrentLandlord::id(), freshCopy: true);

        return redirect()->back()->with('success', "Added \"{$template->name}\". Edit the [bracketed] parts to suit you.");
    }

    public function destroy(LeaseTemplate $lease_template)
    {
        $lease_template->delete();

        return redirect()->back()->with('success', 'Lease template deleted successfully.');
    }
}
