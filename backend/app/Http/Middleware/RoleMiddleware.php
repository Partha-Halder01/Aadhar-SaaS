<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.'
            ], 401);
        }

        if ($user->status === 'blocked') {
            return response()->json([
                'status' => 'error',
                'message' => 'Your account has been deactivated. Please contact support.'
            ], 403);
        }

        if ($user->role !== $role) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access. Insufficient privileges.'
            ], 403);
        }

        return $next($request);
    }
}
