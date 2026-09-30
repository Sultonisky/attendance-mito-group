<?php

return [
    'gps_max_accuracy_meters' => (float) env('GPS_MAX_ACCURACY_METERS', 150),
    'outsource_geofence_radius_meters' => 150.0,
    /**
     * Max open attendance session length for outsource (hours) after clock-in.
     * Stored in PostgreSQL (attendance_sessions). After this, open IN is marked
     * incomplete/expired.
     *
     * Keep this LONGER than outsource_session.ttl_hours (auth cookie), e.g.
     * auth 12h + attendance 20h.
     *
     * Default: 20 hours.
     */
    'outsource_max_session_hours' => (int) env('OUTSOURCE_MAX_SESSION_HOURS', 20),
    /**
     * Outsource monthly history period cutoff (day of month the period starts).
     * 25 => period "2026-09" covers 2026-08-25 .. 2026-09-24. Clamped to 2..28.
     */
    'outsource_period_start_day' => (int) env('OUTSOURCE_PERIOD_START_DAY', 25),
    /**
     * First outsource history period (YYYY-MM, keyed by end month). "2026-09" =
     * 2026-08-25 .. 2026-09-24; earlier periods are not available.
     */
    'outsource_period_first' => env('OUTSOURCE_PERIOD_FIRST', '2026-09'),
    /**
     * Max outsource attendance days counted per period (first N days with a
     * clock-in). Later days in the same period are not counted nor shown in history.
     */
    'outsource_period_max_attendance_days' => (int) env('OUTSOURCE_PERIOD_MAX_ATTENDANCE_DAYS', 26),
    /**
     * Admin outsource attendance report: mark rows beyond the period quota
     * (quota_period / counted_in_quota). Disabled for now; the report stays unchanged.
     */
    'outsource_report_quota_flag' => (bool) env('OUTSOURCE_REPORT_QUOTA_FLAG', false),
    /**
     * Business / display timezone for attendance (calendar day + wall clock).
     * Instant storage remains UTC (PostgreSQL timestamptz + app.timezone UTC).
     */
    'timezone' => env('ATTENDANCE_TIMEZONE', 'Asia/Jakarta'),
];
