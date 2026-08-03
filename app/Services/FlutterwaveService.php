<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FlutterwaveService
{
    public function isConfigured(): bool
    {
        return (bool) config('services.flutterwave.secret_key');
    }

    /**
     * @return array{link: string, reference: string}
     */
    public function initialize(float $amount, string $email, string $reference, array $meta = []): array
    {
        $secret = config('services.flutterwave.secret_key');
        if (! $secret) {
            throw new \RuntimeException('Flutterwave is not configured.');
        }

        $response = Http::timeout(4)
            ->withToken($secret)
            ->post('https://api.flutterwave.com/v3/payments', [
                'tx_ref'       => $reference,
                'amount'       => $amount,
                'currency'     => config('services.flutterwave.currency', 'NGN'),
                'redirect_url' => $meta['redirect_url'] ?? config('app.url') . '/school/my-fees?payment=verify',
                'customer'     => [
                    'email' => $email,
                    'name'  => $meta['customer_name'] ?? 'School fee payer',
                ],
                'meta' => $meta,
            ]);

        if (! $response->successful() || $response->json('status') !== 'success') {
            Log::error('Flutterwave init failed', ['body' => $response->json()]);
            throw new \RuntimeException($response->json('message') ?? 'Flutterwave initialize failed');
        }

        return [
            'link'      => (string) $response->json('data.link'),
            'reference' => $reference,
        ];
    }

    /**
     * @return array{status: string, amount: float, reference: string}
     */
    public function verifyByTransactionId(string $transactionId): array
    {
        $secret = config('services.flutterwave.secret_key');
        $response = Http::timeout(4)
            ->withToken($secret)
            ->get('https://api.flutterwave.com/v3/transactions/' . rawurlencode($transactionId) . '/verify');

        if (! $response->successful()) {
            throw new \RuntimeException('Could not verify Flutterwave payment');
        }

        $data = $response->json('data') ?? [];

        return [
            'status'    => ($data['status'] ?? '') === 'successful' ? 'success' : 'failed',
            'amount'    => (float) ($data['amount'] ?? 0),
            'reference' => (string) ($data['tx_ref'] ?? ''),
        ];
    }

    public static function generateReference(): string
    {
        return 'CMP_FW_' . strtoupper(Str::random(10)) . '_' . time();
    }
}
