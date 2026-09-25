<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateMonthlyRecapBulkRequest extends FormRequest
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
            'source' => ['required', 'string', Rule::in(['employee', 'outsource'])],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'force' => ['nullable', 'boolean'],
            // Optional: limit bulk to one subject (same endpoint for "all" or "one").
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'outsource_id' => ['nullable', 'integer', 'exists:outsources,id'],
        ];
    }
}
