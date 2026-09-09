<?php

namespace App\Http\Requests;

use App\Services\CurrentLandlord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'status' => ['sometimes', 'required', Rule::in(['vacant', 'occupied', 'maintenance'])],
            'estate_id' => ['sometimes', 'required', Rule::exists('estates', 'id')->where('landlord_id', CurrentLandlord::id())],
        ];
    }
}
