<?php

namespace Tests\Feature;

use App\Http\Controllers\UserController;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "Users: should have a filter system to accommodate growth/increase" —
 * added filtering by class (a student in it, or a teacher assigned to it —
 * either the whole year group's class_teacher_id or one arm's
 * class_arm.class_teacher_id) and by department (teachers only —
 * staff.department is a free-text field, not linked to the departments
 * table, so it isn't included).
 */
class UsersFilterTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

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
            $t->string('status')->default('active');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('teachers', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('students', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('classes', function ($t) {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('class_teacher_id')->nullable();
            $t->timestamps();
        });

        Schema::create('class_arm', function ($t) {
            $t->id();
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('arm_id')->nullable();
            $t->unsignedBigInteger('class_teacher_id')->nullable();
            $t->timestamps();
        });

        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->school = School::create(['tenant_id' => 'test-tenant', 'name' => 'Greenfield Academy']);
    }

    private function makeTeacherUser(string $email, ?int $departmentId = null): array
    {
        $user = User::create([
            'tenant_id' => 'test-tenant', 'name' => 'Teacher ' . $email, 'email' => $email,
            'password' => bcrypt('secret'), 'role' => 'teacher',
        ]);
        $teacherId = DB::table('teachers')->insertGetId([
            'user_id' => $user->id, 'department_id' => $departmentId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $teacherId];
    }

    public function test_filters_by_class_matches_a_student_in_that_class(): void
    {
        $studentUser = User::create([
            'tenant_id' => 'test-tenant', 'name' => 'A Student', 'email' => 'student@example.test',
            'password' => bcrypt('secret'), 'role' => 'student',
        ]);
        DB::table('students')->insert(['user_id' => $studentUser->id, 'class_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        [$otherStudentUser] = $this->makeTeacherUser('unrelated@example.test'); // just a distinct user in another class context
        DB::table('students')->insert(['user_id' => 999, 'class_id' => 2, 'created_at' => now(), 'updated_at' => now()]);

        $response = (new UserController())->index(Request::create('/', 'GET', ['class_id' => 1]));
        $data = $response->getData(true);
        $ids = collect($data['data'])->pluck('id')->all();

        $this->assertContains($studentUser->id, $ids);
        $this->assertNotContains($otherStudentUser->id, $ids);
    }

    public function test_filters_by_class_matches_the_year_group_class_teacher(): void
    {
        [$teacherUser, $teacherId] = $this->makeTeacherUser('yeartutor@example.test');
        DB::table('classes')->insert(['name' => 'JSS1', 'class_teacher_id' => $teacherId, 'created_at' => now(), 'updated_at' => now()]);
        $classId = DB::table('classes')->where('name', 'JSS1')->value('id');

        [$unrelatedTeacher] = $this->makeTeacherUser('unrelated2@example.test');

        $response = (new UserController())->index(Request::create('/', 'GET', ['class_id' => $classId]));
        $ids = collect($response->getData(true)['data'])->pluck('id')->all();

        $this->assertContains($teacherUser->id, $ids);
        $this->assertNotContains($unrelatedTeacher->id, $ids);
    }

    public function test_filters_by_class_matches_the_arm_level_class_teacher(): void
    {
        [$teacherUser, $teacherId] = $this->makeTeacherUser('classteacher@example.test');
        $classId = DB::table('classes')->insertGetId(['name' => 'SS1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('class_arm')->insert(['class_id' => $classId, 'class_teacher_id' => $teacherId, 'created_at' => now(), 'updated_at' => now()]);

        $response = (new UserController())->index(Request::create('/', 'GET', ['class_id' => $classId]));
        $ids = collect($response->getData(true)['data'])->pluck('id')->all();

        $this->assertContains($teacherUser->id, $ids);
    }

    public function test_filters_by_department(): void
    {
        [$scienceTeacher] = $this->makeTeacherUser('science@example.test', 5);
        [$artsTeacher] = $this->makeTeacherUser('arts@example.test', 6);

        $response = (new UserController())->index(Request::create('/', 'GET', ['department_id' => 5]));
        $ids = collect($response->getData(true)['data'])->pluck('id')->all();

        $this->assertContains($scienceTeacher->id, $ids);
        $this->assertNotContains($artsTeacher->id, $ids);
    }
}
