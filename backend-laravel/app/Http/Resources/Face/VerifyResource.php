<?php

namespace App\Http\Resources\Face;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Standardized JSON response for face verification results.
 *
 * Exposes only safe AI facts to the SPA. Laravel's final business decision
 * (passed/failed) is included; FastAPI's raw "verified" flag alone is never
 * exposed without the full Laravel context.
 */
class VerifyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  array<string, mixed>  $payload  The processed verification data.
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'success' => true,
            'data' => [
                'employee_id' => $data['employee_id'] ?? null,
                'passed' => $data['passed'] ?? false,
                'ai_facts' => [
                    'verified' => $data['verified'] ?? false,
                    'confidence' => $data['confidence'] ?? 0.0,
                    'liveness' => $data['liveness'] ?? false,
                    'liveness_reason' => $data['liveness_reason'] ?? null,
                    'face_detected' => $data['face_detected'] ?? false,
                    'model_version' => $data['model_version'] ?? null,
                    'processing_time_ms' => $data['processing_time_ms'] ?? null,
                    'quality_score' => $data['quality_score'] ?? null,
                ],
                'message' => $data['message'] ?? null,
            ],
        ];
    }
}
