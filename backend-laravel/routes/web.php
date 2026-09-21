<?php

use Illuminate\Support\Facades\Route;

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
