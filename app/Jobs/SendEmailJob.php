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
    ) {
        // Use default queue (redis). QueueTenancyBootstrapper tags jobs with the
        // active tenant when dispatched from a school subdomain.
        $this->onQueue('emails');
    }

    public function handle(): void
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
