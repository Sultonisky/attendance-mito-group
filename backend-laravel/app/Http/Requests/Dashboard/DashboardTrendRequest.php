<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class DashboardTrendRequest extends FormRequest
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
            'from'   => ['required', 'date', 'before_or_equal:to'],
            'to'     => ['required', 'date', 'after_or_equal:from'],
            'source' => ['nullable', 'string', 'in:employee,outsource'],
        ];
    }
}
