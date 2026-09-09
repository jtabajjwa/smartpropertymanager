<?php

namespace App\Http\Requests;

use App\Services\CurrentLandlord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['required', Rule::exists('tenants', 'id')->where('landlord_id', CurrentLandlord::id())],
            'unit_id' => ['required', Rule::exists('units', 'id')->where('landlord_id', CurrentLandlord::id())],
            'status' => ['nullable', Rule::in(['active', 'terminated', 'evicted'])],
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'rent_amount' => 'required|numeric|min:0.01|max:99999999.99',
            'billing_cycle' => ['required', Rule::in(['daily', 'weekly', 'fortnightly', 'monthly', 'quarterly', 'yearly'])],
            'security_deposit' => 'nullable|numeric|min:0|max:99999999.99',
            'required_upfront_periods' => 'required|integer|min:1',
            'agreement_file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ];
    }
}
