<?php

namespace App\Http\Requests;

use App\Services\CurrentLandlord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => ['required', Rule::in(['vacant', 'occupied', 'maintenance'])],
            'estate_id' => ['required', Rule::exists('estates', 'id')->where('landlord_id', CurrentLandlord::id())],
            'photo' => \App\Services\PhotoUploader::RULES,
        ];
    }
}
