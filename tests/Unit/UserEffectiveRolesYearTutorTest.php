<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\UserEffectiveRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "Year-Tutor-to-year-group linking" — assigning a teacher as a class's
 * (year group's, e.g. JSS1) teacher via classes.class_teacher_id should
 * grant the Year Tutor role, distinct from the arm-level "Class Teacher"
 * (class_arm.class_teacher_id, e.g. JSS1A specifically). Both columns used
 * to infer the exact same 'class_teacher' role, so a year tutor never
 * showed up labeled as one anywhere.
 *
 * Access scoping itself (Controller::accessibleStudentIds()) was not
 * touched — it already scopes a classes.class_teacher_id assignment to
 * every student in that class across all its arms, and treats every
 * TEACHER_ROLES entry (teacher/class_teacher/subject_teacher/year_tutor/hod)
 * identically for that purpose, so relabeling doesn't change what they can
 * see, only what they're correctly called.
 */
class UserEffectiveRolesYearTutorTest extends TestCase
{
    use RefreshDatabase;

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

        Schema::create('teachers', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->timestamps();
        });

        Schema::create('classes', function ($t) {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('class_teacher_id')->nullable();
            $t->timestamps();
        });

        Schema::create('arms', function ($t) { $t->id(); $t->string('name'); $t->timestamps(); });

        Schema::create('class_arm', function ($t) {
            $t->id();
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('arm_id');
            $t->unsignedBigInteger('class_teacher_id')->nullable();
            $t->timestamps();
        });
    }

    private function makeTeacherUser(): array
    {
        $user = User::create([
            'tenant_id' => 'test-tenant', 'name' => 'Mrs Okoro', 'email' => 'okoro@example.test',
            'password' => bcrypt('secret'), 'role' => 'teacher',
        ]);
        $teacherId = DB::table('teachers')->insertGetId([
            'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $teacherId];
    }

    public function test_year_group_class_teacher_id_grants_year_tutor_not_class_teacher(): void
    {
        [$user, $teacherId] = $this->makeTeacherUser();
        DB::table('classes')->insert(['name' => 'JSS1', 'class_teacher_id' => $teacherId, 'created_at' => now(), 'updated_at' => now()]);

        $roles = UserEffectiveRoles::forUser($user);

        $this->assertContains('year_tutor', $roles);
        $this->assertNotContains('class_teacher', $roles);
    }

    public function test_arm_level_class_teacher_id_grants_class_teacher_not_year_tutor(): void
    {
        [$user, $teacherId] = $this->makeTeacherUser();
        $classId = DB::table('classes')->insertGetId(['name' => 'JSS1', 'created_at' => now(), 'updated_at' => now()]);
        $armId = DB::table('arms')->insertGetId(['name' => 'A', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('class_arm')->insert([
            'class_id' => $classId, 'arm_id' => $armId, 'class_teacher_id' => $teacherId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $roles = UserEffectiveRoles::forUser($user);

        $this->assertContains('class_teacher', $roles);
        $this->assertNotContains('year_tutor', $roles);
    }

    public function test_a_teacher_with_neither_assignment_gets_neither_role(): void
    {
        [$user] = $this->makeTeacherUser();

        $roles = UserEffectiveRoles::forUser($user);

        $this->assertNotContains('year_tutor', $roles);
        $this->assertNotContains('class_teacher', $roles);
    }

    public function test_a_teacher_can_be_both_year_tutor_of_one_year_group_and_class_teacher_of_an_arm(): void
    {
        [$user, $teacherId] = $this->makeTeacherUser();
        DB::table('classes')->insert(['name' => 'JSS1', 'class_teacher_id' => $teacherId, 'created_at' => now(), 'updated_at' => now()]);
        $ss1 = DB::table('classes')->insertGetId(['name' => 'SS1', 'created_at' => now(), 'updated_at' => now()]);
        $armId = DB::table('arms')->insertGetId(['name' => 'B', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('class_arm')->insert([
            'class_id' => $ss1, 'arm_id' => $armId, 'class_teacher_id' => $teacherId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $roles = UserEffectiveRoles::forUser($user);

        $this->assertContains('year_tutor', $roles);
        $this->assertContains('class_teacher', $roles);
    }
}
