<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class CheckInRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0'],
            'work_location_id' => ['nullable', 'integer', 'exists:work_locations,id'],
            'source' => ['nullable', 'string', 'max:255'],
            'device_metadata' => ['nullable', 'array'],
            'face_session_id' => ['nullable', 'string', 'max:255'],
            'face_image' => ['nullable', 'image', 'max:5120', 'dimensions:min_width=100,min_height=100,max_width=4096,max_height=4096'],
        ];
    }
}
