<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class OutsourceAttendanceReportRequest extends FormRequest
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
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'store_id' => ['nullable', 'integer', 'exists:work_locations,id'],
            'outsource_id' => ['nullable', 'integer', 'exists:outsources,id'],
            'status' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'max:50'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
