<?php

namespace App\Http\Controllers;

use App\Models\Livestream;
use App\Models\SchoolMeeting;
use App\Services\MuxRecordingResolver;
use App\Services\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MuxWebhookController extends Controller
{
    public function __construct(
        protected TenantService $tenantService,
        protected MuxRecordingResolver $recordingResolver,
    ) {}

    /**
     * Mux webhook — no auth; verified via MUX_WEBHOOK_SECRET when set.
     * Passthrough JSON: {"sub":"demoschool","kind":"livestream|school_meeting","id":123}
     */
    public function handle(Request $request): JsonResponse
    {
        $secret = config('services.mux.webhook_secret');
        if ($secret) {
            $sig = $request->header('Mux-Signature');
            if (! $this->verifyMuxSignature($request->getContent(), $sig, $secret)) {
                return response()->json(['error' => 'Invalid signature'], 401);
            }
        }

        $type = $request->input('type');
        $data = $request->input('data', []);

        if ($type === 'video.asset.ready') {
            $this->handleAssetReady($data);

            return response()->json(['ok' => true]);
        }

        if ($type === 'video.live_stream.completed') {
            $liveStreamId = $data['id'] ?? null;
            if ($liveStreamId) {
                $this->applyRecordingByMuxStreamId((string) $liveStreamId, null);
            }

            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => true, 'ignored' => $type]);
    }

    protected function handleAssetReady(array $data): void
    {
        $assetId      = $data['id'] ?? null;
        $liveStreamId = $data['live_stream_id'] ?? null;
        $passthrough  = $data['passthrough'] ?? null;

        $meta = is_string($passthrough) ? json_decode($passthrough, true) : null;
        if (! is_array($meta)) {
            $meta = null;
        }

        $url = $this->recordingResolver->playbackUrlForAssetId((string) $assetId);
        if (! $url) {
            return;
        }

        $this->applyRecordingByMuxStreamId($liveStreamId ? (string) $liveStreamId : null, $meta, $url);
    }

    protected function applyRecordingByMuxStreamId(?string $muxLiveStreamId, ?array $meta, ?string $url = null): void
    {
        if ($meta && ! empty($meta['sub'])) {
            $tenant = $this->tenantService->getTenantBySubdomain($meta['sub']);
            if ($tenant) {
                DB::purge('tenant');
                $this->tenantService->switchToTenant($tenant);
                Config::set('tenant.subdomain', $tenant->subdomain);
            }
        }

        if (! $url && $muxLiveStreamId) {
            $url = $this->recordingResolver->playbackUrlForLiveStreamId($muxLiveStreamId);
        }
        if (! $url) {
            return;
        }

        if ($meta && ($meta['kind'] ?? '') === 'school_meeting' && ! empty($meta['id'])) {
            $m = SchoolMeeting::find((int) $meta['id']);
            if ($m) {
                $m->update(['recording_url' => $url, 'recording_status' => 'ready']);

                return;
            }
        }

        if ($meta && ($meta['kind'] ?? '') === 'livestream' && ! empty($meta['id'])) {
            $ls = Livestream::find((int) $meta['id']);
            if ($ls) {
                $ls->update(['recording_url' => $url]);

                return;
            }
        }

        if ($muxLiveStreamId) {
            SchoolMeeting::where('mux_live_stream_id', $muxLiveStreamId)->update([
                'recording_url'    => $url,
                'recording_status' => 'ready',
            ]);
            Livestream::where('mux_live_stream_id', $muxLiveStreamId)->update([
                'recording_url' => $url,
            ]);
        }
    }

    protected function verifyMuxSignature(string $payload, ?string $header, string $secret): bool
    {
        if (! $header) {
            return false;
        }
        // Mux-Signature: t=timestamp,v1=hash
        $parts = [];
        foreach (explode(',', $header) as $segment) {
            [$k, $v] = array_pad(explode('=', $segment, 2), 2, null);
            if ($k && $v) {
                $parts[$k] = $v;
            }
        }
        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';
        if ($timestamp === '' || $signature === '') {
            return false;
        }
        $signed = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        return hash_equals($signed, $signature);
    }
}
