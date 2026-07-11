<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LivestreamController/Livestream model consistently use status values
     * ('active','completed') and LivestreamAttendance columns (student_id,
     * teacher_id, device_info, ip_address) that the original migration never
     * created — every start/end/join/leave call threw a SQL error.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE livestreams MODIFY status ENUM('scheduled','active','completed','cancelled') NOT NULL DEFAULT 'scheduled'");

        Schema::table('livestream_attendances', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id')->nullable()->after('livestream_id');
            $table->unsignedBigInteger('teacher_id')->nullable()->after('student_id');
            $table->string('device_info')->nullable();
            $table->string('ip_address', 45)->nullable();
        });

        DB::statement("ALTER TABLE livestream_attendances MODIFY status ENUM('present','absent','late','completed') NOT NULL DEFAULT 'present'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE livestreams MODIFY status ENUM('scheduled','live','ended','cancelled') NOT NULL DEFAULT 'scheduled'");

        Schema::table('livestream_attendances', function (Blueprint $table) {
            $table->dropColumn(['student_id', 'teacher_id', 'device_info', 'ip_address']);
        });

        DB::statement("ALTER TABLE livestream_attendances MODIFY status ENUM('present','absent','late') NOT NULL DEFAULT 'present'");
    }
};
