<?php

namespace Tests\Feature;

use App\Http\Controllers\MarkSheetController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Mark sheet: columns from result settings, created on first save, scores validated and stored where results read them. */
class MarkSheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $tables = [
            'users' => fn ($t) => [$t->id(), $t->string('name'), $t->string('email'), $t->string('password'), $t->string('role'), $t->timestamps(), $t->softDeletes()],
            'schools' => fn ($t) => [$t->id(), $t->string('name')->nullable(), $t->timestamps()],
            'classes' => fn ($t) => [$t->id(), $t->string('name'), $t->string('section_type')->nullable(), $t->unsignedBigInteger('class_teacher_id')->nullable(), $t->timestamps()],
            'subjects' => fn ($t) => [$t->id(), $t->unsignedBigInteger('class_id')->nullable(), $t->string('name'), $t->unsignedBigInteger('teacher_id')->nullable(), $t->timestamps()],
            'students' => fn ($t) => [$t->id(), $t->unsignedBigInteger('class_id')->nullable(), $t->string('first_name'), $t->string('last_name'), $t->string('admission_number')->nullable(), $t->string('status')->nullable(), $t->timestamps(), $t->softDeletes()],
            'teachers' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->timestamps(), $t->softDeletes()],
            'academic_years' => fn ($t) => [$t->id(), $t->string('name')->nullable(), $t->boolean('is_current')->default(false), $t->timestamps()],
            'terms' => fn ($t) => [$t->id(), $t->unsignedBigInteger('academic_year_id'), $t->string('name')->nullable(), $t->boolean('is_current')->default(false), $t->timestamps()],
            'result_configurations' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('section_type'), $t->unsignedBigInteger('class_id')->nullable(), $t->string('name')->nullable(),
                $t->decimal('ca_weight')->default(40), $t->decimal('exam_weight')->default(60), $t->json('assessment_components')->nullable(), $t->json('custom_settings')->nullable(), $t->string('grade_style')->nullable(), $t->boolean('is_active')->default(true), $t->timestamps()],
            'continuous_assessments' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->unsignedBigInteger('subject_id')->nullable(), $t->unsignedBigInteger('class_id'), $t->unsignedBigInteger('term_id'), $t->unsignedBigInteger('academic_year_id'),
                $t->unsignedBigInteger('teacher_id')->nullable(), $t->string('name'), $t->string('type')->nullable(), $t->decimal('total_marks'), $t->date('assessment_date')->nullable(), $t->text('description')->nullable(), $t->string('status')->nullable(), $t->timestamps()],
            'ca_scores' => fn ($t) => [$t->id(), $t->unsignedBigInteger('continuous_assessment_id'), $t->unsignedBigInteger('student_id'), $t->decimal('score'), $t->text('remarks')->nullable(), $t->unsignedBigInteger('recorded_by')->nullable(), $t->timestamps()],
            'exams' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->unsignedBigInteger('subject_id'), $t->unsignedBigInteger('class_id')->nullable(), $t->unsignedBigInteger('term_id'), $t->unsignedBigInteger('academic_year_id'),
                $t->string('name'), $t->string('type')->nullable(), $t->integer('duration_minutes'), $t->decimal('total_marks'), $t->decimal('passing_marks'), $t->dateTime('start_date'), $t->dateTime('end_date'), $t->boolean('is_cbt')->default(false), $t->json('cbt_settings')->nullable(), $t->string('status')->nullable(), $t->unsignedBigInteger('created_by'), $t->timestamps()],
            'exam_submissions' => fn ($t) => [$t->id(), $t->unsignedBigInteger('exam_id'), $t->unsignedBigInteger('student_id'), $t->decimal('score')->nullable(), $t->text('remarks')->nullable(), $t->unsignedBigInteger('recorded_by')->nullable(), $t->timestamps()],
        ];
        foreach ($tables as $name => $def) {
            Schema::dropIfExists($name);
            Schema::create($name, $def);
        }
        Schema::dropIfExists('results');

        DB::table('schools')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('classes')->insert(['id' => 1, 'name' => 'Year One', 'section_type' => 'primary']);
        DB::table('subjects')->insert(['id' => 1, 'class_id' => 1, 'name' => 'Maths']);
        DB::table('academic_years')->insert(['id' => 1, 'name' => '2026/27', 'is_current' => true]);
        DB::table('terms')->insert(['id' => 1, 'academic_year_id' => 1, 'name' => 'First', 'is_current' => true]);
        DB::table('students')->insert([['id' => 1, 'class_id' => 1, 'first_name' => 'Ada', 'last_name' => 'O'], ['id' => 2, 'class_id' => 1, 'first_name' => 'Ben', 'last_name' => 'E']]);
        DB::table('result_configurations')->insert(['school_id' => 1, 'section_type' => 'primary', 'ca_weight' => 40, 'exam_weight' => 60,
            'assessment_components' => json_encode([['name' => '1st CA', 'max' => 20], ['name' => '2nd CA', 'max' => 20]])]);
        User::forceCreate(['id' => 1, 'name' => 'Admin', 'email' => 'a@example.com', 'password' => 'x', 'role' => 'school_admin']);
    }

    private function req(string $method, array $data): Request
    {
        $u = User::find(1);
        $this->actingAs($u);
        $r = Request::create('/', $method, $data);
        $r->setUserResolver(fn () => $u);

        return $r;
    }

    public function test_columns_come_from_result_settings_and_are_created_on_first_save(): void
    {
        $c = new MarkSheetController();
        $ctx = ['class_id' => 1, 'subject_id' => 1, 'term_id' => 1];

        $sheet = $c->show($this->req('GET', $ctx))->getData(true);
        $this->assertSame(['1st CA', '2nd CA', 'Exam'], array_column($sheet['columns'], 'label'));
        $this->assertSame([20.0, 20.0, 60.0], array_map('floatval', array_column($sheet['columns'], 'max')));

        $res = $c->save($this->req('POST', $ctx + ['rows' => [
            ['student_id' => 1, 'values' => ['new-ca:0' => 18, 'new-ca:1' => 17, 'new-exam' => 50]],
            ['student_id' => 2, 'values' => ['new-ca:0' => 10, 'new-exam' => 30]],
        ]]));
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());

        $this->assertSame(2, DB::table('continuous_assessments')->count());
        $this->assertSame(1, DB::table('exams')->count());
        $this->assertEquals(35, DB::table('ca_scores')->where('student_id', 1)->sum('score'));
        $this->assertEquals(50, DB::table('exam_submissions')->where('student_id', 1)->value('score'));

        $after = $c->show($this->req('GET', $ctx))->getData(true);
        $this->assertTrue(collect($after['columns'])->every(fn ($col) => $col['exists']));
        $this->assertEquals(18, $after['rows'][0]['values'][$after['columns'][0]['key']]);
    }

    public function test_scores_above_the_column_maximum_are_rejected(): void
    {
        $res = (new MarkSheetController())->save($this->req('POST', ['class_id' => 1, 'subject_id' => 1, 'term_id' => 1, 'rows' => [
            ['student_id' => 1, 'values' => ['new-ca:0' => 25]],
        ]]));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame(0, DB::table('ca_scores')->count());
    }
}
