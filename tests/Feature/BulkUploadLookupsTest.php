<?php

namespace Tests\Feature;

use App\Support\BulkUploadLookups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Bulk upload templates used to ask for class_id / arm_id / department_id /
 * subject_id / exam_id — database ids an admin has no way to look up. They
 * now take the names shown in the app; these cover how a typed name is
 * matched, and that old numeric ids still work.
 */
class BulkUploadLookupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['classes', 'arms', 'departments'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, function ($t) {
                $t->id();
                $t->string('name');
            });
        }
        Schema::dropIfExists('class_arm');
        Schema::create('class_arm', function ($t) {
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('arm_id');
        });
        Schema::dropIfExists('subjects');
        Schema::create('subjects', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('code')->nullable();
            $t->unsignedBigInteger('class_id')->nullable();
        });
        Schema::dropIfExists('exams');
        Schema::create('exams', function ($t) {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->unsignedBigInteger('term_id')->nullable();
        });

        DB::table('classes')->insert([['id' => 1, 'name' => 'JSS 1'], ['id' => 2, 'name' => 'JSS 2']]);
        DB::table('arms')->insert([['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'Gold'], ['id' => 3, 'name' => 'A']]);
        DB::table('class_arm')->insert([['class_id' => 1, 'arm_id' => 1], ['class_id' => 2, 'arm_id' => 3]]);
        DB::table('departments')->insert([['id' => 7, 'name' => 'Sciences']]);
        DB::table('subjects')->insert([
            ['id' => 10, 'name' => 'Mathematics', 'code' => 'MTH1', 'class_id' => 1],
            ['id' => 11, 'name' => 'Mathematics', 'code' => 'MTH2', 'class_id' => 2],
        ]);
        DB::table('exams')->insert([
            ['id' => 20, 'name' => 'First Term Examination', 'subject_id' => 11, 'class_id' => 2, 'term_id' => 1],
        ]);
    }

    public function test_names_match_regardless_of_case_and_spacing(): void
    {
        $l = new BulkUploadLookups();

        $this->assertSame(1, $l->classId('jss1'));
        $this->assertSame(2, $l->classId(' JSS-2 '));
        $this->assertSame(7, $l->departmentId('sciences'));
        $this->assertNull($l->classId(''));
        $this->assertNull($l->departmentId(null));
    }

    public function test_old_numeric_ids_still_work(): void
    {
        $l = new BulkUploadLookups();

        $this->assertSame(2, $l->classId('2'));
        $this->assertSame(7, $l->departmentId('7'));
    }

    public function test_arm_and_subject_names_resolve_within_the_class(): void
    {
        $l = new BulkUploadLookups();

        // Two arms are called "A"; JSS 2 is linked to the second.
        $this->assertSame(3, $l->armId('A', 2));
        // "Mathematics" exists per class; the student's class picks the row.
        $this->assertSame(11, $l->subjectId('Mathematics', 2));
        $this->assertSame(10, $l->subjectId('mth1', null));
    }

    public function test_exam_is_found_by_name_within_class_and_term(): void
    {
        $l = new BulkUploadLookups();

        $this->assertSame(20, $l->exam('first term examination', 2, 1)->id);
        $this->assertNull($l->exam('', 2, 1));

        $this->expectExceptionMessage('Exam "First Term Examination" not found');
        $l->exam('First Term Examination', 1, 1);
    }

    public function test_unknown_name_lists_the_valid_ones(): void
    {
        $this->expectExceptionMessage('Class "SS 3" not found. Use one of: JSS 1, JSS 2');

        (new BulkUploadLookups())->classId('SS 3');
    }

    public function test_options_list_the_schools_names_per_column(): void
    {
        $l = new BulkUploadLookups();

        $this->assertSame(['class' => ['JSS 1', 'JSS 2'], 'arm' => ['A', 'Gold']], $l->options('students'));
        $this->assertSame(['department' => ['Sciences']], $l->options('teachers'));
    }
}
