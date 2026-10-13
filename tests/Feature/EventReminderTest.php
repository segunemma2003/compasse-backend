<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Models\Event;
use App\Models\EventReminder;
use App\Models\School;
use App\Services\EventReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Event reminders reach the right people once each; event dates serialise as plain dates. */
class EventReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('users');
        Schema::create('users', function ($t) {
            $t->id(); $t->string('name'); $t->string('email')->unique(); $t->string('password'); $t->string('role'); $t->timestamps(); $t->softDeletes();
        });
        foreach (['students', 'teachers', 'guardians'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, function ($t) {
                $t->id(); $t->unsignedBigInteger('user_id')->nullable(); $t->unsignedBigInteger('class_id')->nullable();
                $t->string('first_name'); $t->string('last_name'); $t->string('email')->nullable(); $t->string('status')->nullable();
                $t->timestamps(); $t->softDeletes();
            });
        }
        Schema::dropIfExists('guardian_students');
        Schema::create('guardian_students', fn ($t) => [$t->id(), $t->unsignedBigInteger('guardian_id'), $t->unsignedBigInteger('student_id'), $t->timestamps()]);
        Schema::dropIfExists('notifications');
        Schema::create('notifications', function ($t) {
            $t->id(); $t->unsignedBigInteger('user_id')->nullable(); $t->string('title')->nullable(); $t->text('message')->nullable();
            $t->string('type')->nullable(); $t->boolean('is_read')->default(false); $t->timestamps();
        });
        Schema::dropIfExists('events');
        Schema::create('events', function ($t) {
            $t->id(); $t->unsignedBigInteger('school_id')->nullable(); $t->string('title'); $t->text('description')->nullable();
            $t->string('event_type')->nullable(); $t->date('start_date'); $t->date('end_date')->nullable(); $t->string('start_time')->nullable();
            $t->string('end_time')->nullable(); $t->string('location')->nullable(); $t->string('target_audience')->nullable();
            $t->boolean('is_all_day')->default(false); $t->string('status')->nullable(); $t->timestamps();
        });
        (require database_path('migrations/tenant/2026_10_13_000004_create_event_reminders_table.php'))->up();

        DB::table('tenants')->insert(['id' => 't', 'created_at' => now(), 'updated_at' => now()]);
        School::create(['tenant_id' => 't', 'name' => 'Demo School']);

        DB::table('users')->insert([
            ['id' => 10, 'name' => 'Grace', 'email' => 'grace@example.com', 'password' => 'x', 'role' => 'guardian'],
            ['id' => 11, 'name' => 'Tunde', 'email' => 'tunde@example.com', 'password' => 'x', 'role' => 'guardian'],
        ]);
        DB::table('students')->insert([
            ['id' => 1, 'class_id' => 5, 'first_name' => 'Ada', 'last_name' => 'O', 'email' => 'ada1@demo.compasse.net', 'status' => 'active'],
            ['id' => 2, 'class_id' => 6, 'first_name' => 'Ben', 'last_name' => 'E', 'email' => null, 'status' => 'active'],
        ]);
        DB::table('guardians')->insert([
            ['id' => 1, 'user_id' => 10, 'first_name' => 'Grace', 'last_name' => 'O', 'email' => 'grace@example.com'],
            ['id' => 2, 'user_id' => 11, 'first_name' => 'Tunde', 'last_name' => 'E', 'email' => 'tunde@example.com'],
        ]);
        DB::table('guardian_students')->insert([['guardian_id' => 1, 'student_id' => 1], ['guardian_id' => 2, 'student_id' => 2]]);
    }

    public function test_class_parents_get_one_email_and_a_notification_each(): void
    {
        Queue::fake();
        $event = Event::create(['title' => 'Sports Day', 'start_date' => '2026-10-20', 'is_all_day' => true, 'location' => 'Main field']);
        $reminder = EventReminder::create(['event_id' => $event->id, 'audience' => 'class_parents', 'class_id' => 5,
            'subject' => 'Reminder: Sports Day', 'message' => 'See you there', 'status' => 'pending']);

        $count = app(EventReminderService::class)->send($reminder);

        $this->assertSame(1, $count, 'only the class 5 parent');
        Queue::assertPushed(SendEmailJob::class, 1);
        Queue::assertPushed(SendEmailJob::class, fn ($j) => $j->to === 'grace@example.com');
        $this->assertSame(1, DB::table('notifications')->where('user_id', 10)->count());
        $this->assertSame('sent', $reminder->fresh()->status);
    }

    public function test_everyone_skips_made_up_addresses_and_duplicates(): void
    {
        $people = app(EventReminderService::class)->recipients('everyone');

        $this->assertSame(4, $people->count(), 'two parents + two students, each once');
        $emails = $people->pluck('email')->filter(fn ($e) => \App\Support\EmailAddress::isReal($e))->values()->all();
        $this->assertSame(['grace@example.com', 'tunde@example.com'], $emails, 'the generated student address is not emailed');
    }

    public function test_event_dates_serialise_as_plain_dates_and_draft_mentions_the_day(): void
    {
        $event = Event::create(['title' => 'Sports Day', 'start_date' => '2026-10-20', 'is_all_day' => true, 'location' => 'Main field']);

        $this->assertSame('2026-10-20', $event->fresh()->toArray()['start_date']);
        $draft = EventReminderService::defaultMessage($event->fresh());
        $this->assertSame('Reminder: Sports Day', $draft['subject']);
        $this->assertStringContainsString('Tuesday, 20 October 2026 at Main field', $draft['message']);
    }
}
