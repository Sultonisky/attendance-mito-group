<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AuthUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /**
     * Authenticate a first-party SPA user (session/cookie based via Sanctum).
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return response()->json([
            'success' => true,
            'message' => 'Authenticated successfully.',
            'data' => [
                'user' => new AuthUserResource($request->user()),
            ],
        ]);
    }

    /**
     * Invalidate the authenticated session.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Return the authenticated user's safe profile and authorization context.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'user' => new AuthUserResource($request->user()),
            ],
        ]);
    }
}
