<?php

namespace Tests\Feature;

use App\Http\Controllers\StudentController;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression test for the production bug fixed 2026-09-11: the Students
 * screen reuses a row from GET /students (never calls GET /students/{id})
 * to populate both the "View Profile" dialog and the edit form, but the
 * index() query never eager-loaded the guardians relationship — so
 * guardian info silently never showed there, even though it existed in
 * the database and DID show via the single-record endpoint.
 */
class StudentGuardianEagerLoadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('students', function ($table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('admission_number')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->date('admission_date');
            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('arm_id')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes(); // Student uses SoftDeletes
        });

        Schema::create('guardians', function ($table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('guardian_students', function ($table) {
            $table->id();
            $table->unsignedBigInteger('guardian_id');
            $table->unsignedBigInteger('student_id');
            $table->string('relationship');
            $table->boolean('is_primary')->default(false);
            $table->boolean('emergency_contact')->default(false);
            $table->timestamps();
        });

        // Empty is fine — Student::class_id/arm_id are null in this test,
        // but index()'s with(['class', 'arm']) still issues an eager-load
        // query against these tables regardless, so they must exist.
        Schema::create('classes', function ($table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('arms', function ($table) {
            $table->id();
            $table->string('name');
        });
    }

    public function test_students_index_includes_guardians_for_the_profile_and_edit_views(): void
    {
        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        $school = School::create(['tenant_id' => 'test-tenant', 'name' => 'Test School']);

        $student = Student::create([
            'school_id' => $school->id, 'admission_number' => 'ADM001',
            'first_name' => 'Ada', 'last_name' => 'Okafor', 'email' => 'ada@example.test',
            'admission_date' => now(), 'status' => 'active',
        ]);

        $guardianId = DB::table('guardians')->insertGetId([
            'school_id' => $school->id, 'first_name' => 'Ngozi', 'last_name' => 'Okafor',
            'email' => 'ngozi@example.test', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('guardian_students')->insert([
            'guardian_id' => $guardianId, 'student_id' => $student->id,
            'relationship' => 'Mother', 'is_primary' => true, 'emergency_contact' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $admin = User::factory()->create(['role' => 'school_admin']);
        $request = Request::create('/students', 'GET');
        $request->setUserResolver(fn () => $admin);

        $response = (new StudentController())->index($request);
        $data = $response->getData(true)['data'];

        $this->assertCount(1, $data);
        $this->assertArrayHasKey('guardians', $data[0], 'guardians relation missing from the list response');
        $this->assertCount(1, $data[0]['guardians']);
        $this->assertSame('Ngozi', $data[0]['guardians'][0]['first_name']);
        $this->assertSame('Mother', $data[0]['guardians'][0]['pivot']['relationship']);
    }
}
