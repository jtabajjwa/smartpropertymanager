<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUtilityRequest;
use App\Http\Requests\UpdateUtilityRequest;
use App\Models\Utility;

class UtilityController extends Controller
{
    public function index()
    {
        $utilities = Utility::latest()->paginate(15);
        return view('utilities.index', compact('utilities'));
    }

    public function store(StoreUtilityRequest $request)
    {
        Utility::create($request->validated());

        return redirect()->back()->with('success', 'Utility created successfully.');
    }

    public function update(UpdateUtilityRequest $request, Utility $utility)
    {
        $utility->update($request->validated());

        return redirect()->back()->with('success', 'Utility updated successfully.');
    }

    public function destroy(Utility $utility)
    {
        $utility->delete();
        return redirect()->back()->with('success', 'Utility deleted successfully.');
    }
}
