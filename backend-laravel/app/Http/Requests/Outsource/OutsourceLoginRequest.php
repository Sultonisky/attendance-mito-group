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
            'password' => ['required', 'string', 'min:4', 'max:255'],
            'device_fingerprint' => ['required', 'string', 'min:16', 'max:128'],
        ];
    }
}
