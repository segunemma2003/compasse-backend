<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the tables required by the Security and Driver dashboards:
 *   visitors, gate_passes, security_incidents, access_logs,
 *   transport_trips, transport_attendances
 *
 * Also adds the missing guardian_id index on guardian_students
 * (speeds up parent dashboard and guardian scoping queries).
 */
return new class extends Migration
{
    public function up(): void
    {
        // If a previous deploy failed while creating `access_logs`, these three tables may
        // exist without the rest of this migration. Drop them so we can run cleanly again.
        if (Schema::hasTable('visitors') && ! Schema::hasTable('access_logs')) {
            Schema::dropIfExists('security_incidents');
            Schema::dropIfExists('gate_passes');
            Schema::dropIfExists('visitors');
        }

        // ── Visitors ─────────────────────────────────────────────────────────
        if (! Schema::hasTable('visitors')) {
        Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('id_type')->nullable();        // NIN, passport, driver's licence
            $table->string('id_number')->nullable();
            $table->string('purpose');                    // meeting, delivery, pickup, etc.
            $table->string('host_name')->nullable();      // who they are visiting
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('badge_number')->nullable();
            $table->timestamp('entry_time');
            $table->timestamp('exit_time')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('entry_time');
            $table->index(['exit_time']);          // NULL = still inside
            $table->index('host_user_id');
        });
        }

        // ── Gate Passes ───────────────────────────────────────────────────────
        if (! Schema::hasTable('gate_passes')) {
        Schema::create('gate_passes', function (Blueprint $table) {
            $table->id();
            $table->string('pass_number')->unique();
            $table->enum('type', ['student_exit', 'staff_exit', 'visitor', 'delivery', 'other'])->default('student_exit');
            $table->string('issued_to');                  // name of person
            $table->enum('person_type', ['student', 'staff', 'visitor', 'other'])->default('student');
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason');
            $table->timestamp('valid_from');
            $table->timestamp('valid_until');
            $table->boolean('is_used')->default(false);
            $table->timestamp('used_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            // pass_number is already indexed via unique()
            $table->index(['type', 'is_used']);
            $table->index('valid_until');
        });
        }

        // ── Security Incidents ────────────────────────────────────────────────
        if (! Schema::hasTable('security_incidents')) {
        Schema::create('security_incidents', function (Blueprint $table) {
            $table->id();
            $table->enum('type', [
                'theft', 'vandalism', 'trespassing', 'fight', 'accident',
                'fire', 'unauthorized_access', 'suspicious_activity', 'other',
            ])->default('other');
            $table->string('title');
            $table->text('description');
            $table->string('location')->nullable();
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->enum('status', ['open', 'investigating', 'resolved', 'closed'])->default('open');
            $table->timestamp('reported_time');
            $table->timestamp('resolved_time')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->json('evidence_files')->nullable();   // S3 keys for photos/videos
            $table->timestamps();

            $table->index('reported_time');
            $table->index(['type', 'status']);
            $table->index('severity');
        });
        }

        // ── Access Logs ───────────────────────────────────────────────────────
        if (! Schema::hasTable('access_logs')) {
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            $table->morphs('person');                     // student/teacher/staff/visitor
            $table->string('location');                   // gate, lab, library, etc.
            $table->enum('direction', ['in', 'out'])->default('in');
            $table->enum('method', ['badge', 'manual', 'biometric', 'qr'])->default('manual');
            $table->string('device_id')->nullable();
            $table->boolean('granted')->default(true);
            $table->string('denial_reason')->nullable();
            $table->timestamp('accessed_at');
            $table->timestamps();

            // morphs('person') already adds index(person_type, person_id) — do not duplicate
            $table->index('accessed_at');
            $table->index(['location', 'accessed_at']);
        });
        }

        // ── Transport Trips ───────────────────────────────────────────────────
        if (! Schema::hasTable('transport_trips')) {
        Schema::create('transport_trips', function (Blueprint $table) {
            $table->id();
            // Matches tenant transport schema (see 2026_02_01_000001_create_transport_tables)
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('route_id')->nullable()->constrained('transport_routes')->nullOnDelete();
            $table->enum('trip_type', ['morning', 'afternoon', 'evening', 'special'])->default('morning');
            $table->date('trip_date');
            $table->timestamp('departure_time')->nullable();
            $table->timestamp('arrival_time')->nullable();
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'cancelled'])->default('scheduled');
            $table->integer('students_count')->default(0);
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->text('notes')->nullable();
            $table->text('incident_report')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'trip_date']);
            $table->index(['route_id', 'trip_date']);
            $table->index(['trip_date', 'status']);
        });
        }

        // ── Transport Attendance (per-trip student check-in/check-out) ────────
        if (! Schema::hasTable('transport_attendances')) {
        Schema::create('transport_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('transport_trips')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['present', 'absent', 'dropped_off'])->default('present');
            $table->timestamp('boarded_at')->nullable();
            $table->timestamp('alighted_at')->nullable();
            $table->string('pickup_point')->nullable();
            $table->string('dropoff_point')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['trip_id', 'student_id']);
            $table->index(['student_id', 'trip_id']);
        });
        }

        // ── Missing index: guardian_students.guardian_id ──────────────────────
        if (Schema::hasTable('guardian_students')) {
            $existing = DB::select(
                "SHOW INDEX FROM guardian_students WHERE Column_name = 'guardian_id' AND Key_name != 'PRIMARY'"
            );
            if (count($existing) === 0) {
                Schema::table('guardian_students', function (Blueprint $table) {
                    $table->index('guardian_id');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_attendances');
        Schema::dropIfExists('transport_trips');
        Schema::dropIfExists('access_logs');
        Schema::dropIfExists('security_incidents');
        Schema::dropIfExists('gate_passes');
        Schema::dropIfExists('visitors');

        if (Schema::hasTable('guardian_students')) {
            Schema::table('guardian_students', function (Blueprint $table) {
                $table->dropIndex(['guardian_id']);
            });
        }
    }
};
