<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
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
        $employeeId = $this->route('employee')?->getKey();

        return [
            'employee_code'     => ['sometimes', 'required', 'string', 'max:255', Rule::unique('employees', 'employee_code')->ignore($employeeId)],
            'full_name'         => ['sometimes', 'required', 'string', 'max:255'],
            'email'             => ['nullable', 'email', 'max:255', Rule::unique('employees', 'email')->ignore($employeeId)],
            'phone'             => ['nullable', 'string', 'max:255'],
            'employment_status' => ['sometimes', 'required', Rule::enum(\App\Enums\EmploymentStatus::class)],
            'join_date'         => ['sometimes', 'required', 'date'],
            'end_date'          => ['nullable', 'date', 'after:join_date'],
            'job_position'      => ['nullable', 'string', 'max:255'],
            'department'        => ['nullable', 'string', 'max:255'],
            'division'          => ['nullable', 'string', 'max:255'],
            'branch'            => ['nullable', 'string', 'max:255'],
            'job_level'         => ['nullable', 'string', 'max:255'],
            'grade'             => ['nullable', 'string', 'max:255'],
            'direct_superior_id'     => ['nullable', 'integer', 'exists:employees,id'],
            'indirect_superior_id'   => ['nullable', 'integer', 'exists:employees,id'],
            'user_id'           => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_code.required' => 'Employee code is required.',
            'full_name.required' => 'Full name is required.',
            'employment_status.required' => 'Employment status is required.',
            'join_date.required' => 'Join date is required.',
        ];
    }
}
