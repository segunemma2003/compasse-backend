<?php

namespace Tests\Unit;

use App\Models\School;
use App\Services\PayHubService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The parent pays PayHub's service charge on top, so the school is credited the fee in full. */
class PayHubFeeQuoteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The school's PayHub key is stored encrypted.
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }

    private function school(): School
    {
        $s = new School(['name' => 'Quiver']);
        $s->payhub_company_id = 'co_1';
        $s->payhub_api_key = 'sk_live_test';

        return $s;
    }

    public function test_quote_adds_the_service_charge(): void
    {
        config(['services.payhub.base_url' => 'https://payhub.test']);
        Http::fake(['payhub.test/api/v1/charges/fee-quote*' => Http::response(['amount' => '100000.00', 'fee' => '1010.10', 'total' => '101010.10'])]);
        $q = app(PayHubService::class)->quoteFee($this->school(), 100000);
        $this->assertSame(['amount' => 100000.0, 'service_charge' => 1010.1, 'total' => 101010.1], $q);
    }

    public function test_no_quote_means_no_extra_charge(): void
    {
        config(['services.payhub.base_url' => 'https://payhub.test']);
        Http::fake(['payhub.test/*' => Http::response(['detail' => 'down'], 500)]);
        $this->assertSame(0.0, app(PayHubService::class)->quoteFee($this->school(), 50000)['service_charge']);
    }
}
