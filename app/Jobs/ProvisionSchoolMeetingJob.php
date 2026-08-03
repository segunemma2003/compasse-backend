<?php

namespace App\Jobs;

use App\Models\SchoolMeeting;
use App\Services\GoogleMeetService;
use App\Services\MuxLiveStreamService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class ProvisionSchoolMeetingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(public int $meetingId) {}

    public function handle(GoogleMeetService $meet, MuxLiveStreamService $mux): void
    {
        $meeting = SchoolMeeting::find($this->meetingId);
        if (! $meeting || $meeting->status !== 'provisioning') {
            return;
        }

        try {
            $startTime = $meeting->start_time;
            $useMux    = $meeting->stream_provider === 'mux' && $mux->isConfigured();

            if ($useMux) {
                $sub     = Config::get('tenant.subdomain', '');
                $created = $mux->createLiveStream($meeting->title, null, [
                    'sub'  => $sub,
                    'kind' => 'school_meeting',
                    'id'   => $meeting->id,
                ]);
                $meeting->fill([
                    'stream_provider'    => 'mux',
                    'meeting_link'       => $created['playback_url'],
                    'meeting_id'         => $created['mux_live_stream_id'],
                    'mux_live_stream_id' => $created['mux_live_stream_id'],
                    'mux_playback_id'    => $created['mux_playback_id'],
                    'mux_stream_key'     => $created['mux_stream_key'],
                    'mux_rtmp_url'       => $created['mux_rtmp_url'],
                    'recording_status'   => $meeting->recording_required ? 'pending' : 'unavailable',
                ]);
            } else {
                $created = $meet->createMeeting([
                    'title'            => $meeting->title,
                    'start_time'       => $startTime,
                    'duration_minutes' => $meeting->duration_minutes,
                ]);
                $recording = $meeting->recording_required
                    ? $meet->getRecordingUrl($created['meeting_id'])
                    : null;

                $meeting->fill([
                    'stream_provider'  => 'meet',
                    'meeting_link'     => $created['meeting_link'],
                    'meeting_id'       => $created['meeting_id'],
                    'meeting_password' => $created['meeting_password'],
                    'recording_url'    => $recording,
                    'recording_status' => $meeting->recording_required
                        ? ($recording ? 'processing' : 'pending')
                        : 'unavailable',
                ]);
            }

            $meeting->status = 'scheduled';
            $meeting->save();
        } catch (\Throwable $e) {
            Log::error('ProvisionSchoolMeetingJob failed', ['id' => $this->meetingId, 'error' => $e->getMessage()]);
            $meeting->update(['status' => 'cancelled']);
        }
    }
}
