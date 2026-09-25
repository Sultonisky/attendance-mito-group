<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared source filter for KPI and staff-today endpoints.
 * source: employee (default) | outsource
 */
class DashboardSourceRequest extends FormRequest
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
            'source' => ['nullable', 'string', 'in:employee,outsource'],
        ];
    }
}
