<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (!Auth::check()) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Authentication required'
            ], 401);
        }

        $user = Auth::user();

        if (!$user->hasPermission($permission)) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'Insufficient permissions. Required permission: ' . $permission
            ], 403);
        }

        return $next($request);
    }
}
