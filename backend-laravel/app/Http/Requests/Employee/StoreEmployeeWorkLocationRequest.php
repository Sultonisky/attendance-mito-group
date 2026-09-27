<?php

namespace App\Http\Requests\Employee;

use App\Enums\RecordStatus;
use App\Enums\WorkAreaType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeWorkLocationRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'area_type' => ['required', Rule::enum(WorkAreaType::class)],
            'address' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['nullable', 'numeric', 'min:1', 'max:100000'],
            'status' => ['nullable', Rule::enum(RecordStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Name is required.',
            'city.required' => 'City / work location is required.',
            'area_type.required' => 'Work area is required.',
            'latitude.required' => 'Latitude is required.',
            'longitude.required' => 'Longitude is required.',
        ];
    }
}
