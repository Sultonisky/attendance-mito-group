<?php

namespace App\Http\Requests\Outsource;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkLocationPinRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['nullable', 'numeric', 'min:1', 'max:5000'],
            'status' => ['sometimes', 'required', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}
