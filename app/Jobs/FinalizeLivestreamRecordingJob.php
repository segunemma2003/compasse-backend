<?php

namespace App\Jobs;

use App\Models\Livestream;
use App\Services\MuxRecordingResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FinalizeLivestreamRecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $livestreamId) {}

    public function handle(MuxRecordingResolver $resolver): void
    {
        $ls = Livestream::find($this->livestreamId);
        if (! $ls?->mux_live_stream_id) {
            return;
        }

        $url = $resolver->playbackUrlForLiveStreamId($ls->mux_live_stream_id);
        if ($url) {
            $ls->update(['recording_url' => $url]);
        }
    }
}
