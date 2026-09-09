<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => 'sometimes|required|exists:tenants,id',
            'unit_id' => 'sometimes|required|exists:units,id',
            'status' => ['sometimes', 'required', Rule::in(['active', 'terminated', 'evicted'])],
            'start_date' => 'sometimes|required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'rent_amount' => 'sometimes|required|numeric|min:0.01|max:99999999.99',
            'billing_cycle' => ['sometimes', 'required', Rule::in(['daily', 'weekly', 'fortnightly', 'monthly', 'quarterly', 'yearly'])],
            'security_deposit' => 'sometimes|required|numeric|min:0|max:99999999.99',
            'required_upfront_periods' => 'sometimes|required|integer|min:1',
            'agreement_file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'next_invoice_date' => 'sometimes|required|date',
        ];
    }
}
