<?php

namespace App\Http\Requests\Outsource;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkLocationRequest extends FormRequest
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
            'name'          => ['sometimes', 'required', 'string', 'max:255'],
            'city_id'       => ['nullable', 'integer', 'exists:cities,id'],
            'latitude'      => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'     => ['nullable', 'numeric', 'between:-180,180'],
            'radius_meters' => ['nullable', 'numeric', 'min:1', 'max:10000'],
            'status'        => ['sometimes', 'required', 'string', 'in:active,inactive'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required'        => 'Store name is required.',
            'name.max'             => 'Store name may not exceed 255 characters.',
            'city_id.exists'       => 'The selected city does not exist.',
            'latitude.between'     => 'Latitude must be between -90 and 90.',
            'longitude.between'    => 'Longitude must be between -180 and 180.',
            'radius_meters.min'    => 'Radius must be at least 1 meter.',
            'radius_meters.max'    => 'Radius may not exceed 10,000 meters.',
            'status.in'            => 'Status must be active or inactive.',
        ];
    }
}
