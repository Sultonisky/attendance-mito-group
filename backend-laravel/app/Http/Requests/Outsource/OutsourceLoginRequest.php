<?php

namespace App\Http\Requests\Outsource;

use Illuminate\Foundation\Http\FormRequest;

class OutsourceLoginRequest extends FormRequest
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
            'outsource_code' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'digits_between:4,8'],
            'device_fingerprint' => ['required', 'string', 'min:16', 'max:128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.digits_between' => 'Password must be a numeric PIN (4–8 digits).',
        ];
    }
}
