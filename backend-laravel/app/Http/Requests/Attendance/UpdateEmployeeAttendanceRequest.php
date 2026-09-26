<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeAttendanceRequest extends FormRequest
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
            'check_in_at' => ['required', 'string', 'max:40'],
            'check_out_at' => ['nullable', 'string', 'max:40'],
        ];
    }
}
