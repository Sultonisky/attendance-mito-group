<?php

namespace App\Domain\Attendance\DTOs;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\AttendanceVerification;

/**
 * Result of a successful attendance operation.
 */
final readonly class AttendanceResultData
{
    public function __construct(
        public AttendanceRecord $attendanceRecord,
        public ?AttendanceSession $session,
        public ?AttendanceVerification $verification,
        public array $geofence,
        public array $policy,
        public string $message,
    ) {}
}
