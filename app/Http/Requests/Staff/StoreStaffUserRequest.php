<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class StoreStaffUserRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:staff_users,email',
            'phone' => ['nullable', 'string', 'unique:staff_users,phone', new \App\Rules\PhoneNumber],
            'password' => ['required', 'string', \Illuminate\Validation\Rules\Password::defaults()],
            'role' => 'required|in:admin,user',
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', \Illuminate\Validation\Rule::in(\App\Support\StaffPermissions::all())],
        ];
    }
}
