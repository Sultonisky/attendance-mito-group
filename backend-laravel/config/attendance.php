<?php

return [
    'gps_max_accuracy_meters' => (float) env('GPS_MAX_ACCURACY_METERS', 150),
    'outsource_geofence_radius_meters' => 150.0,
    /** Max open session length for outsource (hours). After this, session becomes incomplete/expired. */
    'outsource_max_session_hours' => (int) env('OUTSOURCE_MAX_SESSION_HOURS', 20),
    /**
     * Business / display timezone for attendance (calendar day + wall clock).
     * Instant storage remains UTC (PostgreSQL timestamptz + app.timezone UTC).
     */
    'timezone' => env('ATTENDANCE_TIMEZONE', 'Asia/Jakarta'),
];
