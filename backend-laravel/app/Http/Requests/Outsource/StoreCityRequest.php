<?php

namespace App\Http\Requests\Outsource;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCityRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('cities', 'code')->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'City name is required.',
            'name.max' => 'City name may not exceed 255 characters.',
            'code.unique' => 'This city code is already in use.',
            'code.max' => 'City code may not exceed 50 characters.',
        ];
    }
}
