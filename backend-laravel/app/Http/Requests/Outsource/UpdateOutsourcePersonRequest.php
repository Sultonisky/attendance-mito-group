<?php

namespace App\Http\Requests\Outsource;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOutsourcePersonRequest extends FormRequest
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
            'name'     => ['sometimes', 'required', 'string', 'max:255'],
            'status'   => ['sometimes', 'required', 'string', 'in:active,inactive'],
            'store_id' => ['nullable', 'integer', 'exists:work_locations,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required'    => 'Name is required.',
            'name.max'         => 'Name may not exceed 255 characters.',
            'status.in'        => 'Status must be active or inactive.',
            'store_id.exists'  => 'The selected store does not exist.',
        ];
    }
}
