<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()],
            'role'     => ['required', 'string', 'in:SUPER_ADMIN,ADMIN,USER'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required'     => 'Name is required.',
            'email.required'    => 'Email is required.',
            'email.email'       => 'A valid email address is required.',
            'email.unique'      => 'This email address is already taken.',
            'password.required' => 'Password is required.',
            'role.required'     => 'A role must be assigned.',
            'role.in'           => 'Role must be SUPER_ADMIN, ADMIN, or USER.',
        ];
    }
}
