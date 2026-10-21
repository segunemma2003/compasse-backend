<?php

namespace Tests\Feature;

use App\Services\GoogleMeetService;
use App\Support\GoogleConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Class / meeting links: pasted link, a real Google Meet from the connected account, else Jitsi. */
class GoogleMeetLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32)), 'services.google.client_id' => 'cid', 'services.google.client_secret' => 'secret']);
        $this->app->forgetInstance('encrypter');
        foreach (['schools' => fn ($t) => [$t->id(), $t->string('name')->nullable(), $t->timestamps()],
            'settings' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('category')->nullable(), $t->string('key'), $t->text('value')->nullable(), $t->timestamps()]] as $n => $d) {
            Schema::dropIfExists($n);
            Schema::create($n, $d);
        }
        DB::table('schools')->insert(['id' => 1, 'name' => 'Quiver']);
    }

    public function test_links(): void
    {
        $svc = new GoogleMeetService();
        $base = ['title' => 'Maths SS2', 'start_time' => now()->addDay(), 'duration_minutes' => 40];

        $this->assertSame('https://zoom.us/j/123', $svc->createMeeting($base + ['custom_link' => 'https://zoom.us/j/123'])['meeting_link']);

        $jitsi = $svc->createMeeting($base);
        $this->assertSame('jitsi', $jitsi['provider'], 'nothing connected');
        $this->assertStringStartsWith('https://meet.jit.si/Compasse-', $jitsi['meeting_link']);
        config(['services.jitsi.domain' => 'https://meet.compasse.net/']);
        $this->assertStringStartsWith('https://meet.compasse.net/Compasse-', $svc->createMeeting($base)['meeting_link'], 'self-hosted Jitsi');
        config(['services.jitsi.domain' => 'meet.jit.si']);

        // School connected, access token expired → refreshed, then the Calendar event gives a real Meet.
        GoogleConnection::save(null, ['access_token' => 'old', 'refresh_token' => 'r1', 'expires_at' => time() - 10, 'email' => 'classes@quiver.sch.ng']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'new', 'expires_in' => 3600]),
            'www.googleapis.com/calendar/*' => Http::response(['id' => 'ev1', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij', 'conferenceData' => ['conferenceId' => 'abc-defg-hij']]),
        ]);
        $g = $svc->createMeeting($base + ['host_user_id' => 7, 'attendees' => ['t@x.test']]);
        $this->assertSame('google', $g['provider']);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $g['meeting_link']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'conferenceDataVersion=1') && $r->hasHeader('Authorization', 'Bearer new')
            && $r['conferenceData']['createRequest']['conferenceSolutionKey']['type'] === 'hangoutsMeet' && $r['attendees'][0]['email'] === 't@x.test');
        $this->assertSame('new', GoogleConnection::get(null)['access_token'], 'refreshed token saved');
        $this->assertStringNotContainsString('r1', (string) DB::table('settings')->value('value'), 'stored encrypted');

        // A teacher's own account wins over the school's.
        GoogleConnection::save(7, ['access_token' => 'mine', 'refresh_token' => 'r2', 'expires_at' => time() + 3000]);
        $svc->createMeeting($base + ['host_user_id' => 7]);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer mine'));
    }

    public function test_google_failure_falls_back_to_jitsi(): void
    {
        GoogleConnection::save(null, ['access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => time() + 3000]);
        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['message' => 'Calendar API has not been used']], 403)]);
        $this->assertSame('jitsi', (new GoogleMeetService())->createMeeting(['title' => 'x', 'start_time' => now(), 'duration_minutes' => 30])['provider']);
    }
}
