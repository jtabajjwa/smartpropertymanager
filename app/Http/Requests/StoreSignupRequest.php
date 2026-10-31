<?php

namespace App\Http\Requests;

use App\Rules\OrganisationName;
use App\Rules\PersonName;
use App\Rules\UgandanPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreSignupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        foreach (['company_name', 'name'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => preg_replace('/\s+/u', ' ', trim($this->input($field)))]);
            }
        }

        if ($this->has('phone')) {
            $this->merge(['phone' => \App\Services\PhoneFormatter::format($this->phone)]);
        }
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255', new OrganisationName],
            'name' => ['required', 'string', 'max:255', new PersonName],
            'email' => 'required|email:rfc,filter|max:255|unique:users,email|unique:landlords,contact_email',
            'phone' => ['required', 'string', new UgandanPhone, 'unique:users,phone', 'unique:landlords,contact_phone'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ];
    }
}
