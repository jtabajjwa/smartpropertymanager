<?php

namespace App\Http\Requests;

use App\Services\CurrentLandlord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        $merge = [];
        if ($this->has('primary_phone')) $merge['primary_phone'] = \App\Services\PhoneFormatter::format($this->primary_phone);
        if ($this->has('secondary_phone')) $merge['secondary_phone'] = \App\Services\PhoneFormatter::format($this->secondary_phone);
        if ($this->has('emergency_contact_phone')) $merge['emergency_contact_phone'] = \App\Services\PhoneFormatter::format($this->emergency_contact_phone);
        
        if (!empty($merge)) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'required', Rule::in(['individual', 'company'])],
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'email',
                Rule::unique('tenants', 'email')->where('landlord_id', CurrentLandlord::id())->ignore($this->route('tenant')),
            ],
            'primary_phone' => ['sometimes', 'required', 'string', new \App\Rules\PhoneNumber],
            'secondary_phone' => ['nullable', 'string', new \App\Rules\PhoneNumber],
            'photo' => 'nullable|image|max:2048',
            'lc_letter' => 'nullable|image|max:2048',
            'id_type' => ['sometimes', 'required', Rule::in(['passport', 'national_id', 'drivers_license', 'business_registration', 'other'])],
            'id_number' => 'sometimes|required|string|max:255',
            'id_scan' => 'nullable|image|max:2048',
            'emergency_contact_name' => 'sometimes|required|string|max:255',
            'emergency_contact_relationship' => ['sometimes', 'required', Rule::in(['family', 'friend', 'colleague', 'other'])],
            'emergency_contact_phone' => ['sometimes', 'required', 'string', new \App\Rules\PhoneNumber],
            'emergency_contact_email' => 'nullable|email|max:255',
            'emergency_contact_address' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ];
    }
}
