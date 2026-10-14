<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A class teacher can be set on the class itself (classes.class_teacher_id) or on one
 * of its sections (class_arm.class_teacher_id). Schools that use sections only fill
 * the second, so every "who is the class teacher" lookup has to read both.
 */
class ClassTeachers
{
    /** Class ids this teacher is class teacher of (whole class or a section of it). */
    public static function classIdsFor(int $teacherId): array
    {
        $ids = DB::table('classes')->where('class_teacher_id', $teacherId)->pluck('id');
        if (Schema::hasTable('class_arm')) {
            $ids = $ids->merge(DB::table('class_arm')->where('class_teacher_id', $teacherId)->pluck('class_id'));
        }

        return $ids->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** Teacher ids who are class teacher for a student in this class (and section, when known). */
    public static function teacherIdsFor(int $classId, ?int $armId = null): array
    {
        $ids = collect();
        if (Schema::hasTable('class_arm')) {
            $ids = DB::table('class_arm')->where('class_id', $classId)
                ->when($armId, fn ($q) => $q->where('arm_id', $armId))
                ->whereNotNull('class_teacher_id')->pluck('class_teacher_id');
        }
        $ids = $ids->merge(DB::table('classes')->where('id', $classId)->whereNotNull('class_teacher_id')->pluck('class_teacher_id'));

        return $ids->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
