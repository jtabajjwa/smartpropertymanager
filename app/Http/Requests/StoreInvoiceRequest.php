<?php

namespace App\Http\Requests;

use App\Services\CurrentLandlord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lease_id' => [
                'required_without:customer_id',
                'nullable',
                Rule::exists('leases', 'id')->where('landlord_id', CurrentLandlord::id()),
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $lease = \App\Models\Lease::find($value);
                        if ($lease && in_array($lease->status, ['terminated', 'evicted'])) {
                            $fail('Cannot create an invoice for a ' . $lease->status . ' lease.');
                        }
                    }
                },
            ],
            'customer_id' => ['required_without:lease_id', 'nullable', Rule::exists('customers', 'id')->where('landlord_id', CurrentLandlord::id())],
            'type' => [
                'required',
                function ($attribute, $value, $fail) {
                    if (request()->has('customer_id') && !empty(request('customer_id'))) {
                        if ($value !== 'utility') {
                            $fail('Casual customers can only receive utility invoices.');
                        }
                    } else {
                        if (!in_array($value, ['rent', 'security_deposit', 'utility'])) {
                            $fail('The selected type is invalid.');
                        }
                    }
                },
            ],
            'utility_id' => ['required_if:type,utility', 'nullable', Rule::exists('utilities', 'id')->where('landlord_id', CurrentLandlord::id())],
            'title' => 'required|string|max:100',
            'invoice_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'due_date' => 'required|date|after_or_equal:invoice_date',
            'notes' => 'nullable|string|max:1000',
            'send_sms' => 'boolean',
            'send_email' => 'boolean',
        ];
    }
}
