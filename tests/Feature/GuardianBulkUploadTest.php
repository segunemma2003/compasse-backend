<?php

namespace Tests\Feature;

use App\Jobs\ProcessBulkUploadJob;
use App\Jobs\SendEmailJob;
use App\Models\BulkUpload;
use App\Models\Guardian;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Parents can be bulk-uploaded: each row links children by admission number,
 * gets its own login (emailed), and a parent already on file is skipped but
 * still gets any new children linked.
 */
class GuardianBulkUploadTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('users');
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role')->default('guardian');
            $t->string('status')->default('active');
            $t->timestamp('email_verified_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        foreach (['students', 'teachers'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, function ($t) {
                $t->id();
                $t->unsignedBigInteger('school_id')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('first_name')->nullable();
                $t->string('last_name')->nullable();
                $t->string('email')->nullable();
                $t->string('admission_number')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        Schema::dropIfExists('staff');
        Schema::create('staff', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::dropIfExists('guardians');
        Schema::create('guardians', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('first_name');
            $t->string('last_name');
            $t->string('middle_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('address')->nullable();
            $t->string('occupation')->nullable();
            $t->string('relationship_to_student')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });
        Schema::dropIfExists('guardian_students');
        Schema::create('guardian_students', function ($t) {
            $t->id();
            $t->unsignedBigInteger('guardian_id');
            $t->unsignedBigInteger('student_id');
            $t->string('relationship')->nullable();
            $t->boolean('is_primary')->default(false);
            $t->boolean('emergency_contact')->default(false);
            $t->timestamps();
        });
        Schema::dropIfExists('bulk_uploads');
        Schema::create('bulk_uploads', function ($t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('user_id');
            $t->string('type');
            $t->string('status')->default('pending');
            $t->string('file_path');
            $t->string('file_name');
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('processed_rows')->default(0);
            $t->unsignedInteger('success_rows')->default(0);
            $t->unsignedInteger('failed_rows')->default(0);
            $t->unsignedInteger('skipped_rows')->default(0);
            $t->json('errors')->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });

        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->school = School::create(['tenant_id' => 'test-tenant', 'name' => 'Demo School', 'website' => 'demo.example.com']);
        DB::table('students')->insert([
            ['id' => 1, 'first_name' => 'Ada', 'last_name' => 'Okafor', 'admission_number' => 'ADM001', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'first_name' => 'Ben', 'last_name' => 'Okafor', 'admission_number' => 'ADM002', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'first_name' => 'Chi', 'last_name' => 'Eze', 'admission_number' => 'ADM003', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function runUpload(string $csv): BulkUpload
    {
        Storage::disk('local')->put('bulk-uploads/test/parents.csv', $csv);
        $upload = BulkUpload::create([
            'school_id' => $this->school->id, 'user_id' => 1, 'type' => 'guardians', 'status' => 'processing',
            'file_path' => 'bulk-uploads/test/parents.csv', 'file_name' => 'parents.csv',
        ]);
        $m = new ReflectionMethod(ProcessBulkUploadJob::class, 'processGuardians');
        $m->setAccessible(true);
        $m->invoke(new ProcessBulkUploadJob($upload->id), $upload);

        return $upload->fresh();
    }

    public function test_parents_are_created_linked_to_children_and_emailed(): void
    {
        Queue::fake();

        $upload = $this->runUpload(
            "FIRST NAME,LAST NAME,EMAIL,PHONE,RELATIONSHIP,CHILDREN\n"
            . "Grace,Okafor,grace@example.com,0801,Mother,ADM001; ADM002\n"
            . "Tunde,Eze,,0802,Father,ADM003\n"
            . "Bad,Row,bad@example.com,0803,Father,ADM999\n"
        );

        $this->assertSame(2, $upload->success_rows);
        $this->assertSame(1, $upload->failed_rows);
        $this->assertStringContainsString('ADM999', collect($upload->errors)->firstWhere('row', 4)['error']);

        $grace = Guardian::where('email', 'grace@example.com')->first();
        $this->assertSame(['ADM001', 'ADM002'], $grace->students()->orderBy('admission_number')->pluck('admission_number')->all());
        $this->assertSame('Mother', $grace->students()->first()->pivot->relationship);
        $this->assertSame('guardian', User::find($grace->user_id)->role);

        Queue::assertPushed(SendEmailJob::class, 1); // only Grace gave a real email
        Queue::assertPushed(SendEmailJob::class, fn ($job) => $job->to === 'grace@example.com' && str_contains($job->subject, 'Parent'));
    }

    public function test_an_existing_parent_is_skipped_but_new_children_are_linked(): void
    {
        Queue::fake();
        $this->runUpload("first_name,last_name,email,children\nGrace,Okafor,grace@example.com,ADM001\n");

        $upload = $this->runUpload("first_name,last_name,email,children\nGrace,Okafor,grace@example.com,ADM001; ADM003\n");

        $this->assertSame(0, $upload->failed_rows);
        $this->assertSame(1, $upload->skipped_rows);
        $this->assertStringContainsString('linked 1 new child', $upload->errors[0]['error']);
        $this->assertSame(1, Guardian::count());
        $this->assertSame(2, Guardian::first()->students()->count());
    }

    public function test_deleting_a_parent_removes_their_login_and_unlinks_children(): void
    {
        Queue::fake();
        $this->runUpload("first_name,last_name,email,children\nGrace,Okafor,grace@example.com,ADM001\n");
        $grace = Guardian::first();

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'role' => 'school_admin']);
        $request = \Illuminate\Http\Request::create('/', 'DELETE');
        $request->setUserResolver(fn () => $admin);
        $this->actingAs($admin);

        $res = app(\App\Http\Controllers\GuardianController::class)->destroy($request, $grace);

        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $this->assertSame(0, Guardian::count());
        $this->assertSame(0, DB::table('guardian_students')->count());
        $this->assertSoftDeleted('users', ['email' => 'grace@example.com']);
        $this->assertSame(3, DB::table('students')->count(), 'the children themselves are kept');
    }
}
