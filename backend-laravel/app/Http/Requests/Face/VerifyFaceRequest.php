<?php

namespace App\Http\Requests\Face;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate face verification (check-in/out) requests from the SPA.
 *
 * The validated data is passed to the Action, which calls FastAPI to verify
 * the probe face against the enrolled embedding. Laravel makes the final
 * attendance decision based on the AI facts plus context (schedule, geofence,
 * policy, liveness requirement).
 */
class VerifyFaceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('face.verify');
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
            'embedding_reference' => ['sometimes', 'string', 'max:255'],
            'image' => ['required', 'image', 'max:5120', 'dimensions:min_width=100,min_height=100,max_width=4096,max_height=4096'],
            'liveness_required' => ['sometimes', 'boolean'],
        ];
    }
}
