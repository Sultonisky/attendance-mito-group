<?php

namespace App\Policies;

/**
 * Explicit reasons for enrollment acceptance or rejection.
 */
enum EnrollmentPolicyReason: string
{
    case Accepted = 'accepted';

    case RejectedAiFailure = 'rejected_ai_failure';

    case RejectedNoFace = 'rejected_no_face';

    case RejectedSpoofedLiveness = 'rejected_spoofed_liveness';

    case RejectedMultipleFaces = 'rejected_multiple_faces';
}
