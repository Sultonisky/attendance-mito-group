<?php

namespace App\Http\Requests\Employee;

use App\Enums\RecordStatus;
use App\Enums\WorkAreaType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeWorkLocationRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'max:255'],
            'area_type' => ['sometimes', 'required', Rule::enum(WorkAreaType::class)],
            'address' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['nullable', 'numeric', 'min:1', 'max:100000'],
            'status' => ['sometimes', 'required', Rule::enum(RecordStatus::class)],
        ];
    }
}
