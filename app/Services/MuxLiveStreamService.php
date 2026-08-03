<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mux Live Streams API — real RTMP ingest + HLS playback in-app.
 * @see https://docs.mux.com/guides/start-live-streaming
 */
class MuxLiveStreamService
{
    public function isConfigured(): bool
    {
        return (bool) (config('services.mux.token_id') && config('services.mux.token_secret'));
    }

    /**
     * @return array{
     *   mux_live_stream_id: string,
     *   mux_playback_id: string,
     *   mux_stream_key: string,
     *   mux_rtmp_url: string,
     *   meeting_link: string,
     *   playback_url: string
     * }
     */
    public function createLiveStream(string $title, ?int $latencyModeSeconds = null): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Mux is not configured (MUX_TOKEN_ID / MUX_TOKEN_SECRET).');
        }

        $payload = [
            'playback_policy' => ['public'],
            'new_asset_settings' => [
                'playback_policy' => ['public'],
            ],
            'passthrough' => mb_substr($title, 0, 255),
        ];

        if ($latencyModeSeconds !== null) {
            $payload['latency_mode'] = $latencyModeSeconds <= 10 ? 'low' : 'standard';
        }

        $response = Http::withBasicAuth(
            config('services.mux.token_id'),
            config('services.mux.token_secret')
        )->post('https://api.mux.com/video/v1/live-streams', ['data' => $payload]);

        if (! $response->successful()) {
            Log::error('Mux live stream create failed', ['body' => $response->json()]);
            throw new \RuntimeException('Mux API error: ' . ($response->json('error.message') ?? $response->body()));
        }

        $data = $response->json('data');
        $streamKey = $data['stream_key'] ?? '';
        $playbackId = $data['playback_ids'][0]['id'] ?? null;

        if (! $playbackId) {
            throw new \RuntimeException('Mux did not return a playback ID.');
        }

        $playbackUrl = 'https://stream.mux.com/' . $playbackId . '.m3u8';
        $rtmpUrl     = 'rtmp://global-live.mux.com:5222/app';

        return [
            'mux_live_stream_id' => (string) ($data['id'] ?? ''),
            'mux_playback_id'    => (string) $playbackId,
            'mux_stream_key'     => (string) $streamKey,
            'mux_rtmp_url'       => $rtmpUrl,
            'meeting_link'       => $playbackUrl,
            'playback_url'       => $playbackUrl,
        ];
    }

    public function signalComplete(string $muxLiveStreamId): void
    {
        if (! $this->isConfigured() || $muxLiveStreamId === '') {
            return;
        }

        Http::withBasicAuth(
            config('services.mux.token_id'),
            config('services.mux.token_secret')
        )->put("https://api.mux.com/video/v1/live-streams/{$muxLiveStreamId}/complete");
    }
}
