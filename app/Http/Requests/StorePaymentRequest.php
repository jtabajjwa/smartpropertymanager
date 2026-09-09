<?php

namespace App\Http\Requests;

use App\Services\CurrentLandlord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['required_without:customer_id', 'nullable', Rule::exists('tenants', 'id')->where('landlord_id', CurrentLandlord::id())],
            'customer_id' => ['required_without:tenant_id', 'nullable', Rule::exists('customers', 'id')->where('landlord_id', CurrentLandlord::id())],
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'payment_date' => 'required',
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'mtn_mobile_money', 'airtel_mobile_money', 'flexipay'])],
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'receipt_photo' => 'nullable|image|max:2048',
            'send_sms' => 'nullable|boolean',
        ];
    }
}
