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
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if (!Auth::check()) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Authentication required'
            ], 401);
        }

        $user = Auth::user();

        if (!$user->hasRole($role)) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'Insufficient permissions. Required role: ' . $role
            ], 403);
        }

        return $next($request);
    }
}
