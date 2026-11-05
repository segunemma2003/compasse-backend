<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CORS must run before any other middleware
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        // Register middleware aliases
        $middleware->alias([
            'tenant' => \Stancl\Tenancy\Middleware\InitializeTenancyByRequestData::class,
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'permission' => \App\Http\Middleware\PermissionMiddleware::class,
            'module' => \App\Http\Middleware\ModuleAccessMiddleware::class,
            'capability' => \App\Http\Middleware\CapabilityMiddleware::class,
        ]);
        
        // Configure Authenticate middleware to return JSON for API routes
        // There is no web login page (the frontend handles sign-in), so guests
        // get a 401 instead of a redirect to a route that does not exist.
        $middleware->redirectGuestsTo(fn ($request) =>
            $request->expectsJson() || $request->is('api/*') || ! \Illuminate\Support\Facades\Route::has('login')
                ? null
                : route('login')
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle unauthenticated exceptions for API routes
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson() || $request->is('api/*') || ! \Illuminate\Support\Facades\Route::has('login')) {
                return response()->json([
                    'message' => 'Unauthenticated.'
                ], 401);
            }
        });

        // Requests for a domain or subdomain that is not a school (bots, typos,
        // the bare IP) are a 404, not a server error worth logging.
        $exceptions->dontReport(\Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException::class);
        $exceptions->render(function (\Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException $e, \Illuminate\Http\Request $request) {
            return response()->json(['message' => 'School not found.'], 404);
        });

        // Ensure CORS headers are always present on API error responses
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, \Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                $origin = $request->header('Origin', '*');
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Subdomain, X-Requested-With');
            }
            return $response;
        });
    })->create();
