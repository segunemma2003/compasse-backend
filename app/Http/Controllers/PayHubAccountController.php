<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Services\PayHubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The school's PayHub account, from the admin and finance dashboards:
 * see whether it's set up, create it, and open it with one click (a
 * single-use sign-in link, so staff never need a separate PayHub password).
 */
class PayHubAccountController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    /** GET /financial/payhub */
    public function show(Request $request, PayHubService $payhub): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $school = $this->school($request);

        return response()->json([
            'enabled'   => $payhub->isConfigured(),
            'connected' => $school ? $payhub->isConnected($school) : false,
            'live'      => (bool) config('services.payhub.live', false),
        ]);
    }

    /** POST /financial/payhub/connect — creates the school's PayHub account (idempotent). */
    public function connect(Request $request, PayHubService $payhub): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }
        if (! $payhub->isConfigured()) {
            return response()->json(['error' => 'PayHub is not enabled on this platform yet.'], 503);
        }

        $school = $this->school($request);
        if (! $school) {
            return response()->json(['error' => 'School not found'], 404);
        }

        try {
            $payhub->ensureCompanyForSchool($school);
        } catch (\Throwable $e) {
            Log::error('PayHub connect failed', ['school_id' => $school->id, 'error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json(['message' => 'PayHub account ready', 'connected' => true]);
    }

    /** POST /financial/payhub/login-link — one-click sign-in URL (single use, ~60s). */
    public function loginLink(Request $request, PayHubService $payhub): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $school = $this->school($request);
        if (! $school || ! $payhub->isConnected($school)) {
            return response()->json(['error' => 'Create the school\'s PayHub account first.'], 422);
        }

        try {
            return response()->json(['url' => $payhub->dashboardLoginUrl($school)]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }
}
