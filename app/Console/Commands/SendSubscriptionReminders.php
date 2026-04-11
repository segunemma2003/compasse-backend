<?php

namespace App\Console\Commands;

use App\Jobs\SendEmailJob;
use App\Models\School;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Carbon\Carbon;

class SendSubscriptionReminders extends Command
{
    protected $signature   = 'subscriptions:send-reminders';
    protected $description = 'Send expiry reminder emails to school admins (7-day, 3-day, 1-day before expiry)';

    // Reminder intervals in days
    private array $intervals = [7, 3, 1];

    public function handle(): void
    {
        $this->info('Checking subscriptions for expiry reminders...');
        $total = 0;

        foreach ($this->intervals as $days) {
            $key = "{$days}_day";

            // Fetch active subscriptions expiring within $days days
            $subscriptions = Subscription::with('school')
                ->where('status', 'active')
                ->where('end_date', '>', now())
                ->where('end_date', '<=', now()->addDays($days)->endOfDay())
                ->get()
                ->filter(fn ($sub) => !in_array($key, $sub->reminders_sent ?? []));

            foreach ($subscriptions as $subscription) {
                $sent = $this->sendReminder($subscription, $days);

                if ($sent) {
                    $reminders   = $subscription->reminders_sent ?? [];
                    $reminders[] = $key;
                    $subscription->update(['reminders_sent' => $reminders]);
                    $total++;
                }
            }

            $this->line("  [{$days}-day] processed " . $subscriptions->count() . " subscription(s)");
        }

        $this->info("Done. {$total} reminder(s) sent.");
    }

    private function sendReminder(Subscription $subscription, int $daysLeft): bool
    {
        $school = $subscription->school;
        if (!$school) {
            return false;
        }

        $adminEmail = $school->email;
        if (!$adminEmail) {
            $this->warn("  Skipping school ID {$school->id} — no email on record");
            return false;
        }

        $appName   = config('app.name', 'Compasse');
        $schoolName = $school->name;
        $endDate   = Carbon::parse($subscription->end_date)->format('d M Y');
        $planName  = $subscription->plan?->name ?? 'your plan';
        $daysLabel = $daysLeft === 1 ? 'tomorrow' : "in {$daysLeft} days";

        $urgencyColor = match(true) {
            $daysLeft === 1 => '#dc2626',
            $daysLeft <= 3  => '#d97706',
            default         => '#4f46e5',
        };

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
        <tr>
          <td style="background:#1a1a2e;padding:28px 40px;text-align:center;">
            <h1 style="margin:0;color:#ffffff;font-size:24px;font-weight:700;">{$appName}</h1>
            <p style="margin:4px 0 0;color:#a0a0b8;font-size:13px;">Subscription Reminder</p>
          </td>
        </tr>
        <tr>
          <td style="padding:36px 40px;">
            <div style="background:{$urgencyColor}15;border-left:4px solid {$urgencyColor};border-radius:4px;padding:14px 18px;margin-bottom:24px;">
              <p style="margin:0;font-size:15px;font-weight:700;color:{$urgencyColor};">
                Your subscription expires {$daysLabel} ({$endDate})
              </p>
            </div>
            <p style="margin:0 0 16px;font-size:15px;color:#333;">Hello,</p>
            <p style="margin:0 0 20px;font-size:15px;color:#555;line-height:1.6;">
              This is a reminder that the <strong>{$planName}</strong> subscription for
              <strong>{$schoolName}</strong> will expire on <strong>{$endDate}</strong>.
            </p>
            <p style="margin:0 0 28px;font-size:15px;color:#555;line-height:1.6;">
              Please contact your administrator to renew your subscription and avoid any disruption to your school's services.
            </p>
            <p style="margin:24px 0 0;font-size:14px;color:#555;">
              Best regards,<br><strong>The {$appName} Team</strong>
            </p>
          </td>
        </tr>
        <tr>
          <td style="background:#f8f8f8;padding:16px 40px;border-top:1px solid #eee;text-align:center;">
            <p style="margin:0;font-size:12px;color:#aaa;">© {$appName} · Automated subscription notification</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

        SendEmailJob::dispatch(
            $adminEmail,
            "Action required: {$schoolName} subscription expires {$daysLabel}",
            $html,
            [],
            [],
            (string) $school->id,
            true,                        // isHtml
            'subscription_reminder',     // type
        )->onQueue('emails');

        $this->line("  Queued reminder to {$adminEmail} ({$daysLeft}-day) for {$schoolName}");
        return true;
    }
}
