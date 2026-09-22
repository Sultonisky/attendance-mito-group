<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public Outsource Session Driver
    |--------------------------------------------------------------------------
    |
    | redis  — production / docker (source of truth for ephemeral sessions)
    | array  — in-memory (PHPUnit); same semantics, no Redis required
    |
    */
    'driver' => env('OUTSOURCE_SESSION_DRIVER', 'redis'),

    'ttl_hours' => (int) env('OUTSOURCE_SESSION_TTL_HOURS', 12),

    'cookie' => [
        'name' => env('OUTSOURCE_SESSION_COOKIE', 'outsource_session'),
        'path' => env('OUTSOURCE_SESSION_PATH', '/'),
        'domain' => env('OUTSOURCE_SESSION_DOMAIN'),
        'secure' => env('OUTSOURCE_SESSION_SECURE'),
        'http_only' => true,
        'same_site' => env('OUTSOURCE_SESSION_SAME_SITE', 'lax'),
    ],

    'redis' => [
        'connection' => env('OUTSOURCE_SESSION_REDIS_CONNECTION', 'default'),
        'session_prefix' => env('OUTSOURCE_SESSION_REDIS_PREFIX', 'mito:outsource:session:'),
        'outsource_index_prefix' => env('OUTSOURCE_SESSION_OUTSOURCE_INDEX_PREFIX', 'mito:outsource:idx:outsource:'),
        'device_index_prefix' => env('OUTSOURCE_SESSION_DEVICE_INDEX_PREFIX', 'mito:outsource:idx:device:'),
    ],

];
