<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaystackService
{
    public function isConfigured(): bool
    {
        return (bool) (config('services.paystack.secret_key') && config('services.paystack.public_key'));
    }

    /**
     * Initialize a transaction (4s timeout — keeps API under 5s).
     *
     * @return array{authorization_url: string, access_code: string, reference: string}
     */
    public function initialize(float $amountNaira, string $email, string $reference, array $metadata = []): array
    {
        $secret = config('services.paystack.secret_key');
        if (! $secret) {
            throw new \RuntimeException('Paystack is not configured.');
        }

        $response = Http::timeout(4)
            ->withToken($secret)
            ->post('https://api.paystack.co/transaction/initialize', [
                'email'     => $email,
                'amount'    => (int) round($amountNaira * 100),
                'reference' => $reference,
                'currency'  => config('services.paystack.currency', 'NGN'),
                'metadata'  => $metadata,
            ]);

        if (! $response->successful() || ! $response->json('status')) {
            Log::error('Paystack initialize failed', ['body' => $response->json()]);
            throw new \RuntimeException($response->json('message') ?? 'Paystack initialize failed');
        }

        $data = $response->json('data');

        return [
            'authorization_url' => (string) ($data['authorization_url'] ?? ''),
            'access_code'       => (string) ($data['access_code'] ?? ''),
            'reference'         => (string) ($data['reference'] ?? $reference),
        ];
    }

    /**
     * @return array{status: string, amount: float, reference: string}
     */
    public function verify(string $reference): array
    {
        $secret = config('services.paystack.secret_key');
        $response = Http::timeout(4)
            ->withToken($secret)
            ->get('https://api.paystack.co/transaction/verify/' . rawurlencode($reference));

        if (! $response->successful()) {
            throw new \RuntimeException('Could not verify payment');
        }

        $data = $response->json('data') ?? [];

        return [
            'status'    => (string) ($data['status'] ?? 'failed'),
            'amount'    => ((float) ($data['amount'] ?? 0)) / 100,
            'reference' => (string) ($data['reference'] ?? $reference),
        ];
    }

    public static function generateReference(): string
    {
        return 'CMP_' . strtoupper(Str::random(12)) . '_' . time();
    }
}
