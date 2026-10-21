<?php

namespace Tests\Feature;

use App\Models\StudentResult;
use App\Support\ReportCardExtras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Grade summary, automatic comments, and the cumulative last-term result with promotion. */
class ReportCardExtrasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $tables = [
            'terms' => fn ($t) => [$t->id(), $t->unsignedBigInteger('academic_year_id'), $t->string('name'), $t->date('start_date')->nullable(), $t->date('end_date')->nullable(), $t->timestamps()],
            'students' => fn ($t) => [$t->id(), $t->string('first_name'), $t->string('last_name'), $t->unsignedBigInteger('class_id')->nullable(), $t->timestamps(), $t->softDeletes()],
            'subjects' => fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()],
            'student_results' => fn ($t) => [$t->id(), $t->unsignedBigInteger('student_id'), $t->unsignedBigInteger('class_id')->nullable(), $t->unsignedBigInteger('term_id'), $t->unsignedBigInteger('academic_year_id'), $t->string('result_type')->default('end_term'), $t->decimal('total_score', 8, 2)->nullable(), $t->decimal('average_score', 8, 2)->nullable(), $t->string('grade')->nullable(), $t->integer('position')->nullable(), $t->integer('out_of')->nullable(), $t->decimal('class_average', 8, 2)->nullable(), $t->text('class_teacher_comment')->nullable(), $t->text('principal_comment')->nullable(), $t->date('next_term_begins')->nullable(), $t->string('status')->nullable(), $t->timestamps()],
            'subject_results' => fn ($t) => [$t->id(), $t->unsignedBigInteger('student_result_id'), $t->unsignedBigInteger('subject_id'), $t->decimal('ca_total', 8, 2)->nullable(), $t->decimal('exam_score', 8, 2)->nullable(), $t->decimal('total_score', 8, 2)->nullable(), $t->string('grade')->nullable(), $t->string('teacher_remark')->nullable(), $t->integer('position')->nullable(), $t->decimal('highest_score', 8, 2)->nullable(), $t->decimal('lowest_score', 8, 2)->nullable(), $t->decimal('class_average', 8, 2)->nullable(), $t->timestamps()],
        ];
        foreach ($tables as $n => $def) {
            Schema::dropIfExists($n);
            Schema::create($n, $def);
        }
        (require database_path('migrations/tenant/2026_10_22_000004_create_result_remarks.php'))->up();
        DB::table('terms')->insert([
            ['id' => 1, 'academic_year_id' => 1, 'name' => 'First Term', 'start_date' => '2026-09-01'],
            ['id' => 2, 'academic_year_id' => 1, 'name' => 'Second Term', 'start_date' => '2027-01-05'],
            ['id' => 3, 'academic_year_id' => 1, 'name' => 'Third Term', 'start_date' => '2027-04-20'],
        ]);
        DB::table('students')->insert(['id' => 1, 'first_name' => 'Ada', 'last_name' => 'O']);
        DB::table('subjects')->insert([['id' => 1, 'name' => 'Maths'], ['id' => 2, 'name' => 'English']]);
    }

    private function makeResult(int $term, array $scores): StudentResult
    {
        $id = DB::table('student_results')->insertGetId(['student_id' => 1, 'term_id' => $term, 'academic_year_id' => 1, 'average_score' => array_sum($scores) / count($scores)]);
        foreach ($scores as $subject => $score) {
            DB::table('subject_results')->insert(['student_result_id' => $id, 'subject_id' => $subject, 'total_score' => $score]);
        }

        return StudentResult::with(['subjectResults.subject', 'term', 'student'])->find($id);
    }

    public function test_summary_comments_and_cumulative_promotion(): void
    {
        $this->makeResult(1, [1 => 70, 2 => 40]);
        // English missing in the second term: its annual average is over 2 terms.
        $this->makeResult(2, [1 => 80]);
        $third = $this->makeResult(3, [1 => 90, 2 => 50]);

        $waec = [['grade' => 'A1', 'min' => 75, 'remark' => 'Excellent'], ['grade' => 'C4', 'min' => 50, 'remark' => 'Credit'], ['grade' => 'F9', 'min' => 0, 'remark' => 'Fail']];
        $sum = ReportCardExtras::summary($third, $waec, 50);
        $this->assertSame(2, $sum['subjects']);
        $this->assertSame(1, $sum['bands']['Distinction']);
        $this->assertSame(1, $sum['bands']['Credit']);

        $s = ReportCardExtras::settings(null);
        $cum = ReportCardExtras::cumulative($third, $s, 50);
        $this->assertSame(['First Term', 'Second Term', 'Third Term'], $cum['term_names']);
        $maths = collect($cum['rows'])->firstWhere('subject', 'Maths');
        $english = collect($cum['rows'])->firstWhere('subject', 'English');
        $this->assertEquals(80.0, $maths['average'], '(70+80+90)/3');
        $this->assertEquals(45.0, $english['average'], '(40+50)/2 — taken in 2 terms');
        $this->assertEquals(62.5, $cum['annual_average']);
        $this->assertSame('Promoted', $cum['promotion']);
        $this->assertSame(1, $cum['failed']);

        $s['max_failed_subjects'] = 0;
        $this->assertSame('Promoted on trial', ReportCardExtras::cumulative($third, $s, 50)['promotion'], 'one subject failed, none allowed');
        $this->assertNull(ReportCardExtras::cumulative($this->makeResult(2, [1 => 60]), $s, 50), 'not the last term');

        // Automatic comments unless typed; Mark Sheet remarks are used too.
        $c = ReportCardExtras::comments($third, null, ReportCardExtras::settings(null), 82);
        $this->assertStringStartsWith('An excellent result. Ada', $c['principal_comment']);
        $this->assertStringContainsString('Ada', $c['class_teacher_comment']);
        DB::table('result_remarks')->insert(['student_id' => 1, 'term_id' => 3, 'academic_year_id' => 1, 'principal_comment' => 'Typed by the head.']);
        $this->assertSame('Typed by the head.', ReportCardExtras::comments($third, null, ReportCardExtras::settings(null), 82)['principal_comment']);
        $manual = array_merge(ReportCardExtras::settings(null), ['class_teacher_comment_mode' => 'manual']);
        $this->assertNull(ReportCardExtras::comments($third, null, $manual, 82)['class_teacher_comment']);
    }
}
