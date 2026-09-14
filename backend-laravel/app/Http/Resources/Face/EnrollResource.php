<?php

namespace App\Http\Resources\Face;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Standardized JSON response for face enrollment results.
 *
 * Exposes only the safe, necessary AI facts to the SPA — never raw embeddings,
 * never internal processing details beyond what's needed for UX.
 */
class EnrollResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  array<string, mixed>  $payload  The processed enrollment data.
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'success' => $data['enrolled'] ?? false,
            'data' => [
                'employee_id' => $data['employee_id'] ?? null,
                'model_version' => $data['model_version'] ?? null,
                'embedding_reference' => $data['embedding_reference'] ?? null,
                'face_detected' => $data['face_detected'] ?? false,
                'quality_score' => $data['quality_score'] ?? null,
                'message' => $data['message'] ?? null,
            ],
        ];
    }
}
