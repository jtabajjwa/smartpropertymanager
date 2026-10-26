<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCompanyProfileRequest;
use App\Models\Landlord;
use App\Services\CurrentLandlord;
use Illuminate\Support\Facades\Storage;

class CompanyProfileController extends Controller
{
    public function edit()
    {
        $landlord = Landlord::findOrFail(CurrentLandlord::id());

        return view('company-profile.edit', compact('landlord'));
    }

    public function update(UpdateCompanyProfileRequest $request)
    {
        $landlord = Landlord::findOrFail(CurrentLandlord::id());

        $data = $request->safe()->except('logo');

        if ($request->hasFile('logo')) {
            if ($landlord->logo) {
                Storage::disk('public')->delete($landlord->logo);
            }
            $data['logo'] = $request->file('logo')->store('landlords/logos', 'public');
        }

        $landlord->update($data);

        return redirect()->back()->with('success', 'Company profile updated successfully.');
    }

    /** Who bears the mobile money collection fee on tenant payments. */
    public function updateCollections(\Illuminate\Http\Request $request)
    {
        $landlord = Landlord::findOrFail(CurrentLandlord::id());
        abort_unless($landlord->collect_payments, 403);

        $data = $request->validate(['pass_collection_fee' => ['required', 'boolean']]);
        $landlord->update(['pass_collection_fee' => (bool) $data['pass_collection_fee']]);

        return back()->with('success', $landlord->pass_collection_fee
            ? 'Saved. Tenants will pay the collection fee on top of what they owe.'
            : 'Saved. You will pay the collection fee; tenants pay exactly what they owe.');
    }
}
