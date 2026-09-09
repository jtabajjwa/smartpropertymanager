<?php

namespace App\Http\Requests;

use App\Services\CurrentLandlord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSecurityDepositLedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => 'sometimes|required|date',
            'lease_id' => ['sometimes', 'required', Rule::exists('leases', 'id')->where('landlord_id', CurrentLandlord::id())],
            'type' => ['sometimes', 'required', Rule::in(['refund', 'deduction', 'forfeit'])],
            'amount' => 'sometimes|required|numeric|min:0.01|max:99999999.99',
            'notes' => 'nullable|string|max:1000',
        ];
    }
}
