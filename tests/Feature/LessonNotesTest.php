<?php

namespace Tests\Feature;

use App\Http\Controllers\LessonNoteController;
use App\Http\Controllers\StudentNoteController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Lesson notes (written / AI-drafted with the school's key, vetted) and students' private notes. */
class LessonNotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $tables = [
            'users' => fn ($t) => [$t->id(), $t->string('name'), $t->string('email'), $t->string('password'), $t->string('role'), $t->timestamps(), $t->softDeletes()],
            'schools' => fn ($t) => [$t->id(), $t->string('name')->nullable(), $t->timestamps()],
            'settings' => fn ($t) => [$t->id(), $t->unsignedBigInteger('school_id')->nullable(), $t->string('category')->nullable(), $t->string('key'), $t->text('value')->nullable(), $t->timestamps()],
            'classes' => fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()],
            'subjects' => fn ($t) => [$t->id(), $t->string('name'), $t->unsignedBigInteger('department_id')->nullable(), $t->timestamps()],
            'departments' => fn ($t) => [$t->id(), $t->string('name'), $t->unsignedBigInteger('head_id')->nullable(), $t->timestamps()],
            'teachers' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id'), $t->unsignedBigInteger('department_id')->nullable(), $t->timestamps()],
            'students' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->unsignedBigInteger('class_id')->nullable(), $t->timestamps()],
            'terms' => fn ($t) => [$t->id(), $t->string('name'), $t->boolean('is_current')->default(false), $t->timestamps()],
            'academic_years' => fn ($t) => [$t->id(), $t->string('name'), $t->boolean('is_current')->default(false), $t->timestamps()],
            'notifications' => fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id'), $t->string('title'), $t->text('message')->nullable(), $t->string('type')->nullable(), $t->boolean('is_read')->default(false), $t->timestamps()],
        ];
        foreach ($tables as $n => $def) {
            Schema::dropIfExists($n);
            Schema::create($n, $def);
        }
        (require database_path('migrations/tenant/2026_10_23_000001_create_lesson_notes.php'))->up();
        (require database_path('migrations/tenant/2026_10_23_000002_create_student_notes.php'))->up();
        (require database_path('migrations/tenant/2026_10_24_000001_duty_roster_and_lesson_note_vetting.php'))->up();
        DB::table('schools')->insert(['id' => 1, 'name' => 'Quiver']);
        DB::table('classes')->insert([['id' => 1, 'name' => 'Primary 4'], ['id' => 2, 'name' => 'Primary 5']]);
        DB::table('subjects')->insert(['id' => 1, 'name' => 'Basic Science', 'department_id' => 1]);
        foreach ([[1, 'school_admin'], [2, 'teacher'], [3, 'teacher'], [4, 'student'], [5, 'student'], [6, 'teacher']] as [$id, $role]) {
            User::forceCreate(['id' => $id, 'name' => "U{$id}", 'email' => "u{$id}@x.test", 'password' => 'x', 'role' => $role]);
        }
        // Teacher 6 heads the Science department, which teaches Basic Science.
        DB::table('teachers')->insert([['id' => 1, 'user_id' => 2, 'department_id' => 1], ['id' => 2, 'user_id' => 3, 'department_id' => null], ['id' => 3, 'user_id' => 6, 'department_id' => 1]]);
        DB::table('departments')->insert(['id' => 1, 'name' => 'Science', 'head_id' => 3]);
        DB::table('students')->insert([['user_id' => 4, 'class_id' => 1], ['user_id' => 5, 'class_id' => 2]]);
    }

    private function req(int $uid, string $method = 'GET', array $data = []): Request
    {
        $u = User::find($uid);
        $this->actingAs($u);
        $r = Request::create('/', $method, $data);
        $r->setUserResolver(fn () => $u);

        return $r;
    }

    public function test_ai_key_drafting_and_vetting(): void
    {
        $c = new LessonNoteController();
        $this->assertSame(422, $c->generate($this->req(2, 'POST', ['topic' => 'Living things']))->getStatusCode(), 'no key yet');

        $this->assertSame(403, $c->saveAiSettings($this->req(2, 'PUT', ['provider' => 'anthropic', 'api_key' => 'sk-ant-secret-1234']))->getStatusCode());
        $saved = $c->saveAiSettings($this->req(1, 'PUT', ['provider' => 'anthropic', 'api_key' => 'sk-ant-secret-1234']))->getData(true);
        $this->assertTrue($saved['has_key']);
        $this->assertStringNotContainsString('secret', json_encode($saved), 'the key never goes back to the browser');
        $this->assertStringNotContainsString('secret', (string) DB::table('settings')->where('key', 'ai_api_key')->value('value'), 'stored encrypted');

        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => "```json\n" . json_encode([
            'behavioural_objectives' => ['Name five living things'], 'instructional_materials' => ['Chart'], 'reference_materials' => ['Basic Science for Primary 4'],
            'previous_knowledge' => 'Pupils have seen animals.', 'set_induction' => 'Show a plant.', 'presentation' => [['step' => 'Step 1', 'teacher_activity' => 'Explains', 'pupils_activity' => 'Listen', 'learning_point' => 'Living things grow']],
            'evaluation' => ['Name two living things'], 'summary' => 'Living things grow.', 'assignment' => ['List five living things at home'],
        ]) . "\n```"]]])]);
        $draft = $c->generate($this->req(2, 'POST', ['topic' => 'Living things', 'class_id' => 1, 'subject_id' => 1]))->getData(true);
        $this->assertSame(['Name five living things'], $draft['content']['behavioural_objectives']);
        Http::assertSent(fn ($r) => $r->hasHeader('x-api-key', 'sk-ant-secret-1234') && str_contains($r['messages'][0]['content'], 'Primary 4'));

        $id = $c->store($this->req(2, 'POST', ['topic' => 'Living things', 'class_id' => 1, 'subject_id' => 1, 'content' => $draft['content'], 'ai_generated' => true]))->getData(true)['id'];
        $this->assertCount(0, $c->index($this->req(3))->getData(true)['notes'], "another teacher's note");
        $this->assertSame(6, $c->reviewers($this->req(2, 'GET', ['subject_id' => 1]))->getData(true)['suggested'], 'the HOD of the subject');
        $c->submit($this->req(2, 'POST'), $id);
        $this->assertSame(6, (int) DB::table('lesson_notes')->where('id', $id)->value('reviewer_id'));
        $this->assertTrue(DB::table('notifications')->where('user_id', 6)->where('title', 'Lesson note to vet')->exists());
        $this->assertSame(1, $c->index($this->req(6, 'GET', ['to_vet' => 1]))->getData(true)['to_vet']);
        $this->assertSame(403, $c->update($this->req(2, 'PUT', ['topic' => 'x']), $id)->getStatusCode(), 'locked while submitted');
        $this->assertSame(403, $c->review($this->req(3, 'POST', ['decision' => 'approve']), $id)->getStatusCode(), 'not sent to this teacher');
        $c->review($this->req(6, 'POST', ['decision' => 'return', 'comment' => 'Add a practical']), $id);
        $this->assertSame(200, $c->update($this->req(2, 'PUT', ['topic' => 'Living and non-living things']), $id)->getStatusCode(), 'editable once returned');
        $this->assertStringContainsString('Living and non-living things', $c->print($this->req(2), $id)->getContent());

        // Line manager overrides the HOD.
        $c->setLineManager($this->req(1, 'PUT', ['teacher_id' => 1, 'line_manager_id' => 3]));
        $this->assertSame(3, LessonNoteController::suggestedReviewer(2, 1));

        // Sharing: only once approved; scheduled notes stay hidden until their time; only that class.
        $this->assertSame(422, $c->share($this->req(2, 'POST', ['class_id' => 1]), $id)->getStatusCode());
        $c->submit($this->req(2, 'POST'), $id);
        $c->review($this->req(3, 'POST', ['decision' => 'approve']), $id);
        $c->share($this->req(2, 'POST', ['class_id' => 1, 'share_at' => now()->addDay()->toDateTimeString()]), $id);
        $this->assertCount(0, $c->shared($this->req(4))->getData(true)['notes'], 'scheduled for tomorrow');
        $c->share($this->req(2, 'POST', ['class_id' => 1]), $id);
        $this->assertCount(1, $c->shared($this->req(4))->getData(true)['notes']);
        $this->assertCount(0, $c->shared($this->req(5))->getData(true)['notes'], 'another class');
        $this->assertSame(200, $c->print($this->req(4), $id)->getStatusCode());
        $this->assertSame(404, $c->print($this->req(5), $id)->getStatusCode());
        $this->assertSame(404, $c->show($this->req(4), $id)->getStatusCode(), 'students only get the shared view');
    }

    public function test_only_the_teacher_on_duty_clocks_in_other_staff(): void
    {
        $d = new \App\Http\Controllers\StaffDutyController();
        $teacher = User::find(3);
        $this->assertFalse(\App\Http\Controllers\StaffDutyController::canMarkStaff($teacher));
        $this->assertTrue(\App\Http\Controllers\StaffDutyController::canMarkStaff(User::find(1)), 'leadership');
        $this->assertSame(403, $d->store($this->req(2, 'POST', ['user_ids' => [3], 'week_start' => now()->toDateString()]))->getStatusCode());
        $d->store($this->req(1, 'POST', ['user_ids' => [3], 'week_start' => now()->toDateString()]));
        $this->assertTrue(\App\Http\Controllers\StaffDutyController::canMarkStaff($teacher), 'on duty this week');
        $this->assertFalse(\App\Http\Controllers\StaffDutyController::canMarkStaff($teacher, now()->addWeek()), 'not next week');
        $this->assertFalse(\App\Http\Controllers\StaffDutyController::canMarkStaff(User::find(2)));
        $res = $d->index($this->req(3))->getData(true);
        $this->assertTrue($res['can_mark_staff']);
        $this->assertSame('U3', $res['on_duty'][0]['name']);
    }

    public function test_student_notes_are_private(): void
    {
        $c = new StudentNoteController();
        $id = $c->store($this->req(4, 'POST', ['title' => 'Photosynthesis', 'body' => 'Plants make food', 'subject' => 'Biology']))->getData(true)['note']['id'];
        $this->assertCount(1, $c->index($this->req(4))->getData(true)['notes']);
        $this->assertCount(0, $c->index($this->req(5))->getData(true)['notes']);
        $this->assertSame(404, $c->update($this->req(5, 'PUT', ['title' => 'hack']), $id)->getStatusCode());
        $this->assertCount(1, $c->index($this->req(4, 'GET', ['q' => 'food']))->getData(true)['notes']);
    }
}
