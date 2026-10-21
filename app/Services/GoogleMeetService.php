<?php

namespace App\Services;

use App\Support\GoogleConnection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Video-room links for classes and meetings, best first:
 *  1. a link the teacher pasted (their own Meet / Zoom / Teams);
 *  2. a real Google Meet created on the host's or the school's connected Google account;
 *  3. otherwise a Jitsi Meet room (free, no account needed to join).
 * (A made-up meet.google.com code does not work — Google only accepts codes it issued.)
 */
class GoogleMeetService
{
    public function createMeeting(array $data): array
    {
        $startTime = $data['start_time'] instanceof \Carbon\Carbon ? $data['start_time'] : \Carbon\Carbon::parse($data['start_time']);
        $base = ['meeting_password' => null, 'created_at' => now(), 'expires_at' => $startTime->copy()->addHours(24)];

        if (! empty($data['custom_link']) && filter_var($data['custom_link'], FILTER_VALIDATE_URL)) {
            return $base + ['provider' => 'custom', 'meeting_id' => null, 'meeting_link' => $data['custom_link'], 'join_url' => $data['custom_link']];
        }

        if (GoogleConnection::configured() && ($token = GoogleConnection::forHost($data['host_user_id'] ?? null))) {
            try {
                [$link, $conferenceId] = GoogleConnection::createMeet($token, (string) $data['title'], $startTime, (int) ($data['duration_minutes'] ?? 60), $data['description'] ?? null, $data['attendees'] ?? []);

                return $base + ['provider' => 'google', 'meeting_id' => $conferenceId, 'meeting_link' => $link, 'join_url' => $link];
            } catch (\Throwable $e) {
                Log::warning('Falling back to Jitsi: ' . $e->getMessage());
            }
        }

        $sub = Config::get('tenant.subdomain');
        $room = 'Compasse-' . preg_replace('/[^A-Za-z0-9]/', '', ucfirst(is_string($sub) && $sub !== '' ? $sub : 'school')) . '-' . bin2hex(random_bytes(6));
        $link = "https://meet.jit.si/{$room}";

        return $base + ['provider' => 'jitsi', 'meeting_id' => $room, 'meeting_link' => $link, 'join_url' => $link];
    }

    public function getMeetingDetails(string $meetingId): array
    {
        return ['meeting_id' => $meetingId, 'status' => 'active', 'participants' => 0, 'duration' => 0];
    }

    public function endMeeting(string $meetingId): bool
    {
        return true;
    }

    public function getMeetingParticipants(string $meetingId): array
    {
        return ['meeting_id' => $meetingId, 'participants' => [], 'total_participants' => 0];
    }

    public function sendInvitation(string $meetingId, array $recipients, ?string $message = null): bool
    {
        return true;
    }

    /** Placeholder until a recording URL is uploaded manually or via Mux. */
    public function getRecordingUrl(?string $meetingId): ?string
    {
        return null;
    }

    public function isMeetingActive(string $meetingId): bool
    {
        return true;
    }
}
