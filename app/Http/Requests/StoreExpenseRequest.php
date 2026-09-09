<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => 'required|date|before_or_equal:today',
            'expense_item_id' => 'required|exists:expense_items,id',
            'estate_id' => 'required|exists:estates,id',
            'unit_id' => 'nullable|exists:units,id',
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'notes' => 'nullable|string|max:1000',
        ];
    }
}
