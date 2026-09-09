<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
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
            'username' => 'required|string|max:255|alpha_dash|unique:users,username,' . $this->route('user')->id,
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . $this->route('user')->id,
            'phone' => 'nullable|string|max:20|unique:users,phone,' . $this->route('user')->id,
            'role' => 'required|in:admin,user',
        ];
    }
}
