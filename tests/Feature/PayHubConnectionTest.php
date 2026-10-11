<?php

namespace Tests\Feature;

use App\Http\Controllers\PayHubAccountController;
use App\Models\School;
use App\Models\User;
use App\Services\PayHubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A school creates its PayHub account and opens it with one click from the
 * admin/finance dashboards; fee charges carry a reason. PayHub is faked.
 */
class PayHubConnectionTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            // The school's PayHub API key is stored encrypted.
            'app.key'                        => 'base64:' . base64_encode(str_repeat('k', 32)),
            'services.payhub.base_url'       => 'http://payhub.internal',
            'services.payhub.public_url'     => 'https://payhub.example.com',
            'services.payhub.admin_email'    => 'admin@payhub.example.com',
            'services.payhub.admin_password' => 'secret',
        ]);

        if (! Schema::hasColumn('schools', 'payhub_company_id')) {
            Schema::table('schools', function ($t) {
                $t->string('payhub_company_id')->nullable();
                $t->text('payhub_api_key')->nullable();
            });
        }

        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->school = School::create(['tenant_id' => 'test-tenant', 'name' => 'Springfield', 'email' => 'owner@example.com']);
        $this->admin  = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'role' => 'school_admin']);
        $this->actingAs($this->admin);
    }

    private function request(): Request
    {
        $r = Request::create('/', 'POST');
        $r->attributes->set('school', $this->school);
        $r->setUserResolver(fn () => $this->admin);

        return $r;
    }

    public function test_connect_creates_the_account_and_retries_with_another_email_when_taken(): void
    {
        Http::fake([
            'payhub.internal/api/v1/auth/admin/login' => Http::response(['access_token' => 'adm']),
            'payhub.internal/api/v1/companies' => Http::sequence()
                ->push(['detail' => 'A company with this email already exists.'], 409)
                ->push(['id' => 'co_123'], 201),
            'payhub.internal/api/v1/companies/co_123/api-keys' => Http::response(['key' => 'sk_test_abc'], 201),
        ]);

        $res = (new PayHubAccountController())->connect($this->request(), app(PayHubService::class));

        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $this->assertSame('co_123', $this->school->fresh()->payhub_company_id);

        $creates = collect(Http::recorded())->filter(fn ($p) => $p[0]->url() === 'http://payhub.internal/api/v1/companies')->values();
        $this->assertSame('owner@example.com', $creates[0][0]['email']);
        $this->assertStringEndsWith('@schools.compasse.net', $creates[1][0]['email'], 'the retry uses a unique stand-in address');
    }

    public function test_open_payhub_returns_a_public_one_time_link(): void
    {
        $this->school->update(['payhub_company_id' => 'co_123', 'payhub_api_key' => 'sk_test_abc']);
        Http::fake([
            'payhub.internal/api/v1/companies/me/login-link' => Http::response(['path' => '/dashboard/sso?token=t0k', 'url' => 'http://payhub.internal/dashboard/sso?token=t0k']),
        ]);

        $res = (new PayHubAccountController())->loginLink($this->request(), app(PayHubService::class));

        $this->assertSame('https://payhub.example.com/dashboard/sso?token=t0k', $res->getData(true)['url'],
            'built on the public address, never the internal one');
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer sk_test_abc'));
    }

    public function test_status_reports_whether_the_school_is_connected(): void
    {
        $data = (new PayHubAccountController())->show($this->request(), app(PayHubService::class))->getData(true);
        $this->assertTrue($data['enabled']);
        $this->assertFalse($data['connected']);
    }

    public function test_charges_send_the_payment_reason(): void
    {
        $this->school->update(['payhub_company_id' => 'co_123', 'payhub_api_key' => 'sk_test_abc']);
        Http::fake(['payhub.internal/api/v1/charges' => Http::response([
            'reference' => 'phb_1', 'checkout_url' => 'https://checkout', 'status' => 'pending', 'provider' => 'paystack',
        ], 201)]);

        app(PayHubService::class)->initializeCharge($this->school->fresh(), 5000, 'parent@example.com', 'https://school/return',
            description: 'School fees – Tuition – Ruth Okegbuna (THE20250001) – First Term 2026/2027');

        Http::assertSent(fn (HttpRequest $r) => $r['description'] === 'School fees – Tuition – Ruth Okegbuna (THE20250001) – First Term 2026/2027');
    }
}
