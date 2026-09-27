<?php

namespace Tests\Feature;

use App\Http\Controllers\SubjectController;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Picking a "Teacher" on the Subject form only ever wrote subjects.teacher_id
 * (a display-only "primary teacher" field) — every real access check
 * (Controller::accessibleStudentIds()/assertCanManageSubjectResource(), used
 * by CA/exam/assignment rosters and record-scores) consults the separate
 * teacher_subjects pivot instead, which this never touched. A subject
 * teacher assigned only this way could see the subject existed but got an
 * empty roster and a 403 the moment they tried to record CA scores.
 * SubjectController::store()/update() now keep the pivot in sync; the
 * migration in the same change backfills subjects that already had a
 * teacher_id before this fix.
 */
class SubjectPrimaryTeacherSyncTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private int $classId;
    private int $teacherId;

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
            $t->string('role')->default('school_admin');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('classes', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('teachers', function ($t) {
            $t->id();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('employee_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('subjects', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('class_id')->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->string('name');
            $t->string('code')->unique();
            $t->text('description')->nullable();
            $t->integer('credits')->default(1);
            $t->unsignedBigInteger('teacher_id')->nullable();
            $t->string('status')->default('active');
            $t->boolean('is_optional')->default(false);
            $t->timestamps();
        });

        Schema::create('teacher_subjects', function ($t) {
            $t->id();
            $t->unsignedBigInteger('teacher_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('class_id')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });

        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->school = School::create(['tenant_id' => 'test-tenant', 'name' => 'Greenfield Academy']);

        $this->actingAs(User::create([
            'tenant_id' => 'test-tenant', 'name' => 'Admin', 'email' => 'admin@example.test',
            'password' => bcrypt('secret'), 'role' => 'school_admin',
        ]));

        $this->classId = DB::table('classes')->insertGetId([
            'school_id' => $this->school->id, 'name' => 'JSS1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->teacherId = DB::table('teachers')->insertGetId([
            'first_name' => 'Ada', 'last_name' => 'Teacher', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function request(array $payload): Request
    {
        $req = Request::create('/', 'POST', $payload);
        $req->attributes->set('school', $this->school);

        return $req;
    }

    public function test_creating_a_subject_with_a_teacher_grants_real_teacher_subjects_access(): void
    {
        $response = (new SubjectController())->store($this->request([
            'name' => 'Mathematics', 'class_id' => $this->classId, 'teacher_id' => $this->teacherId,
        ]));

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $subjectId = Subject::where('name', 'Mathematics')->value('id');

        $this->assertTrue(
            DB::table('teacher_subjects')
                ->where('teacher_id', $this->teacherId)
                ->where('subject_id', $subjectId)
                ->where('class_id', $this->classId)
                ->where('status', 'active')
                ->exists(),
            'picking a teacher on the Subject form must grant the same access "Manage Teachers" would'
        );
    }

    public function test_updating_a_subjects_teacher_grants_the_new_teacher_without_removing_the_old_one(): void
    {
        $subject = Subject::create([
            'school_id' => $this->school->id, 'class_id' => $this->classId, 'name' => 'Mathematics',
            'code' => 'MTH', 'teacher_id' => $this->teacherId,
        ]);

        // Simulate the old teacher already having real access (either from
        // the original store() sync, or manually via "Manage Teachers").
        DB::table('teacher_subjects')->insert([
            'teacher_id' => $this->teacherId, 'subject_id' => $subject->id, 'class_id' => $this->classId,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $newTeacherId = DB::table('teachers')->insertGetId([
            'first_name' => 'Bola', 'last_name' => 'Teacher', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = (new SubjectController())->update(
            $this->request(['teacher_id' => $newTeacherId]),
            $subject
        );

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $this->assertTrue(
            DB::table('teacher_subjects')->where(['teacher_id' => $newTeacherId, 'subject_id' => $subject->id, 'class_id' => $this->classId])->exists(),
            'the newly picked teacher must get access'
        );
        $this->assertTrue(
            DB::table('teacher_subjects')->where(['teacher_id' => $this->teacherId, 'subject_id' => $subject->id, 'class_id' => $this->classId])->exists(),
            'a co-teacher/previous teacher must not be silently dropped'
        );
    }

    public function test_backfill_migration_grants_access_for_subjects_assigned_before_the_fix_but_leaves_existing_pivot_rows_alone(): void
    {
        $subjectWithNoPivotYet = Subject::create([
            'school_id' => $this->school->id, 'class_id' => $this->classId, 'name' => 'Physics',
            'code' => 'PHY', 'teacher_id' => $this->teacherId,
        ]);

        $otherTeacherId = DB::table('teachers')->insertGetId([
            'first_name' => 'Chidi', 'last_name' => 'Teacher', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $subjectAlreadySynced = Subject::create([
            'school_id' => $this->school->id, 'class_id' => $this->classId, 'name' => 'Chemistry',
            'code' => 'CHM', 'teacher_id' => $otherTeacherId,
        ]);
        DB::table('teacher_subjects')->insert([
            'teacher_id' => $otherTeacherId, 'subject_id' => $subjectAlreadySynced->id, 'class_id' => $this->classId,
            'status' => 'inactive', 'created_at' => now(), 'updated_at' => now(),
        ]);

        (require base_path('database/migrations/tenant/2026_09_27_000001_backfill_teacher_subjects_from_subject_teacher_id.php'))->up();

        $this->assertTrue(
            DB::table('teacher_subjects')
                ->where(['teacher_id' => $this->teacherId, 'subject_id' => $subjectWithNoPivotYet->id, 'status' => 'active'])
                ->exists(),
            'a subject with a pre-fix teacher_id and no pivot row yet must be backfilled'
        );

        $this->assertSame(
            1,
            DB::table('teacher_subjects')->where('subject_id', $subjectAlreadySynced->id)->count(),
            'a subject that already has a pivot row must not get a duplicate'
        );
        $this->assertSame(
            'inactive',
            DB::table('teacher_subjects')->where('subject_id', $subjectAlreadySynced->id)->value('status'),
            'an existing pivot row (even a deliberately inactive one) must not be touched by the backfill'
        );
    }
}
