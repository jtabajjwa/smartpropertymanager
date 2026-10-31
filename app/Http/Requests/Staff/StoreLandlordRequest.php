<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class StoreLandlordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => \App\Services\PhoneFormatter::format($this->phone)]);
        }
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255', new \App\Rules\OrganisationName],
            'trading_name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'admin_name' => ['required', 'string', 'max:255', new \App\Rules\PersonName],
            'email' => 'required|email:rfc,filter|unique:users,email|unique:landlords,contact_email',
            'phone' => ['required', 'string', 'unique:users,phone', 'unique:landlords,contact_phone', new \App\Rules\PhoneNumber],
            'password' => ['required', 'string', \Illuminate\Validation\Rules\Password::defaults()],
        ];
    }
}
