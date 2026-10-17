<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVendorRequest;
use App\Http\Requests\UpdateVendorRequest;
use App\Models\Vendor;

class VendorController extends Controller
{
    public function index()
    {
        $query = Vendor::latest();

        if (request('search')) {
            $search = request('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('contact_person', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (request('category')) {
            $query->where('category', request('category'));
        }

        $vendors = $query->paginate(15)->withQueryString();

        return view('vendors.index', compact('vendors'));
    }

    public function store(StoreVendorRequest $request)
    {
        Vendor::create($request->validated());

        return redirect()->back()->with('success', 'Vendor created successfully.');
    }

    public function update(UpdateVendorRequest $request, Vendor $vendor)
    {
        $vendor->update($request->validated());

        return redirect()->back()->with('success', 'Vendor updated successfully.');
    }

    public function destroy(Vendor $vendor)
    {
        if ($vendor->jobs()->exists()) {
            return redirect()->back()->with('error', 'Cannot delete a vendor with existing job cards.');
        }

        $vendor->delete();

        return redirect()->back()->with('success', 'Vendor deleted successfully.');
    }
}
