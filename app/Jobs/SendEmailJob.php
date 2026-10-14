<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Message;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\EmailLog;
use App\Support\SchoolIntegrationSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Up to 3 real failures (bad address, provider error). Waits caused by the
     * provider's rate limit or daily quota are releases, not failures, so the
     * attempt budget is larger: hourly retries cover about two days.
     */
    public int $tries = 60;

    public int $maxExceptions = 3;

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
        /** @var array<int, array{name: string, data: string, mime?: string}> base64 file contents */
        public readonly array   $attachments = [],
    ) {
        // Use default queue (redis). QueueTenancyBootstrapper tags jobs with the
        // active tenant when dispatched from a school subdomain.
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        // Made-up addresses (e.g. generated student logins) have no mailbox; sending to
        // them only uses up the daily quota that real parents' and staff emails need.
        if (\App\Support\EmailAddress::isPlaceholder($this->to) || $this->isGeneratedLogin()) {
            Log::info('SendEmailJob skipped placeholder address', ['to' => $this->to, 'subject' => $this->subject]);

            return;
        }

        // Stay under the provider's per-second limit (Resend: 10 requests/second).
        try {
            $allowed = \Illuminate\Support\Facades\Redis::throttle('mail-provider')->allow(8)->every(1)->block(5)
                ->then(fn () => true, fn () => false);
        } catch (\Throwable) {
            $allowed = true;   // no Redis (tests/local): just send
        }
        if (! $allowed) {
            $this->release(5);

            return;
        }

        try {
            $this->send();
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'daily email sending quota') !== false) {
                Log::warning('SendEmailJob: daily email quota reached, retrying in an hour', ['to' => $this->to]);
                $this->release(3600);

                return;
            }
            if (stripos($msg, 'Too many requests') !== false) {
                $this->release(10);

                return;
            }
            throw $e;
        }
    }

    /** The address belongs to an account whose email the system made up. */
    private function isGeneratedLogin(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn('users', 'email_generated')
                && (bool) \App\Models\User::where('email', $this->to)->value('email_generated');
        } catch (\Throwable) {
            return false;
        }
    }

    private function send(): void
    {
        $body     = $this->body;
        $isHtml   = $this->isHtml;
        $schoolId = $this->schoolId ? (int) $this->schoolId : null;
        $settings = SchoolIntegrationSettings::forSchool($schoolId);

        // Sender, set per message. It used to be written into the global mail
        // config, which a long-running queue worker keeps — so after one
        // school's SMTP email, later emails for other schools could go out
        // from that school's address.
        $fromAddress = config('mail.from.address');
        $mailer = Mail::getFacadeRoot();
        if ($settings->hasSmtp()) {
            config(['mail.mailers.school_smtp' => $settings->smtpMailConfig()]);
            $fromAddress = $settings->smtpFrom ?: ($settings->smtpUser ?: $fromAddress);
            $mailer = Mail::mailer('school_smtp');
        }

        // Emails about a school show the school's name as the sender and
        // reply to the school's own address; platform emails stay "Compasse".
        $school   = $this->school();
        $fromName = $school?->name ?: config('mail.from.name');
        $replyTo  = $school && filter_var($school->email, FILTER_VALIDATE_EMAIL) ? $school->email : null;

        $mailer->send([], [], function (Message $mail) use ($body, $isHtml, $fromAddress, $fromName, $replyTo) {
            $mail->from($fromAddress, $fromName);
            if ($replyTo) {
                $mail->replyTo($replyTo, $fromName);
            }
            $mail->to($this->to)->subject($this->subject);

            if (! empty($this->cc)) {
                $mail->cc($this->cc);
            }
            if (! empty($this->bcc)) {
                $mail->bcc($this->bcc);
            }

            if ($isHtml) {
                $mail->html($body);
            } else {
                $mail->text($body);
            }

            foreach ($this->attachments as $file) {
                $mail->attachData(base64_decode($file['data']), $file['name'], ['mime' => $file['mime'] ?? 'application/octet-stream']);
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

    /**
     * The school sending this email: only when it was dispatched from inside
     * that school (queued jobs carry the tenant). Platform-to-school emails
     * — subscription reminders, super-admin messages — are dispatched
     * centrally and stay from Compasse even though they carry a school id.
     */
    private function school(): ?\App\Models\School
    {
        try {
            return function_exists('tenancy') && tenancy()->initialized ? \App\Models\School::first() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
