<?php

namespace App\Services;

use App\Models\School;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to PayHub (a separate custodial ledger + payout platform — see the
 * payhub project) on behalf of a school, so an online fee payment settles
 * into PayHub's ledger instead of Compasse hitting Paystack/Flutterwave
 * directly.
 *
 * Each school is its own PayHub "company", provisioned lazily the first
 * time anyone tries to pay a fee online for it (ensureCompanyForSchool()).
 * From then on, every charge/verify call authenticates as that school with
 * its own PayHub API key — Compasse never touches Paystack/Flutterwave
 * credentials for fee payments at all; PayHub does, on its own account.
 */
class PayHubService
{
    private const ADMIN_TOKEN_CACHE_KEY = 'payhub:admin_token';

    public function isConfigured(): bool
    {
        return (bool) (config('services.payhub.base_url')
            && config('services.payhub.admin_email')
            && config('services.payhub.admin_password'));
    }

    /**
     * Creates the PayHub company + API key for this school if it doesn't
     * have one yet. Idempotent — safe to call on every payment attempt.
     */
    public function ensureCompanyForSchool(School $school): School
    {
        if ($school->payhub_company_id && $school->payhub_api_key) {
            return $school;
        }

        if (! $this->isConfigured()) {
            throw new \RuntimeException('PayHub is not configured on this Compasse instance.');
        }

        if (! $school->payhub_company_id) {
            $school->payhub_company_id = $this->createCompany($school);
            $school->save();
        }

        if (! $school->payhub_api_key) {
            $school->payhub_api_key = $this->createApiKey($school->payhub_company_id);
            $school->save();
        }

        return $school;
    }

    /**
     * @return array{reference: string, checkout_url: string, status: string, provider: string}
     */
    public function initializeCharge(School $school, float $amount, string $customerEmail, string $redirectUrl, ?string $provider = null, array $metadata = []): array
    {
        $this->ensureCompanyForSchool($school);

        $response = Http::timeout(8)
            ->withToken($school->payhub_api_key)
            ->post($this->url('/api/v1/charges'), [
                'amount'         => (string) round($amount, 2),
                'provider'       => $provider ?: config('services.payhub.default_provider', 'paystack'),
                'customer_email' => $customerEmail,
                'metadata'       => array_merge($metadata, ['redirect_url' => $redirectUrl]),
            ]);

        if (! $response->successful()) {
            Log::error('PayHub charge initialize failed', ['school_id' => $school->id, 'body' => $response->json()]);
            throw new \RuntimeException($this->errorMessage($response, 'Could not start PayHub payment'));
        }

        $data = $response->json();

        return [
            'reference'    => (string) $data['reference'],
            'checkout_url' => (string) $data['checkout_url'],
            'status'       => (string) $data['status'],
            'provider'     => (string) $data['provider'],
        ];
    }

    /**
     * @return array{status: string, amount: float, reference: string}
     */
    public function verifyCharge(School $school, string $reference): array
    {
        if (! $school->payhub_api_key) {
            throw new \RuntimeException('This school has no PayHub account yet — nothing to verify.');
        }

        $response = Http::timeout(8)
            ->withToken($school->payhub_api_key)
            ->post($this->url('/api/v1/charges/' . rawurlencode($reference) . '/verify'));

        if (! $response->successful()) {
            Log::error('PayHub charge verify failed', ['school_id' => $school->id, 'reference' => $reference, 'body' => $response->json()]);
            throw new \RuntimeException($this->errorMessage($response, 'Could not verify PayHub payment'));
        }

        $data = $response->json();

        return [
            'status'    => (string) $data['status'], // "pending" | "success" | "failed"
            'amount'    => (float) $data['amount'],
            'reference' => (string) $data['reference'],
        ];
    }

    private function createCompany(School $school): string
    {
        $tenantId = function_exists('tenant') && tenant() ? tenant('id') : 'no-tenant';

        $response = $this->postAsAdmin('/api/v1/companies', [
            'name'        => $school->name ?: 'School',
            'email'       => $school->email ?: sprintf('school-%s@noreply.compasse.invalid', $school->id),
            'client_app'  => 'compasse',
            // Must be globally unique across every tenant's own database —
            // $school->id alone would collide across schools in different
            // tenants (each tenant is its own physical database, so ids
            // reset per tenant) and PayHub treats a repeated external_ref
            // as "same company, return it" rather than creating a new one.
            'external_ref' => $tenantId . ':' . $school->id,
            'currency'    => 'NGN',
        ]);

        if (! $response->successful()) {
            Log::error('PayHub company creation failed', ['school_id' => $school->id, 'body' => $response->json()]);
            throw new \RuntimeException($this->errorMessage($response, 'Could not provision this school on PayHub'));
        }

        return (string) $response->json('id');
    }

    private function createApiKey(string $companyId): string
    {
        $response = $this->postAsAdmin("/api/v1/companies/{$companyId}/api-keys", [
            'is_live' => (bool) config('services.payhub.live', false),
        ]);

        if (! $response->successful()) {
            Log::error('PayHub API key creation failed', ['company_id' => $companyId, 'body' => $response->json()]);
            throw new \RuntimeException($this->errorMessage($response, 'Could not issue this school a PayHub API key'));
        }

        return (string) $response->json('key');
    }

    /**
     * POSTs as the PayHub platform admin, retrying once with a freshly
     * fetched token if the cached one turned out to be expired/rejected.
     */
    private function postAsAdmin(string $path, array $body): Response
    {
        $response = Http::timeout(8)->withToken($this->adminToken())->post($this->url($path), $body);

        if ($response->status() === 401) {
            $response = Http::timeout(8)->withToken($this->adminToken(forceRefresh: true))->post($this->url($path), $body);
        }

        return $response;
    }

    private function adminToken(bool $forceRefresh = false): string
    {
        if ($forceRefresh) {
            Cache::forget(self::ADMIN_TOKEN_CACHE_KEY);
        }

        return Cache::remember(self::ADMIN_TOKEN_CACHE_KEY, now()->addMinutes(45), function () {
            $response = Http::timeout(8)->post($this->url('/api/v1/auth/admin/login'), [
                'email'    => config('services.payhub.admin_email'),
                'password' => config('services.payhub.admin_password'),
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException('Could not authenticate with PayHub as platform admin — check PAYHUB_ADMIN_EMAIL/PASSWORD.');
            }

            return (string) $response->json('access_token');
        });
    }

    private function url(string $path): string
    {
        return rtrim(config('services.payhub.base_url'), '/') . $path;
    }

    private function errorMessage(Response $response, string $default): string
    {
        $json = $response->json();

        if (is_array($json) && isset($json['detail'])) {
            return is_string($json['detail']) ? $json['detail'] : $default;
        }

        return $default;
    }
}
