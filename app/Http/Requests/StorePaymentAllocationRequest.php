<?php

namespace App\Http\Requests;

use App\Services\CurrentLandlord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_id' => ['required', Rule::exists('payments', 'id')->where('landlord_id', CurrentLandlord::id())],
            'invoice_id' => ['required', Rule::exists('invoices', 'id')->where('landlord_id', CurrentLandlord::id())],
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
        ];
    }
}
