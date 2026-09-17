<?php

namespace App\DTO;

/**
 * AI fact returned by the FastAPI enroll endpoint.
 *
 * FastAPI only returns enrollment facts (model version, embedding reference,
 * face detection, quality). Laravel decides what enrollment means for the
 * business process and stores the opaque reference.
 */
readonly class EnrollResult
{
    public function __construct(
        public bool $enrolled,
        public string $modelVersion,
        public string $embeddingReference,
        public bool $faceDetected,
        public array $quality,
        public array $liveness,
        public float $processingTimeMs,
    ) {}

    /**
     * Parse a FastAPI response payload into an EnrollResult.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            enrolled: (bool) ($data['enrolled'] ?? false),
            modelVersion: (string) ($data['model_version'] ?? ''),
            embeddingReference: (string) ($data['embedding_reference'] ?? ''),
            faceDetected: (bool) ($data['face_detected'] ?? false),
            quality: is_array($data['quality'] ?? null) ? $data['quality'] : [],
            liveness: is_array($data['liveness'] ?? null) ? $data['liveness'] : [],
            processingTimeMs: (float) ($data['processing_time_ms'] ?? 0.0),
        );
    }
}
