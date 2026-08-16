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
                'To'   => $this->to,
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
            'to'         => $this->to,
            'from'       => $settings->smsSenderId ?: $this->senderId,
            'text'       => $this->message,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Vonage error: ' . $response->body());
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
            'to'      => $this->to,
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
