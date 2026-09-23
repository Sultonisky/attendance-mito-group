<?php

namespace App\Http\Requests\Outsource;

use Illuminate\Foundation\Http\FormRequest;

class StoreOutsourcePersonRequest extends FormRequest
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
            'name'       => ['required', 'string', 'max:255'],
            'password'   => ['nullable', 'string', 'digits_between:4,8'],
            'store_id'   => ['nullable', 'integer', 'exists:work_locations,id'],
            'pin_ids'    => ['nullable', 'array'],
            'pin_ids.*'  => ['integer', 'exists:work_location_pins,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required'           => 'Name is required.',
            'name.max'                => 'Name may not exceed 255 characters.',
            'password.digits_between' => 'Password must be a numeric PIN (4–8 digits).',
            'store_id.exists'         => 'The selected store does not exist.',
        ];
    }
}
