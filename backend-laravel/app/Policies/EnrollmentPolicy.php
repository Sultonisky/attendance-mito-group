<?php

namespace App\Policies;

use App\DTO\EnrollResult;

/**
 * Laravel enrollment acceptance policy.
 *
 * FastAPI returns AI facts only. This policy converts those facts into a
 * business decision: whether the enrollment may be persisted as an active
 * biometric template.
 *
 * Rules:
 * - AI/model failure: never persist.
 * - No face detected: never persist.
 * - Multiple faces: FastAPI returns 422 before reaching this policy, but the
 *   rule is documented here for completeness.
 * - Liveness: print/replay must NOT become an ACTIVE enrolled face.
 * - Quality: advisory in AI-3.4 unless an explicit threshold already exists.
 * - Face detected: must be true for acceptance.
 * - Model version: persisted as returned by FastAPI; not hardcoded.
 */
class EnrollmentPolicy
{
    /**
     * Evaluate whether the AI facts permit enrollment persistence.
     */
    public function evaluate(EnrollResult $result): EnrollmentPolicyResult
    {
        if (! $result->enrolled) {
            return EnrollmentPolicyResult::reject(
                EnrollmentPolicyReason::RejectedAiFailure,
                'AI enrollment failed.'
            );
        }

        if (! $result->faceDetected) {
            return EnrollmentPolicyResult::reject(
                EnrollmentPolicyReason::RejectedNoFace,
                'No face was detected during enrollment.'
            );
        }

        $label = strtolower((string) ($result->liveness['label'] ?? ''));

        if (in_array($label, ['print', 'replay'], true)) {
            return EnrollmentPolicyResult::reject(
                EnrollmentPolicyReason::RejectedSpoofedLiveness,
                'Spoofed liveness detected; enrollment rejected.'
            );
        }

        return EnrollmentPolicyResult::accept();
    }
}
