<?php

namespace App\Policies;

/**
 * Result of the enrollment policy evaluation.
 *
 * @property-read bool $accepted
 * @property-read EnrollmentPolicyReason $reason
 * @property-read string|null $message
 */
readonly class EnrollmentPolicyResult
{
    public function __construct(
        public bool $accepted,
        public EnrollmentPolicyReason $reason,
        public ?string $message = null,
    ) {}

    public static function accept(string $message = 'Enrollment accepted.'): self
    {
        return new self(true, EnrollmentPolicyReason::Accepted, $message);
    }

    public static function reject(EnrollmentPolicyReason $reason, string $message): self
    {
        return new self(false, $reason, $message);
    }
}
