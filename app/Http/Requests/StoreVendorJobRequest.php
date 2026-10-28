<?php

namespace App\Http\Requests;

use App\Models\VendorJob;
use App\Services\JobCosting;
use App\Services\CurrentLandlord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVendorJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', Rule::exists('vendors', 'id')->where('landlord_id', CurrentLandlord::id())],
            'estate_id' => ['nullable', Rule::exists('estates', 'id')->where('landlord_id', CurrentLandlord::id())],
            'unit_id' => ['nullable', Rule::exists('units', 'id')->where('landlord_id', CurrentLandlord::id())],
            'expense_id' => ['nullable', Rule::exists('expenses', 'id')->where('landlord_id', CurrentLandlord::id())],
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'job_date' => 'required|date',
            'status' => ['required', Rule::in(array_keys(VendorJob::STATUSES))],
            'cost' => 'nullable|numeric|min:0|max:99999999.99',

            // How a completed job's cost is recorded (see JobCosting).
            'record' => ['nullable', Rule::in(array_keys(JobCosting::RECORD_OPTIONS))],
            'expense_item_id' => [
                Rule::requiredIf(fn () => $this->input('status') === 'completed' && $this->input('record', 'expense') === 'expense' && !$this->filled('expense_id')),
                'nullable', Rule::exists('expense_items', 'id')->where('landlord_id', CurrentLandlord::id()),
            ],
            'paid_on' => 'nullable|date|before_or_equal:today',
            'remove_expense' => 'nullable|boolean',
        ];
    }
}
