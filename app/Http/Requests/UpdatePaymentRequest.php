<?php

namespace App\Http\Requests;

use App\Services\CurrentLandlord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['sometimes', 'required', Rule::exists('tenants', 'id')->where('landlord_id', CurrentLandlord::id())],
            'amount' => 'sometimes|required|numeric|min:0.01|max:99999999.99',
            'payment_date' => 'sometimes|required|date|before_or_equal:today',
            'payment_method' => ['sometimes', 'required', Rule::in(['cash', 'bank_transfer', 'mtn_mobile_money', 'airtel_mobile_money', 'flexipay'])],
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'receipt_photo' => 'nullable|image|max:2048',
        ];
    }
}
