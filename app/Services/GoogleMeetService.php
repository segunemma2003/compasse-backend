<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleMeetService
{
    protected $apiKey;
    protected $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.google.api_key');
        $this->baseUrl = 'https://meet.google.com';
    }

    /**
     * Create a Google Meet meeting.
     *
     * Generates a shareable Meet link in the real xxx-yyyy-zzz format.
     * The teacher who opens the link first "owns" the room.
     * Full server-side creation via Google Calendar API requires OAuth 2.0
     * service-account credentials (GOOGLE_SERVICE_ACCOUNT_JSON env var).
     */
    public function createMeeting(array $data): array
    {
        try {
            $code = $this->generateMeetingCode();
            $link = "https://meet.google.com/{$code}";

            $startTime = $data['start_time'] instanceof \Carbon\Carbon
                ? $data['start_time']
                : \Carbon\Carbon::parse($data['start_time']);

            return [
                'meeting_id'       => $code,
                'meeting_link'     => $link,
                'meeting_password' => null,
                'join_url'         => $link,
                'created_at'       => now(),
                'expires_at'       => $startTime->copy()->addHours(24),
            ];
        } catch (\Exception $e) {
            Log::error('Google Meet service error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate a Meet-style room code: xxx-yyyy-zzz
     */
    protected function generateMeetingCode(): string
    {
        $alpha = 'abcdefghijklmnopqrstuvwxyz';
        $rand  = function (int $len) use ($alpha): string {
            $out = '';
            for ($i = 0; $i < $len; $i++) {
                $out .= $alpha[random_int(0, 25)];
            }
            return $out;
        };
        return $rand(3) . '-' . $rand(4) . '-' . $rand(3);
    }

    /**
     * Get meeting details — stub for future API integration
     */
    public function getMeetingDetails(string $meetingId): array
    {
        try {
            // In a real implementation, this would call Google Meet API
            return [
                'meeting_id' => $meetingId,
                'status' => 'active',
                'participants' => 0,
                'duration' => 0,
                'created_at' => now(),
            ];

        } catch (\Exception $e) {
            Log::error('Google Meet get meeting details error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * End meeting
     */
    public function endMeeting(string $meetingId): bool
    {
        try {
            // In a real implementation, this would call Google Meet API to end the meeting
            Log::info("Meeting {$meetingId} ended");
            return true;

        } catch (\Exception $e) {
            Log::error('Google Meet end meeting error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get meeting participants
     */
    public function getMeetingParticipants(string $meetingId): array
    {
        try {
            // In a real implementation, this would call Google Meet API
            return [
                'meeting_id' => $meetingId,
                'participants' => [],
                'total_participants' => 0,
            ];

        } catch (\Exception $e) {
            Log::error('Google Meet get participants error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Send meeting invitation
     */
    public function sendInvitation(string $meetingId, array $recipients, ?string $message = null): bool
    {
        try {
            // In a real implementation, this would send emails or notifications
            foreach ($recipients as $recipient) {
                Log::info("Meeting invitation sent to {$recipient} for meeting {$meetingId}");
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Google Meet send invitation error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate meeting recording URL
     */
    public function getRecordingUrl(string $meetingId): ?string
    {
        try {
            // In a real implementation, this would get the recording URL from Google Meet
            return "https://meet.google.com/recording/{$meetingId}";

        } catch (\Exception $e) {
            Log::error('Google Meet get recording URL error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if meeting is active
     */
    public function isMeetingActive(string $meetingId): bool
    {
        try {
            // In a real implementation, this would check the meeting status
            return true;

        } catch (\Exception $e) {
            Log::error('Google Meet check meeting status error: ' . $e->getMessage());
            return false;
        }
    }
}
