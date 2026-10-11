<?php

namespace Tests\Feature;

use App\Http\Controllers\UserController;
use App\Jobs\SendEmailJob;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Select people (e.g. teachers after a bulk upload) and email each a fresh
 * password in one go.
 */
class BulkSendCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('users');
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('tenant_id')->nullable();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role')->default('teacher');
            $t->timestamps();
            $t->softDeletes();
        });

        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        School::create(['tenant_id' => 'test-tenant', 'name' => 'The Quiver School']);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'role' => 'school_admin']);
        $this->actingAs($this->admin);
    }

    public function test_each_selected_person_gets_their_own_new_password_by_email(): void
    {
        Queue::fake();
        $a = User::create(['name' => 'Ruth', 'email' => 'ruth@example.test', 'password' => 'Teacher@123']);
        $b = User::create(['name' => 'Ada',  'email' => 'ada@example.test',  'password' => 'Teacher@123']);
        $placeholder = User::create(['name' => 'No Email', 'email' => 'no.email1@quiver.compasse.net', 'password' => 'Teacher@123']);

        $request = Request::create('/', 'POST', ['user_ids' => [$a->id, $b->id, $placeholder->id]]);
        $request->setUserResolver(fn () => $this->admin);

        $data = (new UserController())->bulkSendCredentials($request)->getData(true);

        $this->assertSame(2, $data['sent']);
        $this->assertSame(['No Email'], $data['skipped'], 'made-up addresses have no inbox, so they are skipped');
        Queue::assertPushed(SendEmailJob::class, 2);

        $this->assertFalse(Hash::check('Teacher@123', $a->fresh()->password), 'the shared default password is replaced');
        $this->assertTrue(Hash::check('Teacher@123', $placeholder->fresh()->password), 'skipped accounts are left alone');

        Queue::assertPushed(SendEmailJob::class, fn ($job) => $job->to === 'ruth@example.test'
            && preg_match('/Password: ([A-Za-z0-9]{10})/', $job->body, $m)
            && Hash::check($m[1], $a->fresh()->password));
    }
}
