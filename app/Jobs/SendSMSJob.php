<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Support\SchoolIntegrationSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendSMSJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $tries   = 3;
    public array $backoff = [30, 120, 300];
    public int   $timeout = 60;

    public function __construct(
        public readonly string  $to,
        public readonly string  $message,
        public readonly string  $senderId,
        public readonly ?string $schoolId = null,
    ) {}

    public function handle(): void
    {
        $settings = SchoolIntegrationSettings::forSchool(
            $this->schoolId ? (int) $this->schoolId : null
        );

        $provider = $settings->hasSms()
            ? strtolower((string) $settings->smsProvider)
            : strtolower((string) config('services.sms.provider', 'log'));

        match ($provider) {
            'twilio' => $this->sendViaTwilio($settings),
            'vonage' => $this->sendViaVonage($settings),
            'termii' => $this->sendViaTermii($settings),
            'smslive247', 'smslive' => $this->sendViaSmsLive247($settings),
            '80kobosms', '80kobo', 'kobo80' => $this->sendVia80Kobo($settings),
            default  => $this->logSMS($settings),
        };
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SendSMSJob permanently failed', [
            'to'        => $this->to,
            'school_id' => $this->schoolId,
            'error'     => $e->getMessage(),
        ]);
    }

    private function sendViaTwilio(SchoolIntegrationSettings $settings): void
    {
        $sid   = config('services.twilio.sid');
        $token = $settings->smsApiKey ?: config('services.twilio.token');
        $from  = $settings->smsSenderId ?: config('services.twilio.from', $this->senderId);

        if (! $sid || ! $token) {
            throw new \RuntimeException('Twilio credentials are not configured.');
        }

        $response = Http::withBasicAuth($sid, $token)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'To'   => '+' . self::normaliseNumber($this->to),
                'From' => $from,
                'Body' => $this->message,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Twilio error: ' . $response->body());
        }
    }

    private function sendViaVonage(SchoolIntegrationSettings $settings): void
    {
        $response = Http::post('https://rest.nexmo.com/sms/json', [
            'api_key'    => $settings->smsApiKey ?: config('services.vonage.key'),
            'api_secret' => config('services.vonage.secret'),
            'to'         => self::normaliseNumber($this->to),
            'from'       => $settings->smsSenderId ?: $this->senderId,
            'text'       => $this->message,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Vonage error: ' . $response->body());
        }
    }

    /** International digits without "+": Nigerian local numbers (080…, 70…) become 23480…. */
    public static function normaliseNumber(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 11 && $digits[0] === '0') {
            $digits = '234' . substr($digits, 1);
        } elseif (strlen($digits) === 10 && in_array($digits[0], ['7', '8', '9'], true)) {
            $digits = '234' . $digits;
        }

        return $digits;
    }

    /** 80kobosms (Nigeria), HTTP API v2: account email + password, JSON POST. */
    private function sendVia80Kobo(SchoolIntegrationSettings $settings): void
    {
        if (! $settings->smsUsername || ! $settings->smsApiKey) {
            throw new \RuntimeException('80kobosms needs the account email and password in Settings → Integrations → SMS.');
        }
        $response = Http::acceptJson()->post('https://api.80kobosms.com/v2/app/sms', [
            'email'       => $settings->smsUsername,
            'password'    => $settings->smsApiKey,
            'sender_name' => mb_substr($settings->smsSenderId ?: $this->senderId, 0, 11),
            'recipients'  => self::normaliseNumber($this->to),
            'message'     => $this->message,
            'forcednd'    => 1,
        ]);
        $body = $response->json();
        if (! $response->successful() || (is_array($body) && (($body['status'] ?? '') === 'error' || isset($body['error'])))) {
            throw new \RuntimeException('80kobosms error: ' . $response->status() . ' ' . $response->body());
        }
    }

    /** SMSLive247 (Nigeria), API v4: POST /api/v4/sms with the account's API key as a Bearer token. */
    private function sendViaSmsLive247(SchoolIntegrationSettings $settings): void
    {
        $apiKey = $settings->smsApiKey ?: config('services.smslive247.key');
        if (! $apiKey) {
            throw new \RuntimeException('SMSLive247 API key is not configured.');
        }
        $response = Http::withToken($apiKey)->acceptJson()->post('https://api.smslive247.com/api/v4/sms', [
            'senderID'     => mb_substr($settings->smsSenderId ?: $this->senderId, 0, 11),
            'messageText'  => $this->message,
            'mobileNumber' => self::normaliseNumber($this->to),
        ]);
        if (! $response->successful()) {
            throw new \RuntimeException('SMSLive247 error: ' . $response->status() . ' ' . $response->body());
        }
    }

    private function sendViaTermii(SchoolIntegrationSettings $settings): void
    {
        $apiKey = $settings->smsApiKey ?: config('services.termii.key');
        if (! $apiKey) {
            throw new \RuntimeException('Termii API key is not configured.');
        }

        $response = Http::post('https://api.ng.termii.com/api/sms/send', [
            'api_key' => $apiKey,
            'to'      => self::normaliseNumber($this->to),
            'from'    => $settings->smsSenderId ?: $this->senderId,
            'sms'     => $this->message,
            'type'    => 'plain',
            'channel' => 'generic',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Termii error: ' . $response->body());
        }
    }

    private function logSMS(SchoolIntegrationSettings $settings): void
    {
        Log::info('SMS (log provider)', [
            'to'      => $this->to,
            'from'    => $settings->smsSenderId ?: $this->senderId,
            'message' => $this->message,
        ]);
    }
}
