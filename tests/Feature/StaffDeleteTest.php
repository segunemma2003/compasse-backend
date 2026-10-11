<?php

namespace Tests\Feature;

use App\Http\Controllers\StaffController;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "When I delete on staff it refreshes but the deleted item still appears":
 * the staff list also shows staff-role users with no staff row, and delete
 * removed only the staff row — the leftover user brought them straight back.
 * Delete now removes the login too (as student delete always did).
 */
class StaffDeleteTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
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
            $t->string('role')->default('school_admin');
            $t->string('status')->default('active');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('staff', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('first_name');
            $t->string('last_name');
            $t->string('email')->unique();
            $t->string('role')->default('staff');
            $t->string('status')->default('active');
            $t->timestamps();
            $t->softDeletes();
        });

        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->school = School::create(['tenant_id' => 'test-tenant', 'name' => 'Greenfield Academy']);

        $this->admin = User::create([
            'tenant_id' => 'test-tenant', 'name' => 'Admin', 'email' => 'admin@example.test',
            'password' => bcrypt('secret'), 'role' => 'school_admin',
        ]);
        $this->actingAs($this->admin);
    }

    private function staffWithLogin(string $name, string $role = 'accountant'): array
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name) . '@example.test', 'password' => 'x', 'role' => $role]);
        $staffId = DB::table('staff')->insertGetId([
            'school_id' => $this->school->id, 'user_id' => $user->id, 'first_name' => $name, 'last_name' => 'Eze',
            'email' => strtolower($name) . '@example.test', 'role' => $role, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$staffId, $user];
    }

    private function request(array $body = [], array $query = []): Request
    {
        $request = Request::create('/?' . http_build_query($query), 'POST', $body);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    public function test_delete_removes_the_staff_row_and_its_login(): void
    {
        [$staffId, $user] = $this->staffWithLogin('Blessing');

        $response = (new StaffController())->destroy($this->request(), $staffId);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertNotNull(DB::table('staff')->find($staffId)->deleted_at);
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_user_only_rows_delete_the_user_not_a_staff_row_with_the_same_id(): void
    {
        [$staffId] = $this->staffWithLogin('Blessing');
        $loneUser  = User::create(['name' => 'Tunde Ade', 'email' => 'tunde@example.test', 'password' => 'x', 'role' => 'librarian']);

        $response = (new StaffController())->destroy($this->request([], ['source' => 'user_record']), $loneUser->id);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSoftDeleted('users', ['id' => $loneUser->id]);
        $this->assertNull(DB::table('staff')->find($staffId)->deleted_at, 'an unrelated staff row must not be touched');
    }

    public function test_cannot_delete_yourself_or_the_only_school_admin(): void
    {
        $response = (new StaffController())->destroy($this->request([], ['source' => 'user_record']), $this->admin->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotSoftDeleted('users', ['id' => $this->admin->id]);
    }

    public function test_bulk_delete_and_bulk_status(): void
    {
        [$a, $userA] = $this->staffWithLogin('Ada');
        [$b]         = $this->staffWithLogin('Bola');
        [$c]         = $this->staffWithLogin('Chi');

        $response = (new StaffController())->bulkStatus($this->request([
            'items' => [['id' => $b, 'source' => 'staff_record'], ['id' => $c, 'source' => 'staff_record']],
            'status' => 'inactive',
        ]));
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame('inactive', DB::table('staff')->find($b)->status);
        $this->assertSame('active', DB::table('staff')->find($a)->status);

        $response = (new StaffController())->bulkDestroy($this->request([
            'items' => [['id' => $a, 'source' => 'staff_record'], ['id' => $this->admin->id, 'source' => 'user_record']],
        ]));
        $data = $response->getData(true);

        $this->assertSame(1, $data['deleted']);
        $this->assertCount(1, $data['failed'], 'deleting yourself is skipped, not fatal');
        $this->assertSoftDeleted('users', ['id' => $userA->id]);
    }
}
