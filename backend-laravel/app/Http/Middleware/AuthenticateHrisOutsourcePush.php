<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateHrisOutsourcePush
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = (string) config('services.hris.outsource_push_api_token', '');

        if ($expectedToken === '') {
            Log::error('HRIS outsource person push token is not configured.');

            return response()->json([
                'success' => false,
                'message' => 'HRIS outsource person sync is not configured.',
            ], 503);
        }

        $providedToken = (string) $request->bearerToken();

        if ($providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return $next($request);
    }
}
