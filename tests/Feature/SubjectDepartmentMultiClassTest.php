<?php

namespace Tests\Feature;

use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\SubjectController;
use App\Models\Department;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "Subject/Department multi-class checkboxes" — checking several classes at
 * once when creating a Subject or Department, instead of only "one class"
 * or "all classes".
 *
 *   - Subject: one row per checked class (class_ids), same pattern as
 *     Assign Fee by Class — each class's copy is independent afterward
 *     (own teacher, own optional flag). This already existed for "all
 *     classes"; class_ids adds the same treatment for a chosen subset.
 *   - Department: a single row linked to every checked class via a new
 *     department_classes pivot — duplicating "Science Department" per
 *     class would just be noise for an org unit that spans many classes.
 */
class SubjectDepartmentMultiClassTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private array $classIds = [];

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

        Schema::create('classes', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->string('name');
            $t->string('level')->nullable();
            $t->unsignedBigInteger('class_teacher_id')->nullable();
            $t->timestamps();
        });

        Schema::create('departments', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->string('name');
            $t->text('description')->nullable();
            $t->unsignedBigInteger('head_id')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });

        Schema::create('department_classes', function ($t) {
            $t->id();
            $t->unsignedBigInteger('department_id');
            $t->unsignedBigInteger('class_id');
            $t->timestamps();
            $t->unique(['department_id', 'class_id']);
        });

        Schema::create('teachers', function ($t) {
            $t->id();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('employee_id')->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->timestamps();
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

        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->school = School::create(['tenant_id' => 'test-tenant', 'name' => 'Greenfield Academy']);

        $this->actingAs(User::create([
            'tenant_id' => 'test-tenant', 'name' => 'Admin', 'email' => 'admin@example.test',
            'password' => bcrypt('secret'), 'role' => 'school_admin',
        ]));

        foreach (['JSS1', 'JSS2', 'JSS3', 'SS1'] as $name) {
            $this->classIds[] = DB::table('classes')->insertGetId([
                'school_id' => $this->school->id, 'name' => $name,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function request(array $payload): Request
    {
        $req = Request::create('/', 'POST', $payload);
        $req->attributes->set('school', $this->school);

        return $req;
    }

    public function test_subject_with_class_ids_creates_one_row_per_selected_class_only(): void
    {
        [$jss1, $jss2, , $ss1] = $this->classIds;

        $response = (new SubjectController())->store($this->request([
            'name' => 'Mathematics',
            'class_ids' => [$jss1, $jss2],
        ]));

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $data = $response->getData(true);
        $this->assertSame(2, count($data['subjects']), 'expected exactly the two selected classes, not all four');

        $classIdsCreated = Subject::where('name', 'Mathematics')->pluck('class_id')->sort()->values()->all();
        $this->assertSame([$jss1, $jss2], $classIdsCreated);
        $this->assertFalse(in_array($ss1, $classIdsCreated, true), 'SS1 was not checked and should not have gotten a copy');
    }

    public function test_subject_with_no_class_selection_still_creates_for_every_class(): void
    {
        $response = (new SubjectController())->store($this->request([
            'name' => 'General Studies',
        ]));

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $this->assertSame(4, Subject::where('name', 'General Studies')->count());
    }

    public function test_subject_pinned_to_a_single_class_is_unaffected(): void
    {
        [$jss1] = $this->classIds;

        $response = (new SubjectController())->store($this->request([
            'name' => 'Further Maths',
            'class_id' => $jss1,
        ]));

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $this->assertSame(1, Subject::where('name', 'Further Maths')->count());
        $this->assertSame($jss1, Subject::where('name', 'Further Maths')->value('class_id'));
    }

    public function test_department_with_class_ids_links_one_row_to_all_selected_classes(): void
    {
        [$jss1, $jss2, $jss3] = $this->classIds;

        $response = (new DepartmentController())->store($this->request([
            'name' => 'Science',
            'class_ids' => [$jss1, $jss2, $jss3],
        ]));

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $this->assertSame(1, Department::where('name', 'Science')->count(), 'should be one department row, not one per class');

        $dept = Department::where('name', 'Science')->first();
        $this->assertCount(3, $dept->classes);
        $this->assertSame([$jss1, $jss2, $jss3], $dept->classes->pluck('id')->sort()->values()->all());
    }

    public function test_updating_a_departments_classes_replaces_the_link_set(): void
    {
        [$jss1, $jss2, $jss3, $ss1] = $this->classIds;

        $dept = Department::create(['school_id' => $this->school->id, 'name' => 'Languages', 'status' => 'active']);
        $dept->classes()->sync([$jss1, $jss2]);

        $response = (new DepartmentController())->update($this->request(['class_ids' => [$jss3, $ss1]]), $dept);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $fresh = $dept->fresh();
        $this->assertSame([$jss3, $ss1], $fresh->classes->pluck('id')->sort()->values()->all());
    }
}
