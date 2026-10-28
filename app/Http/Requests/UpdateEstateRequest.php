<?php

namespace App\Http\Requests;

use App\Support\Places;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEstateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** No country chosen means the default (Uganda). */
    protected function prepareForValidation(): void
    {
        if (!$this->filled('country')) {
            $this->merge(['country' => Places::DEFAULT_COUNTRY]);
        }
    }

    public function messages(): array
    {
        return ['district.in' => 'Choose the district from the list.'];
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:255',
            'country' => ['required', Rule::in(Places::COUNTRIES)],
            // Ugandan districts are picked from the list; elsewhere they're typed.
            'district' => ['nullable', 'string', 'max:100', Rule::when($this->input('country') === 'Uganda', [Rule::in(Places::UGANDA_DISTRICTS)])],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'photo' => \App\Services\PhotoUploader::RULES,
            'remove_photo' => 'nullable|boolean',
        ];
    }
}
