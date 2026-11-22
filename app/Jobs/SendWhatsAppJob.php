<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp message through the school's WhatsApp Business account (Meta WhatsApp Cloud API).
 * Settings → Integrations → WhatsApp: access token, phone number ID and, for messages the school
 * starts (clock-in alerts), an approved template whose body has one variable, e.g.
 * "{{1}}" or "Compasse update: {{1}}". Without a template WhatsApp only delivers to parents who
 * messaged the school's number in the last 24 hours.
 */
class SendWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];
    public int $timeout = 60;

    public function __construct(
        public readonly string $to,
        public readonly string $message,
    ) {}

    /** @return array{token: ?string, phone_id: ?string, template: ?string, lang: string} */
    public static function settings(): array
    {
        // Compasse's WhatsApp account when the super admin has switched messaging on for the school.
        if (\App\Support\SchoolMessaging::managed()) {
            $p = fn ($k) => \App\Support\PlatformSettings::get($k);

            return ['token' => $p('whatsapp_token'), 'phone_id' => $p('whatsapp_phone_number_id'),
                'template' => $p('whatsapp_template'), 'lang' => $p('whatsapp_template_lang') ?? 'en'];
        }

        $rows = Schema::hasTable('settings')
            ? DB::table('settings')->whereIn('key', ['whatsapp_token', 'whatsapp_phone_number_id', 'whatsapp_template', 'whatsapp_template_lang'])->pluck('value', 'key')
            : collect();
        $v = fn ($k) => ($x = trim((string) ($rows[$k] ?? ''))) === '' ? null : $x;

        return ['token' => $v('whatsapp_token'), 'phone_id' => $v('whatsapp_phone_number_id'),
            'template' => $v('whatsapp_template'), 'lang' => $v('whatsapp_template_lang') ?? 'en'];
    }

    public static function isConfigured(): bool
    {
        $s = self::settings();

        return (bool) ($s['token'] && $s['phone_id']);
    }

    public function handle(): void
    {
        $s = self::settings();
        if (! $s['token'] || ! $s['phone_id']) {
            $this->failNow(new \RuntimeException('WhatsApp is not set up (Settings → Integrations → WhatsApp).'));

            return;
        }
        $to = SendSMSJob::normaliseNumber($this->to);
        $payload = $s['template']
            ? ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => [
                'name' => $s['template'], 'language' => ['code' => $s['lang']],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $this->message]]]],
            ]]
            : ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => $this->message]];

        $response = Http::withToken($s['token'])->acceptJson()->timeout(20)
            ->post('https://graph.facebook.com/v21.0/' . rawurlencode($s['phone_id']) . '/messages', $payload);

        if (! $response->successful()) {
            $err = 'WhatsApp: ' . $response->status() . ' ' . mb_substr((string) ($response->json('error.message') ?? $response->body()), 0, 300);
            // 4xx (bad token, unknown template, number not on WhatsApp) will not fix itself.
            if ($response->clientError() && $response->status() !== 429) {
                $this->failNow(new \RuntimeException($err));

                return;
            }
            throw new \RuntimeException($err);
        }
        SendSMSJob::record('whatsapp', $this->to, $this->message, null, 'whatsapp', 'sent');
    }

    /** No retries; outside a queue (called directly) the error is thrown to the caller. */
    private function failNow(\Throwable $e): void
    {
        if (! $this->job) {
            throw $e;
        }
        $this->fail($e);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('SendWhatsAppJob failed', ['to' => $this->to, 'error' => $e->getMessage()]);
        SendSMSJob::record('whatsapp', $this->to, $this->message, null, 'whatsapp', 'failed', $e->getMessage());
    }
}
