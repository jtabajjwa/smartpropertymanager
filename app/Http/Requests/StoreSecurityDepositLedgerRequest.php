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
            'notes' => 'nullable|string|max:1000',
        ];
    }
}
