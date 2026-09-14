<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    /**
     * Authenticate requests via Bearer API token with high-performance caching
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Header only: tokens in URLs leak through logs, history and Referer headers
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated. Bearer token missing.'
            ], 401);
        }

        $tokenHash = hash('sha256', $token);
        $cacheKey = 'auth_token_uid_' . $tokenHash;

        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken || !$accessToken->tokenable_id) {
            Cache::forget($cacheKey);
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired token.'
            ], 401);
        }

        // Enforce token expiration
        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            $accessToken->delete();
            Cache::forget($cacheKey);
            return response()->json([
                'status' => 'error',
                'message' => 'Session expired. Please log in again.'
            ], 401);
        }

        // Periodically update last_used_at (every 5 mins to avoid high write lock contention)
        if (!$accessToken->last_used_at || $accessToken->last_used_at->diffInMinutes(now()) >= 5) {
            $accessToken->update(['last_used_at' => now()]);
        }

        $user = \App\Models\User::find($accessToken->tokenable_id);

        if (!$user) {
            Cache::forget($cacheKey);
            return response()->json([
                'status' => 'error',
                'message' => 'User account not found.'
            ], 401);
        }

        if ($user->status === 'blocked') {
            Cache::forget($cacheKey);
            return response()->json([
                'status' => 'error',
                'message' => 'Your account has been deactivated.'
            ], 403);
        }

        $user->withAccessToken($accessToken);
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
