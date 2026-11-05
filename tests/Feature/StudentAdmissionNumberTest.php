<?php

namespace Tests\Feature;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Enrolling a student failed with a server error at The Quiver School until an admission number was
 * typed by hand: the generated one restarted at 001 (it read "E001" off "THE2026YE001" as 0) and
 * ignored deleted students, whose numbers the database still reserves.
 */
class StudentAdmissionNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['students', 'classes', 'schools'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('schools', fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()]);
        Schema::create('classes', fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()]);
        Schema::create('students', fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('admission_number')->unique(), $t->timestamps(), $t->softDeletes()]);

        DB::table('schools')->insert(['id' => 1, 'name' => 'The Quiver School']);
        DB::table('classes')->insert([['id' => 4, 'name' => 'TODDLER ONE'], ['id' => 1, 'name' => 'YEAR ONE']]);
        $year = date('Y');
        DB::table('students')->insert([
            ['school_id' => 1, 'admission_number' => "THE{$year}TO127", 'deleted_at' => null],
            ['school_id' => 1, 'admission_number' => "THE{$year}YE001", 'deleted_at' => null],
            ['school_id' => 1, 'admission_number' => "THE{$year}TO128", 'deleted_at' => now()],
            ['school_id' => 1, 'admission_number' => 'TQS/ADM/2026/249', 'deleted_at' => null],
        ]);
    }

    public function test_next_number_follows_the_highest_including_deleted_students(): void
    {
        $year = date('Y');
        $this->assertSame("THE{$year}TO129", Student::generateAdmissionNumber(1, 4));
        $this->assertSame("THE{$year}YE002", Student::generateAdmissionNumber(1, 1));
    }
}
