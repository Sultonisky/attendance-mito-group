<?php

namespace App\Http\Requests\Outsource;

use Illuminate\Foundation\Http\FormRequest;

class CheckOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0'],
            'source' => ['nullable', 'string', 'max:255'],
            'device_fingerprint' => ['required', 'string', 'min:16', 'max:128'],
            'device_metadata' => ['nullable', 'array'],
            'pin_id' => ['required', 'integer', 'exists:work_location_pins,id'],
        ];
    }
}
