<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
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
        $userId = $this->route('user')?->getKey();

        return [
            'name'     => ['sometimes', 'required', 'string', 'max:255'],
            'email'    => ['sometimes', 'required', 'email', 'max:255', "unique:users,email,{$userId}"],
            'password' => ['nullable', 'string', Password::min(8)->mixedCase()->numbers()],
            'role'     => ['sometimes', 'required', 'string', 'in:SUPER_ADMIN,ADMIN,USER'],
            'status'   => ['sometimes', 'required', 'string', 'in:active,inactive'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required'     => 'Name is required.',
            'email.email'       => 'A valid email address is required.',
            'email.unique'      => 'This email address is already taken.',
            'role.in'           => 'Role must be SUPER_ADMIN, ADMIN, or USER.',
            'status.in'         => 'Status must be active or inactive.',
        ];
    }
}
