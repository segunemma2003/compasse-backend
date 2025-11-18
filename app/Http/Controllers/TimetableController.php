<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class TimetableController extends Controller
{
    /**
     * Get timetable
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = DB::table('timetables');

            if ($request->has('class_id')) {
                $query->where('class_id', $request->class_id);
            }

            if ($request->has('teacher_id')) {
                $query->where('teacher_id', $request->teacher_id);
            }

            if ($request->has('day_of_week')) {
                $query->where('day_of_week', $request->day_of_week);
            }

            $timetables = $query->orderBy('day_of_week')
                ->orderBy('start_time')
                ->get();

            return response()->json(['timetables' => $timetables]);
        } catch (\Exception $e) {
            // Table doesn't exist or query failed
            return response()->json(['timetables' => []]);
        }
    }

    /**
     * Get class timetable
     */
    public function getClassTimetable($classId): JsonResponse
    {
        $timetables = DB::table('timetables')
            ->where('class_id', $classId)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'class_id' => $classId,
            'timetables' => $timetables
        ]);
    }

    /**
     * Get teacher timetable
     */
    public function getTeacherTimetable($teacherId): JsonResponse
    {
        $timetables = DB::table('timetables')
            ->where('teacher_id', $teacherId)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'teacher_id' => $teacherId,
            'timetables' => $timetables
        ]);
    }

    /**
     * Create timetable entry
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'class_id' => 'nullable|exists:classes,id',
            'subject_id' => 'required|exists:subjects,id',
            'teacher_id' => 'nullable|exists:teachers,id',
            'day_of_week' => 'required|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'room' => 'nullable|string|max:50',
            'term' => 'nullable|in:first,second,third',
            'academic_year_id' => 'nullable|exists:academic_years,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $timetableId = DB::table('timetables')->insertGetId([
            'school_id' => $request->school_id ?? 1,
            'class_id' => $request->class_id,
            'subject_id' => $request->subject_id,
            'teacher_id' => $request->teacher_id,
            'day_of_week' => $request->day_of_week,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'room' => $request->room,
            'term' => $request->term,
            'academic_year_id' => $request->academic_year_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $timetable = DB::table('timetables')->find($timetableId);

        return response()->json([
            'message' => 'Timetable entry created successfully',
            'timetable' => $timetable
        ], 201);
    }

    /**
     * Update timetable entry
     */
    public function update(Request $request, $id): JsonResponse
    {
        $timetable = DB::table('timetables')->find($id);

        if (!$timetable) {
            return response()->json(['error' => 'Timetable entry not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'day_of_week' => 'sometimes|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'room' => 'nullable|string|max:50',
            'teacher_id' => 'nullable|exists:teachers,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        DB::table('timetables')
            ->where('id', $id)
            ->update(array_merge(
                $request->only(['day_of_week', 'start_time', 'end_time', 'room', 'teacher_id']),
                ['updated_at' => now()]
            ));

        $timetable = DB::table('timetables')->find($id);

        return response()->json([
            'message' => 'Timetable entry updated successfully',
            'timetable' => $timetable
        ]);
    }

    /**
     * Delete timetable entry
     */
    public function destroy($id): JsonResponse
    {
        $timetable = DB::table('timetables')->find($id);

        if (!$timetable) {
            return response()->json(['error' => 'Timetable entry not found'], 404);
        }

        DB::table('timetables')->where('id', $id)->delete();

        return response()->json([
            'message' => 'Timetable entry deleted successfully'
        ]);
    }
}
