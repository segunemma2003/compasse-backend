<?php

namespace Tests\Feature;

use App\Support\FeeSplit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** One "School Fees" line on the invoice; Tuition, Lesson fees and PTA in the school's books. */
class FeeSplitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'settings' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('key'), $t->text('value')->nullable(), $t->timestamps()],
            'classes' => fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()],
            'students' => fn ($t) => [$t->id(), $t->unsignedBigInteger('class_id')->nullable(), $t->string('first_name'), $t->string('last_name'), $t->string('admission_number')->nullable(), $t->timestamps(), $t->softDeletes()],
            'fees' => fn ($t) => [$t->id(), $t->unsignedBigInteger('student_id'), $t->unsignedBigInteger('term_id')->nullable(), $t->unsignedBigInteger('academic_year_id')->nullable(), $t->string('fee_type')->nullable(), $t->string('description')->nullable(), $t->decimal('amount', 12, 2), $t->decimal('amount_paid', 12, 2)->default(0), $t->string('status')->default('pending'), $t->timestamps()],
            'fee_items' => fn ($t) => [$t->id(), $t->unsignedBigInteger('fee_id'), $t->string('name'), $t->decimal('amount', 12, 2), $t->timestamps()],
        ] as $n => $d) {
            Schema::dropIfExists($n);
            Schema::create($n, $d);
        }
        DB::table('classes')->insert(['id' => 1, 'name' => 'YEAR ONE']);
        DB::table('students')->insert([['id' => 1, 'class_id' => 1, 'first_name' => 'Ada', 'last_name' => 'Okafor', 'admission_number' => 'A1'],
            ['id' => 2, 'class_id' => 1, 'first_name' => 'Ben', 'last_name' => 'Eze', 'admission_number' => 'A2']]);
        // Ada: School Fees 400,000 + Swimming 20,000, paid 210,000. Ben: School Fees only (no lines), fully paid.
        DB::table('fees')->insert([
            ['id' => 1, 'student_id' => 1, 'term_id' => 1, 'fee_type' => 'School Fees', 'amount' => 420000, 'amount_paid' => 210000, 'status' => 'partial'],
            ['id' => 2, 'student_id' => 2, 'term_id' => 1, 'fee_type' => 'School Fees', 'amount' => 400000, 'amount_paid' => 400000, 'status' => 'paid'],
        ]);
        DB::table('fee_items')->insert([['fee_id' => 1, 'name' => 'School Fees Amt', 'amount' => 400000], ['fee_id' => 1, 'name' => 'Swimming', 'amount' => 20000]]);
        FeeSplit::save([['item' => 'School Fees', 'rest' => 'Tuition', 'parts' => [['name' => 'Lesson fees', 'amount' => 50000], ['name' => 'PTA', 'amount' => 10000]]]]);
    }

    public function test_one_invoice_line_is_split_into_its_parts_for_billed_paid_and_owing(): void
    {
        $r = FeeSplit::report(1);
        $this->assertSame(['Lesson fees', 'PTA', 'Tuition', 'Swimming'], $r['parts']);
        $ada = collect($r['rows'])->firstWhere('admission_number', 'A1');
        $this->assertEquals(['Lesson fees' => 50000, 'PTA' => 10000, 'Tuition' => 340000, 'Swimming' => 20000], $ada['billed']);
        // Half of the fee paid: half of every part.
        $this->assertEquals(['Lesson fees' => 25000, 'PTA' => 5000, 'Tuition' => 170000, 'Swimming' => 10000], $ada['paid']);
        $this->assertEquals(170000, $ada['owing']['Tuition']);

        $ben = collect($r['rows'])->firstWhere('admission_number', 'A2');
        $this->assertEquals(['Lesson fees' => 50000, 'PTA' => 10000, 'Tuition' => 340000], $ben['billed'], 'a fee with no lines is split too');
        $this->assertEquals(0, $ben['owing']['PTA']);
        $this->assertEquals(20000, $r['totals']['billed']['PTA']);
        $this->assertEquals(15000, $r['totals']['paid']['PTA']);

        $csv = FeeSplit::csv($r);
        $this->assertStringContainsString('"Lesson fees billed","Lesson fees paid","Lesson fees owing"', $csv);
        $this->assertStringContainsString('TOTAL', $csv);
    }

    public function test_percentages_and_lines_without_a_rule(): void
    {
        $this->assertSame(['PTA' => 20000.0, 'Tuition' => 380000.0], FeeSplit::splitLine('School Fees', 400000, FeeSplit::clean([['item' => 'school fees', 'parts' => [['name' => 'PTA', 'percent' => 5]]]])));
        $this->assertSame(['Bus' => 30000.0], FeeSplit::splitLine('Bus', 30000, FeeSplit::rules()));
        // Parts larger than the line stop at the line's amount.
        $this->assertSame(['Lesson fees' => 40000.0], FeeSplit::splitLine('School Fees', 40000, FeeSplit::rules()));
    }
}
