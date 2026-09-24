<?php

use Illuminate\Support\Facades\Route;

/**
 * SPA maintenance probe. Prefer the static public/maintenance.json when present
 * (nginx serves it even while artisan is down). If missing, return disabled JSON
 * so the browser never sees a 404 — the file is gitignored on purpose.
 */
Route::get('/maintenance.json', function () {
    $path = public_path('maintenance.json');

    if (is_file($path)) {
        return response()->file($path, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    return response()
        ->json([
            'enabled' => false,
            'retry_after' => 60,
            'message' => '',
        ])
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
});

/**
 * Serve the Vue SPA shell for all non-API browser routes.
 * Built assets live at /assets, /images, /manifest.webmanifest (public/).
 * The HTML shell is copied to public/spa.html by the production Dockerfile
 * so Laravel's public/index.php is never overwritten.
 */
Route::get('/{any?}', function () {
    $spa = public_path('spa.html');

    if (! is_file($spa)) {
        abort(503, 'Frontend build is missing. Run the production image build.');
    }

    return response()->file($spa, [
        'Content-Type' => 'text/html; charset=UTF-8',
    ]);
})->where('any', '.*');
