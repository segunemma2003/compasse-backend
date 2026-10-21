<?php

namespace App\Http\Controllers;

use App\Services\TenantService;
use App\Support\GoogleConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Connect Google (for real Google Meet links). The school admin connects the school's account once
 * — used for every class and meeting — and any teacher may connect their own to host their classes.
 * Google redirects to one fixed callback on the API domain; the encrypted `state` says which school
 * and user it belongs to and where to send the browser back.
 */
class GoogleIntegrationController extends Controller
{
    private const LEADERSHIP = ['super_admin', 'school_admin', 'principal', 'admin'];

    private function view(?array $t): array
    {
        return $t ? ['connected' => true, 'email' => $t['email'] ?? null, 'connected_at' => $t['connected_at'] ?? null] : ['connected' => false];
    }

    /** GET /integrations/google */
    public function status(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json([
            'configured' => GoogleConnection::configured(),
            'redirect_uri' => GoogleConnection::redirectUri(),
            'can_manage_school' => in_array($u?->role, self::LEADERSHIP, true),
            'school' => $this->view(GoogleConnection::get(null)),
            'me' => $this->view($u ? GoogleConnection::get($u->id) : null),
        ]);
    }

    /** GET /integrations/google/connect?scope=school|me&return_to=… → {url} to send the browser to. */
    public function connect(Request $request): JsonResponse
    {
        if (! GoogleConnection::configured()) {
            return response()->json(['error' => 'Google sign-in is not set up on this Compasse server yet (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET).'], 422);
        }
        $u = $request->user();
        $scope = $request->query('scope') === 'me' ? 'me' : 'school';
        if ($scope === 'school' && ! in_array($u?->role, self::LEADERSHIP, true)) {
            return $this->forbiddenResponse('Only the school admin connects the school’s Google account.');
        }
        $state = Crypt::encryptString(json_encode([
            'sub' => Config::get('tenant.subdomain'), 'uid' => $u->id, 'scope' => $scope,
            'ret' => $this->safeReturn((string) $request->query('return_to', '')), 'exp' => time() + 900,
        ]));
        $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => config('services.google.client_id'), 'redirect_uri' => GoogleConnection::redirectUri(),
            'response_type' => 'code', 'scope' => implode(' ', GoogleConnection::SCOPES),
            'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state,
        ]);

        return response()->json(['url' => $url]);
    }

    /** GET /v1/integrations/google/callback (central, no auth) — Google sends the browser here. */
    public function callback(Request $request, TenantService $tenants)
    {
        try {
            $state = json_decode(Crypt::decryptString((string) $request->query('state')), true);
        } catch (\Throwable) {
            return response('Invalid or expired sign-in link. Please try connecting Google again.', 400);
        }
        $back = fn (string $status, string $msg = '') => redirect()->away($this->withQuery($state['ret'] ?? '', ['google' => $status, 'google_message' => $msg]));
        if (($state['exp'] ?? 0) < time()) {
            return $back('error', 'The sign-in took too long — try again.');
        }
        if ($request->filled('error')) {
            return $back('error', $request->query('error') === 'access_denied' ? 'Google access was not allowed.' : (string) $request->query('error'));
        }
        $tenant = $tenants->getTenantBySubdomain((string) ($state['sub'] ?? ''));
        if (! $tenant) {
            return $back('error', 'School not found.');
        }
        DB::purge('tenant');
        $tenants->switchToTenant($tenant);
        Config::set('tenant.subdomain', $tenant->subdomain);
        try {
            $token = GoogleConnection::exchange((string) $request->query('code'));
            if (empty($token['refresh_token'])) {
                return $back('error', 'Google did not give long-term access. Remove Compasse at myaccount.google.com/permissions and connect again.');
            }
            $owner = $state['scope'] === 'me' ? (int) $state['uid'] : null;
            GoogleConnection::save($owner, $token + ['connected_by' => (int) $state['uid']]);
        } catch (\Throwable $e) {
            return $back('error', $e->getMessage());
        }

        return $back('connected', $token['email'] ?? '');
    }

    /** DELETE /integrations/google?scope=school|me */
    public function disconnect(Request $request): JsonResponse
    {
        $u = $request->user();
        $scope = $request->query('scope') === 'me' ? 'me' : 'school';
        if ($scope === 'school' && ! in_array($u?->role, self::LEADERSHIP, true)) {
            return $this->forbiddenResponse();
        }
        GoogleConnection::forget($scope === 'me' ? $u->id : null);

        return response()->json(['message' => 'Google disconnected']);
    }

    /** Only send the browser back to a Compasse page. */
    private function safeReturn(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $root = config('services.google.frontend_domain', 'compasse.net');
        $ok = $host && ($host === $root || str_ends_with($host, '.' . $root) || in_array($host, ['localhost', '127.0.0.1'], true));

        return $ok ? $url : 'https://' . $root;
    }

    private function withQuery(string $url, array $q): string
    {
        $url = $url ?: 'https://' . config('services.google.frontend_domain', 'compasse.net');

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query(array_filter($q));
    }
}
