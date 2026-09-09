<?php

namespace App\Http\Requests;

use App\Services\CurrentLandlord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => 'sometimes|required|date|before_or_equal:today',
            'expense_item_id' => ['sometimes', 'required', Rule::exists('expense_items', 'id')->where('landlord_id', CurrentLandlord::id())],
            'estate_id' => ['sometimes', 'required', Rule::exists('estates', 'id')->where('landlord_id', CurrentLandlord::id())],
            'unit_id' => ['nullable', Rule::exists('units', 'id')->where('landlord_id', CurrentLandlord::id())],
            'amount' => 'sometimes|required|numeric|min:0.01|max:99999999.99',
            'notes' => 'nullable|string|max:1000',
        ];
    }
}
