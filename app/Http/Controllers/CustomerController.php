<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $customers = $query->latest()->paginate(15)->withQueryString();

        return view('customers.index', compact('customers'));
    }

    public function store(Request $request)
    {
        if ($request->has('phone')) {
            $request->merge(['phone' => \App\Services\PhoneFormatter::format($request->phone)]);
        }
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => ['required', 'string', new \App\Rules\PhoneNumber],
        ]);

        Customer::create($validated);

        return back()->with('success', 'Customer created successfully.');
    }

    public function update(Request $request, Customer $customer)
    {
        if ($request->has('phone')) {
            $request->merge(['phone' => \App\Services\PhoneFormatter::format($request->phone)]);
        }
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => ['required', 'string', new \App\Rules\PhoneNumber],
        ]);

        $customer->update($validated);

        return back()->with('success', 'Customer updated successfully.');
    }

    public function destroy(Customer $customer)
    {
        // Check if customer has associated invoices or payments before deleting
        if ($customer->invoices()->exists() || $customer->payments()->exists()) {
            return back()->with('error', 'Cannot delete customer because they have associated invoices or payments.');
        }

        $customer->delete();

        return back()->with('success', 'Customer deleted successfully.');
    }
}
