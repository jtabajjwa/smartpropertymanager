<?php

namespace App\Http\Requests;

use App\Services\CurrentLandlord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'primary_phone' => \App\Services\PhoneFormatter::format($this->primary_phone),
            'secondary_phone' => \App\Services\PhoneFormatter::format($this->secondary_phone),
            'emergency_contact_phone' => \App\Services\PhoneFormatter::format($this->emergency_contact_phone),
        ]);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['individual', 'company'])],
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('tenants', 'email')->where('landlord_id', CurrentLandlord::id())],
            'primary_phone' => ['required', 'string', new \App\Rules\PhoneNumber],
            'secondary_phone' => ['nullable', 'string', new \App\Rules\PhoneNumber],
            'photo' => 'nullable|image|max:2048',
            'lc_letter' => 'nullable|image|max:2048',
            'id_type' => ['required', Rule::in(['passport', 'national_id', 'drivers_license', 'business_registration', 'other'])],
            'id_number' => 'required|string|max:255',
            'id_scan' => 'nullable|image|max:2048',
            'emergency_contact_name' => 'required|string|max:255',
            'emergency_contact_relationship' => ['required', Rule::in(['family', 'friend', 'colleague', 'other'])],
            'emergency_contact_phone' => ['required', 'string', new \App\Rules\PhoneNumber],
            'emergency_contact_email' => 'nullable|email|max:255',
            'emergency_contact_address' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ];
    }
}
