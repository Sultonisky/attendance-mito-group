<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class ReportQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date', 'before_or_equal:to'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', 'string', 'max:50'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
