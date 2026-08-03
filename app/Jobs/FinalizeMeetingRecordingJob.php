<?php

namespace App\Jobs;

use App\Models\SchoolMeeting;
use App\Services\GoogleMeetService;
use App\Services\MuxRecordingResolver;
use App\Services\MuxLiveStreamService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FinalizeMeetingRecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $meetingId) {}

    public function handle(GoogleMeetService $meet, MuxLiveStreamService $mux): void
    {
        $meeting = SchoolMeeting::find($this->meetingId);
        if (! $meeting || ! $meeting->recording_required) {
            return;
        }

        if ($meeting->stream_provider === 'mux' && $meeting->mux_live_stream_id && $mux->isConfigured()) {
            $url = app(MuxRecordingResolver::class)->playbackUrlForLiveStreamId($meeting->mux_live_stream_id);
            if ($url) {
                $meeting->update([
                    'recording_url'    => $url,
                    'recording_status' => 'ready',
                ]);

                return;
            }
        }

        if ($meeting->stream_provider === 'meet' && $meeting->meeting_id) {
            $url = $meet->getRecordingUrl($meeting->meeting_id);
            $meeting->update([
                'recording_url'    => $url,
                'recording_status' => $url ? 'ready' : 'processing',
            ]);

            return;
        }

        $meeting->update(['recording_status' => 'unavailable']);
    }
}
