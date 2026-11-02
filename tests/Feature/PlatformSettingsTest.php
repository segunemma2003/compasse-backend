<?php

namespace Tests\Feature;

use App\Http\Controllers\PlatformSettingsController;
use App\Support\PlatformSettings;
use App\Support\SchoolIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_keys_are_encrypted_masked_and_used_as_the_sms_fallback(): void
    {
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        app()->forgetInstance('encrypter');
        \Illuminate\Support\Facades\Crypt::clearResolvedInstances();
        (require database_path('migrations/2026_11_02_000001_create_platform_settings_table.php'))->up();
        $c = app(PlatformSettingsController::class);

        $c->update(Request::create('/', 'PUT', ['aloc_token' => 'ALOC-secret-1234', 'sms_provider' => 'smslive247', 'sms_api_key' => 'key-9876', 'sms_sender_id' => 'Compasse']));
        $raw = DB::table('platform_settings')->where('key', 'aloc_token')->value('value');
        $this->assertStringNotContainsString('ALOC-secret', (string) $raw, 'stored encrypted');

        $shown = $c->show()->getData(true)['settings'];
        $this->assertNull($shown['aloc_token']);
        $this->assertSame('••••1234', $shown['aloc_token_hint']);

        // Saving with the secret left empty keeps it.
        $c->update(Request::create('/', 'PUT', ['sms_sender_id' => 'Quiver', 'aloc_token' => '']));
        PlatformSettings::flush();
        $this->assertSame('ALOC-secret-1234', PlatformSettings::get('aloc_token'));
        $this->assertSame('ALOC-secret-1234', \App\Support\AlocQuestions::token());

        $s = SchoolIntegrationSettings::forSchool(null);
        $this->assertTrue($s->hasSms());
        $this->assertSame('smslive247', $s->smsProvider);
        $this->assertSame('Quiver', $s->smsSenderId);
    }
}
