<?php

namespace App\Support;

use App\Models\School;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A school's (or a teacher's own) Google account, connected once with OAuth, used to create real
 * Google Meet links through Google Calendar. Compasse has one Google OAuth app (GOOGLE_CLIENT_ID /
 * GOOGLE_CLIENT_SECRET); each school connects its own account — usually the school's Google
 * Workspace account — and every class / meeting is created on it. A teacher may connect their own
 * account so they host their own classes. Refresh tokens are stored encrypted, never sent out.
 */
class GoogleConnection
{
    public const SCOPES = ['openid', 'email', 'https://www.googleapis.com/auth/calendar.events'];

    public static function configured(): bool
    {
        return (bool) (config('services.google.client_id') && config('services.google.client_secret'));
    }

    public static function redirectUri(): string
    {
        return config('services.google.redirect') ?: rtrim(config('app.url'), '/') . '/api/v1/integrations/google/callback';
    }

    private static function key(?int $userId): string
    {
        return $userId ? "google_oauth_user_{$userId}" : 'google_oauth_school';
    }

    /** Stored token for a user (their own account) or the school; null if not connected. */
    public static function get(?int $userId = null): ?array
    {
        $raw = DB::table('settings')->where('school_id', School::first()?->id)->where('key', self::key($userId))->value('value');
        if (! $raw) {
            return null;
        }
        try {
            return json_decode(Crypt::decryptString($raw), true) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function save(?int $userId, array $token): void
    {
        DB::table('settings')->updateOrInsert(
            ['school_id' => School::first()?->id, 'key' => self::key($userId)],
            ['value' => Crypt::encryptString(json_encode($token)), 'category' => 'integrations', 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public static function forget(?int $userId): void
    {
        $t = self::get($userId);
        if ($t && ! empty($t['refresh_token'])) {
            Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => $t['refresh_token']]);
        }
        DB::table('settings')->where('school_id', School::first()?->id)->where('key', self::key($userId))->delete();
    }

    /** The account that will host: the teacher's own if connected, else the school's. */
    public static function forHost(?int $userId): ?array
    {
        if ($userId && ($t = self::get($userId))) {
            return $t + ['owner' => $userId];
        }
        $t = self::get(null);

        return $t ? $t + ['owner' => null] : null;
    }

    /** Exchange the OAuth code for tokens and the account's email. */
    public static function exchange(string $code): array
    {
        $res = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'code' => $code, 'client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => self::redirectUri(), 'grant_type' => 'authorization_code',
        ]);
        if (! $res->successful()) {
            throw new \RuntimeException('Google did not accept the sign-in: ' . ($res->json('error_description') ?? $res->status()));
        }
        $t = $res->json();
        $email = null;
        if (! empty($t['id_token'])) {
            $parts = explode('.', $t['id_token']);
            $email = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true)['email'] ?? null;
        }

        return [
            'access_token' => $t['access_token'], 'refresh_token' => $t['refresh_token'] ?? null,
            'expires_at' => time() + (int) ($t['expires_in'] ?? 3600) - 60, 'email' => $email, 'connected_at' => now()->toIso8601String(),
        ];
    }

    /** A valid access token (refreshing it when it has expired). */
    public static function accessToken(array &$token): string
    {
        if (! empty($token['access_token']) && ($token['expires_at'] ?? 0) > time()) {
            return $token['access_token'];
        }
        if (empty($token['refresh_token'])) {
            throw new \RuntimeException('The Google connection has expired — reconnect Google in Settings → Integrations.');
        }
        $res = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $token['refresh_token'], 'grant_type' => 'refresh_token',
        ]);
        if (! $res->successful()) {
            throw new \RuntimeException('Google refused the saved connection (' . ($res->json('error') ?? $res->status()) . ') — reconnect Google in Settings → Integrations.');
        }
        $token['access_token'] = $res->json('access_token');
        $token['expires_at'] = time() + (int) $res->json('expires_in', 3600) - 60;
        self::save($token['owner'] ?? null, array_diff_key($token, ['owner' => 1]));

        return $token['access_token'];
    }

    /**
     * A Calendar event with a Google Meet conference. Returns [meet link, conference id, event id].
     *
     * @param  list<string>  $attendees  emails invited (they get it in their calendar)
     */
    public static function createMeet(array $token, string $title, \DateTimeInterface $start, int $minutes, ?string $description = null, array $attendees = []): array
    {
        $access = self::accessToken($token);
        $end = (clone \Carbon\Carbon::instance($start))->addMinutes(max(15, $minutes));
        $res = Http::withToken($access)->timeout(25)->post('https://www.googleapis.com/calendar/v3/calendars/primary/events?conferenceDataVersion=1&sendUpdates=' . ($attendees ? 'all' : 'none'), [
            'summary' => $title,
            'description' => $description,
            'start' => ['dateTime' => \Carbon\Carbon::instance($start)->toRfc3339String()],
            'end' => ['dateTime' => $end->toRfc3339String()],
            'attendees' => array_map(fn ($e) => ['email' => $e], array_values(array_unique(array_filter($attendees)))),
            'conferenceData' => ['createRequest' => ['requestId' => bin2hex(random_bytes(10)), 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]],
            'guestsCanModify' => false,
        ]);
        if (! $res->successful()) {
            Log::warning('Google Meet create failed', ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 500)]);
            throw new \RuntimeException('Google could not create the Meet (' . ($res->json('error.message') ?? $res->status()) . ').');
        }
        $link = $res->json('hangoutLink') ?: collect($res->json('conferenceData.entryPoints', []))->firstWhere('entryPointType', 'video')['uri'] ?? null;
        if (! $link) {
            throw new \RuntimeException('Google created the event but no Meet link — Google Meet may be switched off for this account.');
        }

        return [$link, $res->json('conferenceData.conferenceId'), $res->json('id')];
    }
}
