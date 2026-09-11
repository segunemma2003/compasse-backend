<?php

namespace Tests\Feature;

use App\Http\Controllers\HostelRoomController;
use App\Http\Controllers\SecurityController;
use App\Models\School;
use App\Models\User;
use App\Support\RoleCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 2 of the sub-admin capability gating: Hostel, Inventory, Health,
 * Transport, Library, Staff, Security controllers now carry the same
 * requireCapability() guards Finance/Users/Academic got in phase 1. This
 * spot-checks two of the seven (Hostel, Security) end to end — the same
 * mechanical pattern already proven for Finance in
 * SubAdminCapabilityGatingTest, so this exists to catch a wrong capability
 * slug or a missed guard, not to re-prove the mechanism itself.
 */
class SubAdminCapabilityGatingPhase2Test extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenant.subdomain' => 'test-tenant']);

        Schema::dropIfExists('users');
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('tenant_id')->nullable();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role')->default('student');
            $t->string('status')->default('active');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('settings', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->string('type')->default('string');
            $t->string('category')->nullable();
            $t->timestamps();
        });

        Schema::create('hostel_rooms', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->string('room_number');
            $t->string('block')->nullable();
            $t->string('floor')->nullable();
            $t->string('type')->default('double');
            $t->unsignedInteger('capacity')->default(2);
            $t->unsignedInteger('occupied_count')->default(0);
            $t->decimal('price_per_term', 10, 2)->default(0);
            $t->json('amenities')->nullable();
            $t->string('status')->default('available');
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('security_incidents', function ($t) {
            $t->id();
            $t->string('type')->default('other');
            $t->string('title');
            $t->text('description');
            $t->string('location')->nullable();
            $t->string('severity')->default('low');
            $t->string('status')->default('open');
            $t->timestamp('reported_time')->nullable();
            $t->timestamp('resolved_time')->nullable();
            $t->unsignedBigInteger('reported_by')->nullable();
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->text('resolution_notes')->nullable();
            $t->json('evidence_files')->nullable();
            $t->timestamps();
        });

        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->school = School::create(['tenant_id' => 'test-tenant', 'name' => 'Greenfield Academy']);
    }

    private function makeUser(string $role, string $email): User
    {
        return User::create([
            'tenant_id' => 'test-tenant', 'name' => ucfirst($role), 'email' => $email,
            'password' => bcrypt('secret'), 'role' => $role,
        ]);
    }

    private function requestFor(array $payload): Request
    {
        $request = Request::create('/', 'POST', $payload);
        $request->attributes->set('school', $this->school);

        return $request;
    }

    public function test_restricting_hostel_manage_for_admin_blocks_creating_a_room(): void
    {
        $this->actingAs($this->makeUser('school_admin', 'owner@example.test'));
        $matrix = RoleCapabilityService::matrixForSchool($this->school->id);
        $matrix['admin']['hostel.manage'] = false;
        RoleCapabilityService::saveForSchool($this->school->id, $matrix);

        $this->actingAs($this->makeUser('admin', 'admin@example.test'));
        $response = (new HostelRoomController())->store($this->requestFor([
            'room_number' => 'A101', 'type' => 'double', 'capacity' => 2,
        ]));
        $this->assertSame(403, $response->getStatusCode());

        // housemaster's hostel.manage default (true) is untouched by
        // restricting admin specifically.
        $this->actingAs($this->makeUser('housemaster', 'housemaster@example.test'));
        $response = (new HostelRoomController())->store($this->requestFor([
            'room_number' => 'A102', 'type' => 'double', 'capacity' => 2,
        ]));
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
    }

    public function test_restricting_security_manage_for_admin_blocks_logging_an_incident(): void
    {
        $this->actingAs($this->makeUser('school_admin', 'owner@example.test'));
        $matrix = RoleCapabilityService::matrixForSchool($this->school->id);
        $matrix['admin']['security.manage'] = false;
        RoleCapabilityService::saveForSchool($this->school->id, $matrix);

        $this->actingAs($this->makeUser('admin', 'admin@example.test'));
        $response = (new SecurityController())->incidentStore($this->requestFor([
            'type' => 'other', 'title' => 'Test incident', 'description' => 'Details',
            'severity' => 'low',
        ]));
        $this->assertSame(403, $response->getStatusCode());

        // security role's own default (true) is untouched.
        $this->actingAs($this->makeUser('security', 'guard@example.test'));
        $response = (new SecurityController())->incidentStore($this->requestFor([
            'type' => 'other', 'title' => 'Test incident', 'description' => 'Details',
            'severity' => 'low',
        ]));
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
    }
}
