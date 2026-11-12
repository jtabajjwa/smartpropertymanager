<?php

namespace App\Http\Requests;

use App\Services\CurrentLandlord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSecurityDepositLedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => 'required|date',
            'lease_id' => ['required', Rule::exists('leases', 'id')->where('landlord_id', CurrentLandlord::id())],
            'type' => ['required', Rule::in(['refund', 'deduction', 'forfeit'])],
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'notes' => 'required_if:type,deduction|nullable|string|max:1000',
            'category' => ['required_if:type,deduction', 'nullable', Rule::in(array_keys(\App\Models\SecurityDepositLedger::CATEGORIES))],
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,webp,heic,pdf|max:5120',
            'record_expense' => 'nullable|boolean',
            'expense_item_id' => ['required_if:record_expense,1', 'nullable', Rule::exists('expense_items', 'id')->where('landlord_id', CurrentLandlord::id())],
        ];
    }

    public function messages(): array
    {
        return [
            'notes.required_if' => 'Say what the deposit was used for, e.g. "Replaced broken window in the sitting room".',
            'category.required_if' => 'Choose why the deposit was used.',
            'expense_item_id.required_if' => 'Choose the expense item to record the cost under.',
        ];
    }
}
