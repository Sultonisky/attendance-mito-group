<?php

namespace App\Http\Requests\Outsource;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOutsourceAttendanceRequest extends FormRequest
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
            'pin_id' => ['required', 'integer', 'exists:work_location_pins,id'],
            'check_in_at' => ['required', 'string', 'max:40'],
            'check_out_at' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pin_id.required' => 'Pin is required.',
            'pin_id.exists' => 'The selected pin does not exist.',
            'check_in_at.required' => 'Clock in is required.',
        ];
    }
}
