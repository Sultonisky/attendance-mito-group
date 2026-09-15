<?php

namespace App\DTO;

/**
 * AI fact returned by the FastAPI verify endpoint.
 *
 * FastAPI returns AI facts only (face match, confidence, liveness). Laravel
 * combines these with attendance context, geofence, schedule, and policy
 * to make the final business decision.
 */
readonly class VerifyResult
{
    public function __construct(
        public bool $verified,
        public float $confidence,
        public bool $liveness,
        public ?string $livenessReason,
        public bool $faceDetected,
        public string $modelVersion,
        public int $processingTimeMs,
        public float $qualityScore,
    ) {}

    /**
     * Parse a FastAPI response payload into a VerifyResult.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            verified: (bool) ($data['verified'] ?? false),
            confidence: (float) ($data['confidence'] ?? 0.0),
            liveness: (bool) ($data['liveness'] ?? false),
            livenessReason: isset($data['liveness_reason']) ? (string) $data['liveness_reason'] : null,
            faceDetected: (bool) ($data['face_detected'] ?? false),
            modelVersion: (string) ($data['model_version'] ?? ''),
            processingTimeMs: (int) ($data['processing_time_ms'] ?? 0),
            qualityScore: (float) ($data['quality_score'] ?? 0.0),
        );
    }
}
