<?php

namespace Tests\Unit;

use App\Jobs\SendEmailJob;
use App\Support\EmailAddress;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailAddressDeliverableTest extends TestCase
{
    public function test_mistyped_and_test_addresses_are_not_deliverable(): void
    {
        foreach (['Nwachukwujoy080.gmail.com', 'edehglory679@gmail', 'Collins@gmail. com', 'PETER.PAUL@ROLEXSCHOOLS.ORG)',
            'CHINONYE.OGWO@, ROLEX SCHOOLS. ORG', 'CHIEMERIE.OBINEME', 'john.doe@example.com', '', null] as $bad) {
            $this->assertFalse(EmailAddress::isDeliverable($bad), var_export($bad, true));
        }
        foreach (['ada@gmail.com', 'PETER.PAUL@ROLEXSCHOOLS.ORG', 'head@school.edu.ng'] as $good) {
            $this->assertTrue(EmailAddress::isDeliverable($good), $good);
        }
    }

    public function test_job_skips_an_undeliverable_address_without_throwing(): void
    {
        Mail::shouldReceive('getFacadeRoot')->never();
        Mail::shouldReceive('mailer')->never();

        (new SendEmailJob('Nwachukwujoy080.gmail.com', 'Your Teacher Login Credentials', 'hi'))->handle();

        $this->addToAssertionCount(1);
    }
}
