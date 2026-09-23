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
     * Business / display timezone for attendance (calendar day + wall clock).
     * Instant storage remains UTC (PostgreSQL timestamptz + app.timezone UTC).
     */
    'timezone' => env('ATTENDANCE_TIMEZONE', 'Asia/Jakarta'),
];
