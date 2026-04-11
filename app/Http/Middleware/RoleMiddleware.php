<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     */
    /**
     * Accept one or more comma-separated roles.
     * The user must have AT LEAST ONE of the listed roles.
     *
     * Usage in routes:
     *   ->middleware('role:school_admin')
     *   ->middleware('role:school_admin,principal,vice_principal')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!Auth::check()) {
            return response()->json([
                'error'   => 'Unauthorized',
                'message' => 'Authentication required',
            ], 401);
        }

        $user = Auth::user();

        // Flatten: middleware can be called as role:a,b or role:a role:b
        $allowed = [];
        foreach ($roles as $part) {
            foreach (explode(',', $part) as $r) {
                $allowed[] = trim($r);
            }
        }

        // 1. Fast path — check the direct role column.
        if (isset($user->role) && in_array($user->role, $allowed, true)) {
            return $next($request);
        }

        // 2. Fallback — check the user_roles relationship.
        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole($allowed)) {
            return $next($request);
        }

        return response()->json([
            'error'   => 'Forbidden',
            'message' => 'Access denied. Required role(s): ' . implode(', ', $allowed),
        ], 403);
    }
}
