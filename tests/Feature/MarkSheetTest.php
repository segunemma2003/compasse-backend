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
        DB::table('teachers')->insert(['id' => 7, 'user_id' => 50]);
        DB::table('subjects')->insert(['id' => 1, 'class_id' => 1, 'name' => 'Maths', 'teacher_id' => 7]);
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
        $this->assertEquals(7, DB::table('exams')->value('created_by'), 'exams.created_by is a teachers.id: an admin saving credits the subject teacher, not their user id');
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

    public function test_teachers_enter_scores_but_cannot_change_the_columns(): void
    {
        User::forceCreate(['id' => 50, 'name' => 'Tola', 'email' => 't@example.com', 'password' => 'x', 'role' => 'teacher']);
        Schema::create('teacher_subjects', fn ($t) => [$t->id(), $t->unsignedBigInteger('teacher_id'), $t->unsignedBigInteger('subject_id'), $t->unsignedBigInteger('class_id')->nullable(), $t->string('status')->default('active'), $t->timestamps()]);
        DB::table('teacher_subjects')->insert(['teacher_id' => 7, 'subject_id' => 1, 'class_id' => 1]);
        $c = new MarkSheetController();
        $ctx = ['class_id' => 1, 'subject_id' => 1, 'term_id' => 1];
        $teacher = User::find(50);
        $this->actingAs($teacher);
        $r = Request::create('/', 'POST', $ctx + [
            'columns' => [['key' => 'new-ca:0', 'label' => 'My test', 'max' => 25], ['key' => 'add:1', 'label' => 'Extra', 'max' => 10]],
            'rows' => [['student_id' => 1, 'values' => ['new-ca:0' => 18]]],
        ]);
        $r->setUserResolver(fn () => $teacher);
        $res = $c->save($r);
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $this->assertFalse($res->getData(true)['can_edit_columns']);
        $this->assertSame(['1st CA', '2nd CA'], DB::table('continuous_assessments')->orderBy('id')->pluck('name')->all(), 'the set-up\'s columns, no extra');
        $this->assertEquals(20, DB::table('continuous_assessments')->where('name', '1st CA')->value('total_marks'), 'marks stay as the school set them');
        $this->assertEquals(18, DB::table('ca_scores')->value('score'));
    }

    public function test_the_school_applies_one_set_of_marks_to_every_subject(): void
    {
        $c = new MarkSheetController();
        $ctx = ['class_id' => 1, 'subject_id' => 1, 'term_id' => 1];
        // Maths was set up earlier as 1st CA 25, 2nd CA 15, Exam 70, with one score of 23.
        $c->save($this->req('POST', $ctx + ['rows' => [['student_id' => 1, 'values' => ['new-ca:0' => 15, 'new-ca:1' => 10, 'new-exam' => 50]]]]));
        DB::table('continuous_assessments')->where('name', '1st CA')->update(['total_marks' => 25]);
        DB::table('continuous_assessments')->where('name', '2nd CA')->update(['total_marks' => 15, 'name' => 'Second test']);
        DB::table('exams')->update(['total_marks' => 70]);
        DB::table('ca_scores')->where('score', 15)->update(['score' => 23]);
        $cfgId = DB::table('result_configurations')->value('id');
        $res = (new \App\Http\Controllers\ResultConfigurationController())->applyColumns($this->req('POST', ['term_id' => 1]), $cfgId);
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());

        $sheet = $c->show($this->req('GET', $ctx))->getData(true);
        $this->assertSame([20.0, 20.0, 60.0], array_map('floatval', array_column($sheet['columns'], 'max')), 'the set-up: 20 / 20 / 60');
        $this->assertSame(['1st CA', '2nd CA'], array_slice(array_column($sheet['columns'], 'label'), 0, 2));
        $this->assertStringContainsString('Maths (Year One) 1st CA: 1 score(s) above 20', implode(' | ', $res->getData(true)['problems']));
    }

    public function test_an_extra_column_scored_only_for_a_deleted_pupil_is_removed(): void
    {
        $c = new MarkSheetController();
        $ctx = ['class_id' => 1, 'subject_id' => 1, 'term_id' => 1];
        $c->show($this->req('GET', $ctx));
        $c->save($this->req('POST', $ctx + ['rows' => [['student_id' => 1, 'values' => ['new-ca:0' => 15]]]]));
        // A stray "CA1 /10" with one score, for a pupil since deleted.
        $extra = DB::table('continuous_assessments')->insertGetId(['school_id' => 1, 'subject_id' => 1, 'class_id' => 1, 'term_id' => 1,
            'academic_year_id' => 1, 'name' => 'CA1', 'type' => 'test', 'total_marks' => 10, 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('students')->insert(['id' => 3, 'class_id' => 1, 'first_name' => 'Felix', 'last_name' => 'Test', 'deleted_at' => now()]);
        DB::table('ca_scores')->insert(['continuous_assessment_id' => $extra, 'student_id' => 3, 'score' => 10, 'created_at' => now(), 'updated_at' => now()]);

        $cfgId = DB::table('result_configurations')->value('id');
        $res = (new \App\Http\Controllers\ResultConfigurationController())->applyColumns($this->req('POST', ['term_id' => 1]), $cfgId);
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $this->assertFalse(DB::table('continuous_assessments')->where('id', $extra)->exists(), 'the stray column is gone');
        $this->assertFalse(DB::table('ca_scores')->where('continuous_assessment_id', $extra)->exists());
        $this->assertSame([], $res->getData(true)['problems']);

        // A live pupil's score keeps it (listed for the school instead).
        $extra2 = DB::table('continuous_assessments')->insertGetId(['school_id' => 1, 'subject_id' => 1, 'class_id' => 1, 'term_id' => 1,
            'academic_year_id' => 1, 'name' => 'CA1', 'type' => 'test', 'total_marks' => 10, 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ca_scores')->insert(['continuous_assessment_id' => $extra2, 'student_id' => 2, 'score' => 8, 'created_at' => now(), 'updated_at' => now()]);
        $res = (new \App\Http\Controllers\ResultConfigurationController())->applyColumns($this->req('POST', ['term_id' => 1]), $cfgId);
        $this->assertTrue(DB::table('continuous_assessments')->where('id', $extra2)->exists());
        $this->assertStringContainsString('extra column "CA1" has scores', implode(' | ', $res->getData(true)['problems']));
    }

    public function test_draft_results_are_generated_again_when_scores_change_after_generating(): void
    {
        foreach ([
            'student_results' => fn ($t) => [$t->id(), $t->unsignedBigInteger('student_id'), $t->unsignedBigInteger('class_id')->nullable(), $t->unsignedBigInteger('term_id'), $t->unsignedBigInteger('academic_year_id'), $t->string('result_type')->default('end_term'), $t->string('status')->default('draft'), $t->timestamps()],
            'settings' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('key'), $t->text('value')->nullable(), $t->timestamps()],
        ] as $name => $def) {
            Schema::dropIfExists($name);
            Schema::create($name, $def);
        }
        // Stand-in for the generator (its position query is MySQL-only): counts the runs.
        $runs = new \ArrayObject();
        app()->instance(\App\Http\Controllers\ResultController::class, new class($runs) extends \App\Http\Controllers\ResultController {
            public function __construct(private \ArrayObject $runs) {}
            public function runGeneration(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
            {
                $this->runs->append($request->all());
                \App\Support\ResultFreshness::markGenerated((int) $request->class_id, (int) $request->term_id, (int) $request->academic_year_id, (string) $request->result_type);

                return response()->json(['message' => 'ok']);
            }
        });
        $c = new MarkSheetController();
        $ctx = ['class_id' => 1, 'subject_id' => 1, 'term_id' => 1];
        $ensure = fn () => (new \ReflectionClass(\App\Support\ResultFreshness::class))->setStaticPropertyValue('checked', []) ?? \App\Support\ResultFreshness::ensure(1, 1, 1);

        \Illuminate\Support\Carbon::setTestNow('2026-11-20 09:00:00');
        $c->show($this->req('GET', $ctx));
        $c->save($this->req('POST', $ctx + ['rows' => [['student_id' => 1, 'values' => ['new-ca:0' => 15]]]]));
        \Illuminate\Support\Carbon::setTestNow('2026-11-20 09:05:00');
        DB::table('student_results')->insert([['student_id' => 1, 'class_id' => 1, 'term_id' => 1, 'academic_year_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['student_id' => 2, 'class_id' => 1, 'term_id' => 1, 'academic_year_id' => 1, 'created_at' => now(), 'updated_at' => now()]]);
        \App\Support\ResultFreshness::markGenerated(1, 1, 1, 'end_term');
        $this->assertFalse($ensure(), 'nothing changed since they were generated');

        // The 2nd CA is entered the next day.
        \Illuminate\Support\Carbon::setTestNow('2026-11-21 09:00:00');
        $ca2 = DB::table('continuous_assessments')->where('name', '2nd CA')->value('id');
        $c->save($this->req('POST', $ctx + ['rows' => [['student_id' => 1, 'values' => ["ca:{$ca2}" => 18]]]]));
        \Illuminate\Support\Carbon::setTestNow('2026-11-21 10:00:00');
        $this->assertTrue($ensure(), 'generated again with the new score');
        $this->assertSame(['class_id' => 1, 'term_id' => 1, 'academic_year_id' => 1, 'result_type' => 'end_term'], $runs[0]);
        $this->assertFalse($ensure(), 'and not again until something else changes');

        // A Result Settings change counts too.
        \Illuminate\Support\Carbon::setTestNow('2026-11-21 11:00:00');
        DB::table('result_configurations')->update(['updated_at' => now()]);
        \Illuminate\Support\Carbon::setTestNow('2026-11-21 11:05:00');
        $this->assertTrue($ensure());

        // Approved / published results are never generated again (it would send them back to draft).
        DB::table('student_results')->where('student_id', 2)->update(['status' => 'published']);
        \Illuminate\Support\Carbon::setTestNow('2026-11-22 09:00:00');
        $c->save($this->req('POST', $ctx + ['rows' => [['student_id' => 1, 'values' => ["ca:{$ca2}" => 5]]]]));
        \Illuminate\Support\Carbon::setTestNow('2026-11-22 10:00:00');
        $this->assertFalse($ensure());
        $this->assertCount(2, $runs);
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_a_subject_graded_without_scores_takes_a_grade_per_student(): void
    {
        Schema::table('subjects', fn ($t) => [$t->boolean('grade_only')->default(false), $t->unsignedBigInteger('grading_system_id')->nullable()]);
        Schema::create('subject_grades', fn ($t) => [$t->id(), $t->unsignedBigInteger('student_id'), $t->unsignedBigInteger('subject_id'), $t->unsignedBigInteger('term_id'), $t->unsignedBigInteger('academic_year_id')->nullable(), $t->string('grade'), $t->string('remark')->nullable(), $t->unsignedBigInteger('recorded_by')->nullable(), $t->timestamps()]);
        Schema::dropIfExists('grading_systems');
        Schema::create('grading_systems', fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('name'), $t->boolean('is_default')->default(false), $t->decimal('pass_mark')->default(40), $t->json('grade_boundaries')->nullable(), $t->boolean('is_active')->default(true), $t->timestamps()]);
        DB::table('grading_systems')->insert(['id' => 3, 'name' => 'Handwriting', 'grade_boundaries' => json_encode([['grade' => 'A', 'min' => 70, 'max' => 100, 'remark' => 'Neat'], ['grade' => 'B', 'min' => 0, 'max' => 69, 'remark' => 'Fair']])]);
        DB::table('subjects')->insert(['id' => 9, 'class_id' => 1, 'name' => 'Handwriting', 'grade_only' => true, 'grading_system_id' => 3]);
        $c = new MarkSheetController();
        $ctx = ['class_id' => 1, 'subject_id' => 9, 'term_id' => 1];

        $sheet = $c->show($this->req('GET', $ctx))->getData(true);
        $this->assertSame('grade', $sheet['mode']);
        $this->assertSame(['A', 'B'], array_column($sheet['options'], 'grade'));

        $this->assertSame(422, $c->save($this->req('POST', $ctx + ['rows' => [['student_id' => 1, 'grade' => 'Z']]]))->getStatusCode());
        $res = $c->save($this->req('POST', $ctx + ['rows' => [['student_id' => 1, 'grade' => 'A'], ['student_id' => 2, 'grade' => 'B']]]));
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $this->assertSame('Neat', DB::table('subject_grades')->where('student_id', 1)->value('remark'));
        $this->assertSame(0, DB::table('continuous_assessments')->where('subject_id', 9)->count(), 'no score columns');
    }

    public function test_nobody_changes_the_columns_on_the_mark_sheet_not_even_the_admin(): void
    {
        $c = new MarkSheetController();
        $ctx = ['class_id' => 1, 'subject_id' => 1, 'term_id' => 1];
        $res = $c->save($this->req('POST', $ctx + [
            'columns' => [['key' => 'new-ca:0', 'label' => 'My test', 'max' => 25], ['key' => 'add:1', 'label' => 'Extra', 'max' => 10]],
            'rows' => [['student_id' => 1, 'values' => ['new-ca:0' => 15]]],
        ]));
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $this->assertFalse($res->getData(true)['can_edit_columns']);
        $this->assertSame(['1st CA', '2nd CA'], DB::table('continuous_assessments')->orderBy('id')->pluck('name')->all());
        $this->assertEquals(20, DB::table('continuous_assessments')->where('name', '1st CA')->value('total_marks'));
        $this->assertSame(403, $c->copyColumns($this->req('POST', $ctx))->getStatusCode());
    }
}
