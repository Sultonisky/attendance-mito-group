<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | These options control how the Laravel API responds to cross-origin
    | requests coming from the Vue SPA. During local development the SPA runs
    | on a different origin (http://localhost:5173) than the API
    | (http://localhost:8000), so the API must allow those origins and allow
    | credentials (Sanctum session cookies) to be sent and read.
    |
    | Authoritative CORS decisions remain server-side; this file only controls
    | which cross-origin requests the browser is permitted to make.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'up'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:8000',
        'http://127.0.0.1:8000',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
