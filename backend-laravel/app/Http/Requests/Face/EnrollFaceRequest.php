<?php

namespace App\Http\Requests\Face;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate face enrollment requests from the SPA.
 *
 * The validated data is passed to the Action, which calls FastAPI to generate
 * an embedding. Laravel remains the authority for who can enroll and when.
 */
class EnrollFaceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Only authenticated users with employee-face-management permission can
     * enroll faces. Frontend permission checks are UX-only; the server always
     * enforces this.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('employees.manage-faces');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'image' => ['required', 'image', 'max:5120', 'dimensions:min_width=100,min_height=100,max_width=4096,max_height=4096'],
        ];
    }
}
