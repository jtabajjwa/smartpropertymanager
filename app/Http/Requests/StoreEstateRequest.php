<?php

namespace App\Http\Requests;

use App\Services\CurrentLandlord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEstateRequest extends FormRequest
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
            'location_id' => ['required', Rule::exists('locations', 'id')->where('landlord_id', CurrentLandlord::id())],
        ];
    }
}
