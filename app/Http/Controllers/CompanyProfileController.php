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
}
