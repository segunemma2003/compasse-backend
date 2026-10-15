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
                $t->unsignedBigInteger('teacher_id')->nullable(), $t->string('name'), $t->string('type')->nullable(), $t->decimal('total_marks'), $t->boolean('counts_for_mid_term')->default(true), $t->date('assessment_date')->nullable(), $t->text('description')->nullable(), $t->string('status')->nullable(), $t->timestamps()],
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

    public function test_added_columns_and_mid_term_ticks_are_saved(): void
    {
        $c = new MarkSheetController();
        $ctx = ['class_id' => 1, 'subject_id' => 1, 'term_id' => 1];
        $res = $c->save($this->req('POST', $ctx + [
            'columns' => [
                ['key' => 'new-ca:1', 'label' => '2nd CA', 'max' => 20, 'mid_term' => false],
                ['key' => 'add:1', 'label' => 'Mid-term test', 'max' => 30, 'mid_term' => true],
            ],
            'rows' => [['student_id' => 1, 'values' => ['add:1' => 25, 'new-ca:0' => 15]]],
        ]));
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());

        $this->assertSame(['1st CA' => 1, '2nd CA' => 0, 'Mid-term test' => 1],
            DB::table('continuous_assessments')->orderBy('id')->pluck('counts_for_mid_term', 'name')->map(fn ($v) => (int) $v)->all());
        $this->assertEquals(25, DB::table('ca_scores')->join('continuous_assessments', 'continuous_assessments.id', '=', 'ca_scores.continuous_assessment_id')
            ->where('continuous_assessments.name', 'Mid-term test')->value('score'));

        $sheet = $c->show($this->req('GET', $ctx))->getData(true);
        $this->assertSame([true, false, true], array_column(array_filter($sheet['columns'], fn ($col) => $col['kind'] === 'ca'), 'mid_term'));
    }

    public function test_a_cbt_paper_shows_with_a_theory_column_and_both_count(): void
    {
        DB::table('exams')->insert(['id' => 9, 'subject_id' => 1, 'class_id' => 1, 'term_id' => 1, 'academic_year_id' => 1, 'name' => 'First term', 'type' => 'exam',
            'duration_minutes' => 30, 'total_marks' => 40, 'passing_marks' => 20, 'start_date' => now(), 'end_date' => now(), 'is_cbt' => true, 'status' => 'active', 'created_by' => 1]);
        DB::table('exam_submissions')->insert(['exam_id' => 9, 'student_id' => 1, 'score' => 30]);
        $c = new MarkSheetController();
        $ctx = ['class_id' => 1, 'subject_id' => 1, 'term_id' => 1];

        $cols = collect($c->show($this->req('GET', $ctx))->getData(true)['columns'])->where('kind', 'exam')->values();
        $this->assertSame(['First term (CBT)', 'Theory'], $cols->pluck('label')->all());
        $this->assertEquals(20, $cols[1]['max'], 'theory gets what is left of the 60-mark exam');

        $res = $c->save($this->req('POST', $ctx + ['rows' => [['student_id' => 1, 'values' => ['new-exam' => 15]]]]));
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $this->assertEquals(45, DB::table('exam_submissions')->where('student_id', 1)->sum('score'), 'CBT 30 + theory 15');
        $row = $res->getData(true)['rows'][0]['values'];
        $this->assertEquals(30, $row['exam:9']);
    }
}
