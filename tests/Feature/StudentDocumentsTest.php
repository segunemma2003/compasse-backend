<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Support\StudentDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Transcript and letter of recommendation for any student. */
class StudentDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'schools' => fn ($t) => [$t->id(), $t->string('name'), $t->string('logo')->nullable(), $t->string('address')->nullable(), $t->string('phone')->nullable(), $t->string('email')->nullable(), $t->string('website')->nullable(), $t->json('settings')->nullable(), $t->timestamps()],
            'classes' => fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()],
            'arms' => fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()],
            'students' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->unsignedBigInteger('user_id')->nullable(), $t->unsignedBigInteger('class_id')->nullable(), $t->unsignedBigInteger('arm_id')->nullable(), $t->string('first_name'), $t->string('last_name'), $t->string('middle_name')->nullable(), $t->string('admission_number')->nullable(), $t->date('date_of_birth')->nullable(), $t->string('gender')->nullable(), $t->date('admission_date')->nullable(), $t->string('status')->nullable(), $t->timestamps(), $t->softDeletes()],
            'academic_years' => fn ($t) => [$t->id(), $t->string('name'), $t->date('start_date')->nullable(), $t->timestamps()],
            'terms' => fn ($t) => [$t->id(), $t->unsignedBigInteger('academic_year_id')->nullable(), $t->string('name'), $t->date('start_date')->nullable(), $t->date('end_date')->nullable(), $t->timestamps()],
            'subjects' => fn ($t) => [$t->id(), $t->unsignedBigInteger('class_id')->nullable(), $t->string('name'), $t->timestamps()],
            'student_results' => fn ($t) => [$t->id(), $t->unsignedBigInteger('student_id'), $t->unsignedBigInteger('class_id')->nullable(), $t->unsignedBigInteger('term_id'), $t->unsignedBigInteger('academic_year_id'), $t->string('result_type')->default('end_term'), $t->decimal('total_score', 8, 2)->default(0), $t->decimal('average_score', 8, 2)->default(0), $t->string('grade')->nullable(), $t->integer('position')->nullable(), $t->integer('out_of')->nullable(), $t->string('status')->default('draft'), $t->timestamps()],
            'subject_results' => fn ($t) => [$t->id(), $t->unsignedBigInteger('student_result_id'), $t->unsignedBigInteger('subject_id'), $t->decimal('ca_total', 8, 2)->nullable(), $t->decimal('exam_score', 8, 2)->nullable(), $t->decimal('total_score', 8, 2)->nullable(), $t->string('grade')->nullable(), $t->timestamps()],
            'school_signatures' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id'), $t->unsignedBigInteger('teacher_id')->nullable(), $t->string('role'), $t->string('name')->nullable(), $t->string('signature_path')->nullable(), $t->boolean('active')->default(true), $t->timestamps()],
        ] as $n => $d) {
            Schema::dropIfExists($n);
            Schema::create($n, $d);
        }
        DB::table('schools')->insert(['id' => 1, 'name' => 'The Quiver School']);
        DB::table('classes')->insert(['id' => 1, 'name' => 'YEAR TWO']);
        DB::table('arms')->insert(['id' => 1, 'name' => 'GOLD']);
        DB::table('students')->insert(['id' => 5, 'school_id' => 1, 'class_id' => 1, 'arm_id' => 1, 'first_name' => 'Ada', 'last_name' => 'Okafor', 'admission_number' => 'TQS/2024/005', 'admission_date' => '2024-09-01']);
        DB::table('academic_years')->insert(['id' => 1, 'name' => '2025/2026', 'start_date' => '2025-09-01']);
        DB::table('terms')->insert([['id' => 1, 'academic_year_id' => 1, 'name' => 'First Term', 'start_date' => '2025-09-01'], ['id' => 2, 'academic_year_id' => 1, 'name' => 'Second Term', 'start_date' => '2026-01-05']]);
        DB::table('subjects')->insert([['id' => 1, 'class_id' => 1, 'name' => 'Mathematics'], ['id' => 2, 'class_id' => 1, 'name' => 'English']]);
        foreach ([[1, 70, 'published'], [2, 80, 'draft']] as [$term, $avg, $status]) {
            $rid = DB::table('student_results')->insertGetId(['student_id' => 5, 'class_id' => 1, 'term_id' => $term, 'academic_year_id' => 1, 'average_score' => $avg, 'grade' => 'B', 'position' => 3, 'out_of' => 24, 'status' => $status]);
            DB::table('subject_results')->insert([
                ['student_result_id' => $rid, 'subject_id' => 1, 'ca_total' => 30, 'exam_score' => $avg - 30, 'total_score' => $avg, 'grade' => 'B'],
                ['student_result_id' => $rid, 'subject_id' => 2, 'ca_total' => 35, 'exam_score' => $avg - 35, 'total_score' => $avg, 'grade' => 'B'],
            ]);
        }
    }

    public function test_transcript_lists_every_term_by_session(): void
    {
        $html = StudentDocuments::transcriptHtml(Student::find(5));
        $this->assertStringContainsString('ACADEMIC TRANSCRIPT', $html);
        $this->assertStringContainsString('TQS/2024/005', $html);
        $this->assertStringContainsString('YEAR TWO GOLD', $html);
        $this->assertStringContainsString('2025/2026', $html);
        $this->assertLessThan(strpos($html, 'Second Term'), strpos($html, 'First Term'), 'terms in order');
        $this->assertStringContainsString('Mathematics', $html);
        $this->assertStringContainsString('Session average: 75%', $html);
        $this->assertStringContainsString('Position 3 of 24', $html);

        $published = StudentDocuments::transcriptHtml(Student::find(5), true);
        $this->assertStringNotContainsString('Second Term', $published, 'only published results when asked');
    }

    public function test_recommendation_letter_fills_in_the_student(): void
    {
        $html = StudentDocuments::recommendationHtml(Student::find(5), null, "The Admissions Officer\nXYZ College");
        $this->assertStringContainsString('LETTER OF RECOMMENDATION', $html);
        $this->assertStringContainsString('RE: ADA OKAFOR', $html);
        $this->assertStringContainsString('Ada Okafor (Admission No. TQS/2024/005) has been a student of The Quiver School since September 2024, and is currently in YEAR TWO GOLD', $html);
        $this->assertStringContainsString('XYZ College', $html);
        $this->assertStringNotContainsString('{first_name}', $html);

        $custom = StudentDocuments::recommendationHtml(Student::find(5), '{first_name} was our head girl.');
        $this->assertStringContainsString('Ada was our head girl.', $custom);
    }
}
