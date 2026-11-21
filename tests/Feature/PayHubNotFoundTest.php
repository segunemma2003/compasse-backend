<?php

namespace Tests\Feature;

use App\Models\School;
use App\Services\PayHubService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A payment the provider has no transaction for is "not found", not an outage to retry forever. */
class PayHubNotFoundTest extends TestCase
{
    public function test_provider_with_no_transaction_counts_as_not_found(): void
    {
        $school = new School(['name' => 'Q']);
        $school->id = 1;
        $school->payhub_api_key = 'k';
        $svc = app(PayHubService::class);

        Http::fake(['*' => Http::sequence()
            ->push(['detail' => 'No transaction was found for this id'], 502)
            ->push(['detail' => 'Charge not found.'], 404)
            ->push(['detail' => 'Paystack timed out'], 502)]);

        $this->assertSame('not_found', $svc->verifyCharge($school, 'phb_a')['status']);
        $this->assertSame('not_found', $svc->verifyCharge($school, 'phb_b')['status']);
        $this->expectException(\RuntimeException::class);
        $svc->verifyCharge($school, 'phb_c');
    }
}
