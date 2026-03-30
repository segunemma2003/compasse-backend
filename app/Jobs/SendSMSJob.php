<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        $provider = config('services.sms.provider', 'log');

        match ($provider) {
            'twilio' => $this->sendViaTwilio(),
            'vonage' => $this->sendViaVonage(),
            'termii' => $this->sendViaTermii(),
            default  => $this->logSMS(),
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

    // -------------------------------------------------------------------------
    // Provider implementations
    // -------------------------------------------------------------------------

    private function sendViaTwilio(): void
    {
        $sid   = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $from  = config('services.twilio.from', $this->senderId);

        $response = Http::withBasicAuth($sid, $token)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'To'   => $this->to,
                'From' => $from,
                'Body' => $this->message,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Twilio error: ' . $response->body());
        }
    }

    private function sendViaVonage(): void
    {
        $response = Http::post('https://rest.nexmo.com/sms/json', [
            'api_key'    => config('services.vonage.key'),
            'api_secret' => config('services.vonage.secret'),
            'to'         => $this->to,
            'from'       => $this->senderId,
            'text'       => $this->message,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Vonage error: ' . $response->body());
        }
    }

    private function sendViaTermii(): void
    {
        $response = Http::post('https://api.ng.termii.com/api/sms/send', [
            'api_key' => config('services.termii.key'),
            'to'      => $this->to,
            'from'    => $this->senderId,
            'sms'     => $this->message,
            'type'    => 'plain',
            'channel' => 'generic',
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Termii error: ' . $response->body());
        }
    }

    private function logSMS(): void
    {
        Log::info('SMS (log provider)', [
            'to'      => $this->to,
            'from'    => $this->senderId,
            'message' => $this->message,
        ]);
    }
}
