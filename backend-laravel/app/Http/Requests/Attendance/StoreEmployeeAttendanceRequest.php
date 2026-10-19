<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeAttendanceRequest extends FormRequest
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
            'employee_id' => ['required_without:hris_employee_id', 'nullable', 'integer', 'exists:employees,id'],
            'hris_employee_id' => ['required_without:employee_id', 'nullable', 'string', 'max:255'],
            'nik' => ['required_with:hris_employee_id', 'nullable', 'digits:16'],
            'work_location_id' => [
                'required_with:hris_employee_id',
                'nullable',
                'integer',
                Rule::exists('employee_work_locations', 'id')
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
            ],
            'attendance_date' => ['required', 'date_format:Y-m-d'],
            'check_in_at' => ['required', 'string', 'max:40'],
            'check_out_at' => ['nullable', 'string', 'max:40'],
        ];
    }
}
