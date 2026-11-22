<?php

namespace Tests\Feature;

use App\Http\Controllers\StaffAttendanceController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fingerprint clock-in: a passkey set up on the person's own phone, then used on the clock page
 * after scanning the school's QR code. The "phone" here is a P-256 key signing like a real one.
 */
class PasskeyClockTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://quiver.compasse.net';

    private $phoneKey;
    private string $credentialId;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'users' => fn ($t) => [$t->id(), $t->string('name'), $t->string('email'), $t->string('password'), $t->string('role'), $t->string('phone')->nullable(), $t->string('status')->nullable(), $t->string('attendance_shift', 40)->nullable(), $t->string('clock_pin_hash', 64)->nullable(), $t->text('clock_pin')->nullable(), $t->timestamps(), $t->softDeletes()],
            'schools' => fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()],
            'settings' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('key'), $t->text('value')->nullable(), $t->timestamps()],
            'teachers' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id'), $t->unsignedBigInteger('department_id')->nullable(), $t->string('employee_id')->nullable(), $t->string('phone')->nullable(), $t->timestamps(), $t->softDeletes()],
            'staff' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id'), $t->string('department')->nullable(), $t->string('employee_id')->nullable(), $t->string('phone')->nullable(), $t->timestamps(), $t->softDeletes()],
            'staff_duties' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id'), $t->date('week_start'), $t->timestamps()],
            'attendances' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('attendanceable_type'), $t->unsignedBigInteger('attendanceable_id'), $t->date('date'), $t->string('status')->default('present'), $t->time('check_in_time')->nullable(), $t->time('check_out_time')->nullable(), $t->text('notes')->nullable(), $t->unsignedBigInteger('marked_by')->nullable(), $t->decimal('total_hours', 5, 2)->nullable(), $t->string('location')->nullable(), $t->string('device_info')->nullable(), $t->string('ip_address')->nullable(), $t->boolean('is_late')->default(false), $t->integer('late_minutes')->nullable(), $t->boolean('is_absent')->default(false), $t->timestamps()],
        ] as $n => $d) {
            Schema::dropIfExists($n);
            Schema::create($n, $d);
        }
        (require database_path('migrations/tenant/2026_11_22_100000_create_passkeys_table.php'))->up();
        DB::table('schools')->insert(['id' => 1, 'name' => 'Quiver']);
        User::forceCreate(['id' => 1, 'name' => 'Admin', 'email' => 'a@x.test', 'password' => 'x', 'role' => 'school_admin']);
        User::forceCreate(['id' => 2, 'name' => 'Grace Teacher', 'email' => 't@x.test', 'password' => 'x', 'role' => 'teacher']);
        DB::table('teachers')->insert(['id' => 9, 'user_id' => 2]);
        Queue::fake();
        $this->phoneKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->credentialId = random_bytes(32);
    }

    private function req(?int $uid, array $data, string $origin = self::ORIGIN): Request
    {
        $r = Request::create('/', 'POST', $data);
        $r->headers->set('Origin', $origin);
        if ($uid) {
            $u = User::find($uid);
            $this->actingAs($u);
            $r->setUserResolver(fn () => $u);
        }
        app()->instance('request', $r);

        return $r;
    }

    private static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function unb64u(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    /** Minimal CBOR encoder for the shapes WebAuthn uses. */
    private static function cbor(mixed $v): string
    {
        $head = function (int $major, int $len): string {
            if ($len < 24) return chr(($major << 5) | $len);
            if ($len < 256) return chr(($major << 5) | 24) . chr($len);
            return chr(($major << 5) | 25) . pack('n', $len);
        };
        if (is_int($v)) return $v >= 0 ? $head(0, $v) : $head(1, -1 - $v);
        if (is_array($v) && isset($v['__bytes'])) return $head(2, strlen($v['__bytes'])) . $v['__bytes'];
        if (is_string($v)) return $head(3, strlen($v)) . $v;
        $out = $head(5, count($v));
        foreach ($v as $k => $x) $out .= self::cbor($k) . self::cbor($x);
        return $out;
    }

    /** What the phone sends back when the passkey is created. */
    private function phoneCreate(array $options, string $rpId = 'compasse.net'): array
    {
        $d = openssl_pkey_get_details($this->phoneKey)['ec'];
        $cose = self::cbor([1 => 2, 3 => -7, -1 => 1, -2 => ['__bytes' => str_pad($d['x'], 32, "\0", STR_PAD_LEFT)], -3 => ['__bytes' => str_pad($d['y'], 32, "\0", STR_PAD_LEFT)]]);
        $authData = hash('sha256', $rpId, true) . chr(0x45) . pack('N', 0) . str_repeat("\0", 16)
            . pack('n', strlen($this->credentialId)) . $this->credentialId . $cose;
        $att = self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => ['__bytes' => $authData]]);
        $client = json_encode(['type' => 'webauthn.create', 'challenge' => $options['publicKey']['challenge'], 'origin' => self::ORIGIN]);

        return ['client_data_json' => self::b64u($client), 'attestation_object' => self::b64u($att), 'name' => 'Test phone'];
    }

    /** What the phone sends back on the clock page after the fingerprint. */
    private function phoneSign(array $options, int $counter = 0, bool $verified = true): array
    {
        $authData = hash('sha256', 'compasse.net', true) . chr($verified ? 0x05 : 0x01) . pack('N', $counter);
        $client = json_encode(['type' => 'webauthn.get', 'challenge' => $options['publicKey']['challenge'], 'origin' => self::ORIGIN]);
        openssl_sign($authData . hash('sha256', $client, true), $sig, $this->phoneKey, OPENSSL_ALGO_SHA256);

        return ['id' => self::b64u($this->credentialId), 'challenge_id' => $options['challenge_id'], 'client_data_json' => self::b64u($client),
            'authenticator_data' => self::b64u($authData), 'signature' => self::b64u($sig)];
    }

    public function test_set_up_on_my_phone_then_clock_in_with_my_fingerprint(): void
    {
        $c = app(StaffAttendanceController::class);
        $opts = $c->passkeyRegisterOptions($this->req(2, []))->getData(true);
        $this->assertSame('compasse.net', $opts['publicKey']['rp']['id']);
        $this->assertSame('required', $opts['publicKey']['authenticatorSelection']['userVerification'], 'fingerprint/face, not just a tap');
        $this->assertSame('none', $opts['publicKey']['attestation']);

        $res = $c->passkeyRegister($this->req(2, $this->phoneCreate($opts)));
        $this->assertSame(201, $res->getStatusCode(), $res->getContent());
        $this->assertSame('Test phone', $res->getData(true)['passkeys'][0]['name']);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', DB::table('passkeys')->value('public_key'), 'only the public key is kept');

        // The clock page, after scanning the school's QR code.
        $key = $c->qr($this->req(1, []))->getData(true)['key'];
        $o = $c->passkeyClockOptions($this->req(null, ['key' => $key]))->getData(true);
        $this->assertSame([], $o['publicKey']['allowCredentials'] ?? [], 'the phone offers whichever passkey it has');
        $res = $c->passkeyClock($this->req(null, ['key' => $key] + $this->phoneSign($o)));
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $this->assertSame('in', $res->getData(true)['action']);
        $this->assertSame('Grace Teacher', $res->getData(true)['name']);
        $this->assertSame(1, DB::table('attendances')->where('attendanceable_id', 9)->count());
        $this->assertNotNull(DB::table('passkeys')->value('last_used_at'));

        // The same challenge cannot be used twice.
        $this->assertSame(422, $c->passkeyClock($this->req(null, ['key' => $key] + $this->phoneSign($o)))->getStatusCode());
    }

    public function test_a_wrong_signature_another_site_or_no_fingerprint_is_refused(): void
    {
        $c = app(StaffAttendanceController::class);
        $c->passkeyRegister($this->req(2, $this->phoneCreate($c->passkeyRegisterOptions($this->req(2, []))->getData(true))));
        $key = $c->qr($this->req(1, []))->getData(true)['key'];

        // Signed by a different key (someone else's phone pretending).
        $o = $c->passkeyClockOptions($this->req(null, ['key' => $key]))->getData(true);
        $real = $this->phoneKey;
        $this->phoneKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $res = $c->passkeyClock($this->req(null, ['key' => $key] + $this->phoneSign($o)));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('not accepted', $res->getData(true)['error']);
        $this->phoneKey = $real;

        // The phone only tapped, no fingerprint / face.
        $o = $c->passkeyClockOptions($this->req(null, ['key' => $key]))->getData(true);
        $this->assertSame(422, $c->passkeyClock($this->req(null, ['key' => $key] + $this->phoneSign($o, 0, false)))->getStatusCode());

        // A page on another website, or an old QR code.
        $this->assertSame(422, $c->passkeyClockOptions($this->req(null, ['key' => $key], 'https://compasse.net.evil.test'))->getStatusCode());
        $this->assertSame(422, $c->passkeyClockOptions($this->req(null, ['key' => 'old']))->getStatusCode());
        $this->assertSame(0, DB::table('attendances')->count(), 'nobody was clocked in');
    }

    public function test_remove_a_lost_phone(): void
    {
        $c = app(StaffAttendanceController::class);
        $c->passkeyRegister($this->req(2, $this->phoneCreate($c->passkeyRegisterOptions($this->req(2, []))->getData(true))));
        $pins = $c->pins($this->req(1, []))->getData(true)['people'];
        $this->assertSame(1, collect($pins)->firstWhere('id', 2)['fingerprints']);

        $this->assertSame(403, $c->passkeyRemoveFor($this->req(2, []), 2)->getStatusCode(), 'only the office removes other people\'s');
        $this->assertSame(1, $c->passkeyRemoveFor($this->req(1, []), 2)->getData(true)['removed']);

        $c->passkeyRegister($this->req(2, $this->phoneCreate($c->passkeyRegisterOptions($this->req(2, []))->getData(true))));
        $id = DB::table('passkeys')->value('id');
        $this->assertSame([], $c->passkeyDestroy($this->req(2, []), $id)->getData(true)['passkeys']);
    }
}
