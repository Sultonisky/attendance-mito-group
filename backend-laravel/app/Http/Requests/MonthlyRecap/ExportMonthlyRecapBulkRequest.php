<?php

namespace App\Http\Requests\MonthlyRecap;

use Illuminate\Foundation\Http\FormRequest;

class ExportMonthlyRecapBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('monthly_recap.export') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }
}
