<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', new \App\Rules\OrganisationName],
            'trading_name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,svg|max:2048',
            'brand_color' => 'required|string|regex:/^#[0-9a-fA-F]{6}$/',
            'invoice_footer_note' => 'nullable|string|max:1000',
        ];
    }
}
