<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MuxRecordingResolver
{
    public function playbackUrlForLiveStreamId(string $muxLiveStreamId): ?string
    {
        if ($muxLiveStreamId === '' || ! config('services.mux.token_id')) {
            return null;
        }

        try {
            $response = Http::timeout(6)->withBasicAuth(
                config('services.mux.token_id'),
                config('services.mux.token_secret')
            )->get('https://api.mux.com/video/v1/assets', [
                'live_stream_id' => $muxLiveStreamId,
            ]);

            $playbackId = $response->json('data.0.playback_ids.0.id');
            if ($playbackId) {
                return 'https://stream.mux.com/' . $playbackId . '.m3u8';
            }
        } catch (\Throwable $e) {
            Log::warning('MuxRecordingResolver failed', ['stream' => $muxLiveStreamId, 'error' => $e->getMessage()]);
        }

        return null;
    }

    public function playbackUrlForAssetId(string $assetId): ?string
    {
        if ($assetId === '') {
            return null;
        }

        try {
            $response = Http::timeout(6)->withBasicAuth(
                config('services.mux.token_id'),
                config('services.mux.token_secret')
            )->get('https://api.mux.com/video/v1/assets/' . $assetId);

            $playbackId = $response->json('data.playback_ids.0.id');
            if ($playbackId) {
                return 'https://stream.mux.com/' . $playbackId . '.m3u8';
            }
        } catch (\Throwable $e) {
            Log::warning('MuxRecordingResolver asset failed', ['asset' => $assetId, 'error' => $e->getMessage()]);
        }

        return null;
    }
}
