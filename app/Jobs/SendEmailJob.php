<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Message;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\EmailLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'emails';

    /**
     * Retry up to 3 times before marking as failed.
     */
    public int $tries = 3;

    /**
     * Wait 30 s, 2 min, 5 min between retries.
     */
    public array $backoff = [30, 120, 300];

    /**
     * Abandon the job after 90 seconds of execution.
     */
    public int $timeout = 90;

    public function __construct(
        public readonly string  $to,
        public readonly string  $subject,
        public readonly string  $body,
        public readonly array   $cc       = [],
        public readonly array   $bcc      = [],
        public readonly ?string $schoolId = null,
        public readonly bool    $isHtml   = false,
        public readonly ?string $type     = null,
    ) {}

    public function handle(): void
    {
        $body   = $this->body;
        $isHtml = $this->isHtml;

        Mail::send([], [], function (Message $mail) use ($body, $isHtml) {
            $mail->to($this->to)->subject($this->subject);

            if (!empty($this->cc)) {
                $mail->cc($this->cc);
            }
            if (!empty($this->bcc)) {
                $mail->bcc($this->bcc);
            }

            if ($isHtml) {
                $mail->html($body);
            } else {
                $mail->text($body);
            }
        });

        EmailLog::create([
            'to'        => $this->to,
            'subject'   => $this->subject,
            'status'    => 'sent',
            'school_id' => $this->schoolId,
            'type'      => $this->type,
            'sent_at'   => now(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SendEmailJob permanently failed', [
            'to'        => $this->to,
            'subject'   => $this->subject,
            'school_id' => $this->schoolId,
            'error'     => $e->getMessage(),
        ]);

        EmailLog::create([
            'to'        => $this->to,
            'subject'   => $this->subject,
            'status'    => 'failed',
            'error'     => $e->getMessage(),
            'school_id' => $this->schoolId,
            'type'      => $this->type,
            'sent_at'   => now(),
        ]);
    }
}
