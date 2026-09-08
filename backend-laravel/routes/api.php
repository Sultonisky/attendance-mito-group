<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/v1/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'laravel-api',
    ]);
});

Route::get('/v1/ai-health', function () {
    $response = Http::timeout(5)
        ->get('http://127.0.0.1:8001/health');

    return response()->json([
        'laravel' => 'ok',
        'ai' => $response->json(),
    ]);
});
