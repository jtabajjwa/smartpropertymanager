<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStaffUserRequest extends FormRequest
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
            'email' => 'required|email|unique:staff_users,email,' . $this->route('staff_user')->id,
            'phone' => 'nullable|string|max:20|unique:staff_users,phone,' . $this->route('staff_user')->id,
            'role' => 'required|in:admin,user',
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', \Illuminate\Validation\Rule::in(\App\Support\StaffPermissions::all())],
        ];
    }
}
