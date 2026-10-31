<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLandlordRequest extends FormRequest
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
            'company_name' => 'required|string|max:255',
            'trading_name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'email' => 'required|email|unique:landlords,contact_email,' . $this->route('landlord')->id,
            'phone' => ['required', 'string', 'unique:landlords,contact_phone,'.$this->route('landlord')->id, new \App\Rules\PhoneNumber],
        ];
    }
}
