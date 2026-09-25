<?php

namespace App\Http\Requests\MonthlyRecap;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GenerateMonthlyRecapRequest extends FormRequest
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
            'source' => ['nullable', 'string', Rule::in(['employee', 'outsource'])],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'outsource_id' => ['nullable', 'integer', 'exists:outsources,id'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $source = $this->input('source', 'employee');
            if ($source === 'outsource') {
                if (! $this->filled('outsource_id')) {
                    $validator->errors()->add('outsource_id', 'Outsource ID is required when source is outsource.');
                }

                return;
            }

            if (! $this->filled('employee_id')) {
                $validator->errors()->add('employee_id', 'Employee ID is required when source is employee.');
            }
        });
    }
}
