<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\TermController;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression tests for the production bug fixed 2026-09-11:
 *   - A school could end up with two academic years (or two terms) both
 *     marked is_current=1 at once, because store()/update() never unset
 *     the flag on the others. Found live on tenant "christ" (two current
 *     academic years, one of which had ended six months earlier).
 *   - GET /terms returned every term ever created across every session,
 *     with no way to scope to just the current one.
 *
 * academic_years/terms are TENANT migrations (database/migrations/tenant),
 * which the default `artisan migrate` used by RefreshDatabase does not run.
 * Rather than fight the app's (currently nonexistent) tenant-aware test
 * bootstrapping, these tests declare the two tables directly — they only
 * need the columns AcademicYearController/TermController actually touch —
 * and call the controllers directly, matching how getSchoolFromRequest()
 * resolves the school (request attribute first, before any tenancy check),
 * so no HTTP kernel / tenant middleware / auth guard is needed to exercise
 * the exact logic that was buggy.
 */
class AcademicSessionExclusivityTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('academic_years', function ($table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_current')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('terms', function ($table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_current')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        // No SchoolFactory exists in this repo — create the rows directly.
        // schools.tenant_id has a foreign key to tenants.id. Insert via the
        // query builder, not Tenant::create() — the Tenant model fires real
        // provisioning (separate tenant DB + full tenant migration run,
        // queued but QUEUE_CONNECTION=sync in testing) on creation, which
        // has nothing to do with what's under test here and leaks state
        // across tests (its own sqlite file, outside this RefreshDatabase
        // transaction).
        DB::table('tenants')->insert(['id' => 'test-tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->school = School::create(['tenant_id' => 'test-tenant', 'name' => 'Test School']);

        // academic.manage is now required on these controllers' write
        // actions (see the sub-admin capability-gating change) —
        // school_admin always passes it (LEADERSHIP bypass), unrelated to
        // what's under test here.
        $this->actingAs(\App\Models\User::create([
            'tenant_id' => 'test-tenant',
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('secret'),
            'role' => 'school_admin',
        ]));
    }

    private function requestFor(array $payload): Request
    {
        $request = Request::create('/', 'POST', $payload);
        $request->attributes->set('school', $this->school);
        return $request;
    }

    public function test_marking_a_new_academic_year_current_unsets_the_previous_one(): void
    {
        $controller = new AcademicYearController();

        $controller->store($this->requestFor([
            'name' => '2025/2026', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31', 'is_current' => true,
        ]));
        $first = AcademicYear::where('name', '2025/2026')->first();
        $this->assertTrue((bool) $first->is_current);

        $controller->store($this->requestFor([
            'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-07-31', 'is_current' => true,
        ]));

        $this->assertSame(1, AcademicYear::where('is_current', true)->count());
        $this->assertFalse((bool) $first->fresh()->is_current);
        $this->assertTrue((bool) AcademicYear::where('name', '2026/2027')->first()->is_current);
    }

    public function test_reproduces_and_fixes_the_live_incident_two_years_marked_current(): void
    {
        // Exact shape of the tenant "christ" incident: two rows already both
        // is_current=1 in the database (e.g. from before this fix existed).
        $stale = AcademicYear::create([
            'school_id' => $this->school->id, 'name' => '2025/2026',
            'start_date' => '2025-03-12', 'end_date' => '2026-03-12', 'is_current' => true,
        ]);
        $active = AcademicYear::create([
            'school_id' => $this->school->id, 'name' => '2026-2027',
            'start_date' => '2026-09-01', 'end_date' => '2027-07-31', 'is_current' => true,
        ]);
        $this->assertSame(2, AcademicYear::where('is_current', true)->count(), 'sanity check: bad state is reproduced');

        // Re-confirming the active one as current (the normal admin action)
        // must clean up the pre-existing duplicate, not just leave it be.
        (new AcademicYearController())->update($this->requestFor(['is_current' => true]), $active);

        $this->assertSame(1, AcademicYear::where('is_current', true)->count());
        $this->assertFalse((bool) $stale->fresh()->is_current);
        $this->assertTrue((bool) $active->fresh()->is_current);
    }

    public function test_marking_a_new_term_current_unsets_the_previous_one(): void
    {
        $year = AcademicYear::create([
            'school_id' => $this->school->id, 'name' => '2026/2027',
            'start_date' => '2026-09-01', 'end_date' => '2027-07-31', 'is_current' => true,
        ]);
        $controller = new TermController();

        $controller->store($this->requestFor([
            'academic_year_id' => $year->id, 'name' => 'First Term',
            'start_date' => '2026-09-01', 'end_date' => '2026-12-15', 'is_current' => true,
        ]));
        $controller->store($this->requestFor([
            'academic_year_id' => $year->id, 'name' => 'Second Term',
            'start_date' => '2027-01-05', 'end_date' => '2027-04-01', 'is_current' => true,
        ]));

        $this->assertSame(1, Term::where('is_current', true)->count());
        $this->assertSame('Second Term', Term::where('is_current', true)->first()->name);
    }

    public function test_terms_index_defaults_to_the_current_academic_year_only(): void
    {
        $lastYear = AcademicYear::create([
            'school_id' => $this->school->id, 'name' => '2025/2026',
            'start_date' => '2025-09-01', 'end_date' => '2026-07-31', 'is_current' => false,
        ]);
        $thisYear = AcademicYear::create([
            'school_id' => $this->school->id, 'name' => '2026/2027',
            'start_date' => '2026-09-01', 'end_date' => '2027-07-31', 'is_current' => true,
        ]);
        Term::create(['school_id' => $this->school->id, 'academic_year_id' => $lastYear->id, 'name' => 'Old Term', 'start_date' => '2025-09-01', 'end_date' => '2025-12-01']);
        Term::create(['school_id' => $this->school->id, 'academic_year_id' => $thisYear->id, 'name' => 'Current Term', 'start_date' => '2026-09-01', 'end_date' => '2026-12-01']);

        // No filter: previously returned both terms from both sessions.
        $response = (new TermController())->index(Request::create('/terms', 'GET'));
        $names = collect($response->getData(true)['data'])->pluck('name');
        $this->assertSame(['Current Term'], $names->all());

        // Explicit ?academic_year_id= still reaches into a non-current year.
        $response = (new TermController())->index(Request::create('/terms', 'GET', ['academic_year_id' => $lastYear->id]));
        $names = collect($response->getData(true)['data'])->pluck('name');
        $this->assertSame(['Old Term'], $names->all());

        // ?all=1 is the explicit escape hatch back to "everything".
        $response = (new TermController())->index(Request::create('/terms', 'GET', ['all' => 1]));
        $this->assertCount(2, $response->getData(true)['data']);
    }

    public function test_reproduces_and_fixes_the_live_incident_current_term_from_a_different_year_than_current_year(): void
    {
        // Exact shape found live on 2 of 7 tenants: the school switched its
        // current year at some point but never touched terms afterwards, so
        // the old year's term was still flagged current.
        $oldYear = AcademicYear::create([
            'school_id' => $this->school->id, 'name' => '2026-2027',
            'start_date' => '2026-09-01', 'end_date' => '2027-07-31', 'is_current' => false,
        ]);
        $staleTerm = Term::create([
            'school_id' => $this->school->id, 'academic_year_id' => $oldYear->id, 'name' => '1st Term',
            'start_date' => '2026-09-01', 'end_date' => '2026-12-15', 'is_current' => true,
        ]);
        $newYear = AcademicYear::create([
            'school_id' => $this->school->id, 'name' => '2027/2028',
            'start_date' => '2027-09-01', 'end_date' => '2028-07-31', 'is_current' => true,
        ]);
        $this->assertSame($oldYear->id, $staleTerm->fresh()->academic_year_id, 'sanity check: bad state is reproduced — current term belongs to a non-current year');

        // Re-confirming the new year as current (the normal admin action)
        // must clear the now-contradictory current term, not leave it
        // pointing at a year that is no longer current.
        (new AcademicYearController())->update($this->requestFor(['is_current' => true]), $newYear);

        $this->assertFalse((bool) $staleTerm->fresh()->is_current);
        $this->assertSame(0, Term::where('is_current', true)->count(), 'no term should be left claiming to be current for the wrong year');
    }

    public function test_marking_a_term_current_promotes_its_own_year_to_current(): void
    {
        $oldYear = AcademicYear::create([
            'school_id' => $this->school->id, 'name' => '2026-2027',
            'start_date' => '2026-09-01', 'end_date' => '2027-07-31', 'is_current' => true,
        ]);
        $newYear = AcademicYear::create([
            'school_id' => $this->school->id, 'name' => '2027/2028',
            'start_date' => '2027-09-01', 'end_date' => '2028-07-31', 'is_current' => false,
        ]);
        $term = Term::create([
            'school_id' => $this->school->id, 'academic_year_id' => $newYear->id, 'name' => 'First Term',
            'start_date' => '2027-09-01', 'end_date' => '2027-12-15', 'is_current' => false,
        ]);

        // Admin picks a term from the not-yet-current year — intent is
        // clearly "this session is live now", not a contradiction to reject.
        (new TermController())->update($this->requestFor(['is_current' => true]), $term);

        $this->assertTrue((bool) $newYear->fresh()->is_current);
        $this->assertFalse((bool) $oldYear->fresh()->is_current);
    }
}
