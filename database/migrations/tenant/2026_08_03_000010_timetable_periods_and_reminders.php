<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('timetable_periods')) {
            Schema::create('timetable_periods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->unsignedTinyInteger('period_number');
                $table->string('label')->nullable();
                $table->time('start_time');
                $table->time('end_time');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['school_id', 'period_number']);
            });
        }

        if (Schema::hasTable('timetables')) {
            Schema::table('timetables', function (Blueprint $table) {
                if (! Schema::hasColumn('timetables', 'arm_id')) {
                    $table->foreignId('arm_id')->nullable()->after('class_id')->constrained('arms')->nullOnDelete();
                }
                if (! Schema::hasColumn('timetables', 'period_number')) {
                    $table->unsignedTinyInteger('period_number')->nullable()->after('day_of_week');
                }
            });
        }

        if (! Schema::hasTable('timetable_reminder_logs')) {
            Schema::create('timetable_reminder_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('timetable_id')->constrained('timetables')->onDelete('cascade');
                $table->date('slot_date');
                $table->unsignedSmallInteger('minutes_before');
                $table->timestamps();

                $table->unique(
                    ['user_id', 'timetable_id', 'slot_date', 'minutes_before'],
                    'timetable_reminder_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_reminder_logs');
        Schema::dropIfExists('timetable_periods');

        if (Schema::hasTable('timetables')) {
            Schema::table('timetables', function (Blueprint $table) {
                if (Schema::hasColumn('timetables', 'arm_id')) {
                    $table->dropConstrainedForeignId('arm_id');
                }
                if (Schema::hasColumn('timetables', 'period_number')) {
                    $table->dropColumn('period_number');
                }
            });
        }
    }
};
