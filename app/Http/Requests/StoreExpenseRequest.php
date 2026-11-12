<?php

namespace App\Http\Requests;

use App\Services\CurrentLandlord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => 'required|date|before_or_equal:today',
            'expense_item_id' => ['required', Rule::exists('expense_items', 'id')->where('landlord_id', CurrentLandlord::id())],
            'estate_id' => ['required', Rule::exists('estates', 'id')->where('landlord_id', CurrentLandlord::id())],
            'unit_id' => ['nullable', Rule::exists('units', 'id')->where('landlord_id', CurrentLandlord::id())],
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'notes' => 'nullable|string|max:1000',
            // Paid from a tenant's security deposit (see SecurityDeposits).
            'paid_from_deposit' => 'nullable|boolean',
            'deposit_lease_id' => ['required_if:paid_from_deposit,1', 'nullable', Rule::exists('leases', 'id')->where('landlord_id', CurrentLandlord::id())],
            'deposit_category' => ['required_if:paid_from_deposit,1', 'nullable', Rule::in(array_keys(\App\Models\SecurityDepositLedger::CATEGORIES))],
        ];
    }

    public function messages(): array
    {
        return [
            'deposit_lease_id.required_if' => 'Choose whose security deposit paid for this.',
            'deposit_category.required_if' => 'Choose why the deposit was used.',
        ];
    }
}
