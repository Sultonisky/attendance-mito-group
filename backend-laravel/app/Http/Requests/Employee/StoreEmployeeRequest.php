<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
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
            'employee_code'     => ['required', 'string', 'max:255', 'unique:employees,employee_code'],
            'full_name'         => ['required', 'string', 'max:255'],
            'email'             => ['nullable', 'email', 'max:255', 'unique:employees,email'],
            'phone'             => ['nullable', 'string', 'max:255'],
            'employment_status' => ['required', Rule::enum(\App\Enums\EmploymentStatus::class)],
            'join_date'         => ['required', 'date'],
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
            'employee_code.unique' => 'This employee code is already used.',
            'full_name.required' => 'Full name is required.',
            'employment_status.required' => 'Employment status is required.',
            'join_date.required' => 'Join date is required.',
        ];
    }
}
