<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AttendanceController/BulkOperationService write these columns (they're
     * in Attendance::$fillable) but the original migration never created them —
     * every attendance-marking request has been failing with a
     * "column not found" SQL error.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->unsignedBigInteger('school_id')->nullable()->after('id');
            $table->text('notes')->nullable()->after('remarks');
            $table->decimal('total_hours', 5, 2)->nullable();
            $table->integer('break_duration')->nullable();
            $table->decimal('overtime_hours', 5, 2)->nullable();
            $table->string('location')->nullable();
            $table->string('device_info')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->boolean('is_late')->default(false);
            $table->integer('late_minutes')->nullable();
            $table->boolean('is_absent')->default(false);
            $table->string('absence_reason')->nullable();
            $table->boolean('is_excused')->default(false);
            $table->text('excuse_notes')->nullable();

            $table->index(['school_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'date']);
            $table->dropColumn([
                'school_id', 'notes', 'total_hours', 'break_duration', 'overtime_hours',
                'location', 'device_info', 'ip_address', 'is_late', 'late_minutes',
                'is_absent', 'absence_reason', 'is_excused', 'excuse_notes',
            ]);
        });
    }
};
