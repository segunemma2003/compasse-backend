<?php

namespace App\Jobs;

use App\Models\Livestream;
use App\Services\GoogleMeetService;
use App\Services\MuxLiveStreamService;
use App\Jobs\SendEmailJob;
use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class ProvisionLivestreamJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(
        public int $livestreamId,
        public string $streamProviderPref,
        public ?int $schoolId = null,
    ) {}

    public function handle(GoogleMeetService $meet, MuxLiveStreamService $mux): void
    {
        $livestream = Livestream::find($this->livestreamId);
        if (! $livestream || $livestream->status !== 'provisioning') {
            return;
        }

        try {
            $useMux = $this->streamProviderPref === 'mux' && $mux->isConfigured();
            $sub    = Config::get('tenant.subdomain', '');

            if ($useMux) {
                $created = $mux->createLiveStream($livestream->title, null, [
                    'sub'  => $sub,
                    'kind' => 'livestream',
                    'id'   => $livestream->id,
                ]);
                $livestream->fill([
                    'stream_provider'    => 'mux',
                    'meeting_link'       => $created['playback_url'],
                    'meeting_id'         => $created['mux_live_stream_id'],
                    'mux_live_stream_id' => $created['mux_live_stream_id'],
                    'mux_playback_id'    => $created['mux_playback_id'],
                    'mux_stream_key'     => $created['mux_stream_key'],
                    'mux_rtmp_url'       => $created['mux_rtmp_url'],
                ]);
            } else {
                $created = $meet->createMeeting([
                    'title'            => $livestream->title,
                    'start_time'       => $livestream->start_time,
                    'duration_minutes' => $livestream->duration_minutes,
                ]);
                $livestream->fill([
                    'stream_provider'  => 'meet',
                    'meeting_link'     => $created['meeting_link'],
                    'meeting_id'       => $created['meeting_id'],
                    'meeting_password' => $created['meeting_password'],
                ]);
            }

            $livestream->status = 'scheduled';
            $livestream->save();

            $this->dispatchInviteEmails($livestream);
        } catch (\Throwable $e) {
            Log::error('ProvisionLivestreamJob failed', ['id' => $this->livestreamId, 'error' => $e->getMessage()]);
            $livestream->update(['status' => 'cancelled']);
        }
    }

    private function dispatchInviteEmails(Livestream $livestream): void
    {
        if (! $livestream->class_id) {
            return;
        }

        $schoolId = $this->schoolId ?? $livestream->school_id;
        $title    = $livestream->title;
        $link     = $livestream->meeting_link ?? '';
        $start    = $livestream->start_time?->format('l, d M Y \a\t g:i A') ?? '';

        $html = "<p>Class session <strong>{$title}</strong> is scheduled for {$start}.</p><p><a href=\"{$link}\">Join link</a></p>";

        $students = Student::where('class_id', $livestream->class_id)->with(['user', 'guardians'])->get();
        $sent     = [];

        foreach ($students as $student) {
            $email = $student->email ?? $student->user?->email;
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL) && ! in_array($email, $sent, true)) {
                dispatch(new SendEmailJob(
                    to: $email,
                    subject: "Upcoming class: {$title}",
                    body: $html,
                    schoolId: $schoolId ? (string) $schoolId : null,
                    isHtml: true,
                    type: 'meeting_invite',
                ));
                $sent[] = $email;
            }
        }
    }
}
