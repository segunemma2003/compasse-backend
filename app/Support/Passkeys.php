<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;

/**
 * Fingerprint / face clock-in with a passkey (WebAuthn) on the person's own phone.
 *
 * The phone's fingerprint sensor unlocks a key kept on the phone; the school only stores its public
 * key and checks the phone's signature. One passkey belongs to one person, so this works on personal
 * phones — a phone shared by many people cannot tell whose finger it was.
 *
 * Browser data is exchanged as base64url strings.
 */
class Passkeys
{
    private const TTL = 300; // seconds a challenge stays valid

    public static function ready(): bool
    {
        return Schema::hasTable('passkeys');
    }

    /**
     * The site the passkey is bound to: the school's frontend domain (compasse.net, which covers
     * every school's subdomain), or localhost in development. Null when the page is not ours.
     */
    public static function rpIdFor(Request $request): ?string
    {
        $origin = (string) ($request->headers->get('Origin') ?: $request->headers->get('Referer'));
        $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
        $base = strtolower((string) config('services.webauthn.rp_id', 'compasse.net'));
        if ($host === '' ) {
            return null;
        }
        if ($host === 'localhost' || $host === '127.0.0.1') {
            return 'localhost';
        }
        if ($host === $base || str_ends_with($host, '.' . $base)) {
            return $base;
        }

        return null;
    }

    private static function server(string $rpId): WebAuthn
    {
        $schoolName = (string) (DB::table('schools')->value('name') ?: 'Compasse');

        return new WebAuthn(mb_substr($schoolName, 0, 60), $rpId, null, true);
    }

    private static function cacheKey(string $kind, string $id): string
    {
        return "webauthn:{$kind}:" . (function_exists('tenant') && tenant() ? tenant('id') : 'central') . ":{$id}";
    }

    /** Options for navigator.credentials.create() — set up fingerprint clock-in on this phone. */
    public static function registrationOptions(User $user, string $rpId): array
    {
        $server = self::server($rpId);
        $existing = DB::table('passkeys')->where('user_id', $user->id)->pluck('credential_id')
            ->map(fn ($id) => ByteBuffer::fromBase64Url($id))->all();
        $args = $server->getCreateArgs(
            userId: (function_exists('tenant') && tenant() ? tenant('id') : 'school') . ':' . $user->id,
            userName: (string) ($user->username ?? $user->email ?? $user->name),
            userDisplayName: (string) $user->name,
            timeout: 120,
            requireResidentKey: true,         // the clock page finds the person from the passkey alone
            requireUserVerification: true,    // fingerprint / face / phone PIN, not just a tap
            crossPlatformAttachment: false,   // this phone's own sensor
            excludeCredentialIds: $existing,
        );
        // Which phone model it is does not matter here.
        $args->publicKey->attestation = 'none';
        Cache::put(self::cacheKey('reg', (string) $user->id), $server->getChallenge()->getBinaryString(), self::TTL);

        return json_decode(json_encode($args), true);
    }

    /** Check the phone's answer and keep its public key. Returns the new passkey's id. */
    public static function register(User $user, string $rpId, string $clientDataJson, string $attestationObject, ?string $name): int
    {
        $challenge = Cache::pull(self::cacheKey('reg', (string) $user->id));
        if (! $challenge) {
            throw new \RuntimeException('That took too long — press "Set up" again.');
        }
        $data = self::server($rpId)->processCreate(
            ByteBuffer::fromBase64Url($clientDataJson)->getBinaryString(),
            ByteBuffer::fromBase64Url($attestationObject)->getBinaryString(),
            $challenge,
            requireUserVerification: true,
            requireUserPresent: true,
            failIfRootMismatch: false,
            requireCtsProfileMatch: false,
        );
        $credentialId = rtrim(strtr(base64_encode($data->credentialId), '+/', '-_'), '=');

        return (int) DB::table('passkeys')->insertGetId([
            'user_id' => $user->id, 'credential_id' => $credentialId, 'public_key' => $data->credentialPublicKey,
            'sign_count' => (int) ($data->signatureCounter ?? 0), 'name' => $name ? mb_substr($name, 0, 100) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Options for navigator.credentials.get() on the clock page (anyone with a passkey here). */
    public static function clockOptions(string $rpId): array
    {
        $server = self::server($rpId);
        $args = $server->getGetArgs(credentialIds: [], timeout: 60, allowUsb: false, allowNfc: false, allowBle: false,
            allowHybrid: false, allowInternal: true, requireUserVerification: true);
        $challengeId = Str::random(32);
        Cache::put(self::cacheKey('auth', $challengeId), $server->getChallenge()->getBinaryString(), self::TTL);

        return ['challenge_id' => $challengeId] + json_decode(json_encode($args), true);
    }

    /** The person whose passkey signed the clock page's challenge, or an exception saying why not. */
    public static function verifyClock(string $rpId, string $challengeId, string $credentialId, string $clientDataJson, string $authenticatorData, string $signature): User
    {
        $challenge = Cache::pull(self::cacheKey('auth', $challengeId));
        if (! $challenge) {
            throw new \RuntimeException('That took too long — try again.');
        }
        $key = DB::table('passkeys')->where('credential_id', $credentialId)->first();
        if (! $key) {
            throw new \RuntimeException('This phone\'s fingerprint is not set up for this school. Set it up on My Profile, or use your PIN.');
        }
        $server = self::server($rpId);
        $server->processGet(
            ByteBuffer::fromBase64Url($clientDataJson)->getBinaryString(),
            ByteBuffer::fromBase64Url($authenticatorData)->getBinaryString(),
            ByteBuffer::fromBase64Url($signature)->getBinaryString(),
            $key->public_key,
            $challenge,
            (int) $key->sign_count ?: null,
            requireUserVerification: true,
            requireUserPresent: true,
        );
        DB::table('passkeys')->where('id', $key->id)->update([
            'sign_count' => (int) ($server->getSignatureCounter() ?? $key->sign_count), 'last_used_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::find($key->user_id);
        if (! $user || $user->deleted_at || ($user->status ?? 'active') !== 'active') {
            throw new \RuntimeException('This account is not active — ask the school office.');
        }

        return $user;
    }
}
