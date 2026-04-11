<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TransportTripController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user   = Auth::user();
        $query  = DB::table('transport_trips as t')
            ->leftJoin('transport_drivers as d', 't.driver_id', '=', 'd.id')
            ->leftJoin('transport_vehicles as v', 't.vehicle_id', '=', 'v.id')
            ->leftJoin('transport_routes as r', 't.route_id', '=', 'r.id')
            ->select('t.*', 'd.name as driver_name', 'v.plate_number', 'r.name as route_name');

        // Drivers only see their own trips
        if ($user->role === 'driver') {
            $driver = DB::table('transport_drivers')->where('user_id', $user->id)->first();
            if ($driver) {
                $query->where('t.driver_id', $driver->id);
            }
        }

        if ($request->filled('trip_date'))  { $query->whereDate('t.trip_date', $request->trip_date); }
        if ($request->filled('driver_id'))  { $query->where('t.driver_id', $request->driver_id); }
        if ($request->filled('route_id'))   { $query->where('t.route_id', $request->route_id); }
        if ($request->filled('status'))     { $query->where('t.status', $request->status); }

        $trips = $query->orderByDesc('t.trip_date')->orderByDesc('t.id')->paginate(50);

        return response()->json(['trips' => $trips]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'driver_id'   => ['required', 'integer', 'exists:transport_drivers,id'],
            'vehicle_id'  => ['nullable', 'integer', 'exists:transport_vehicles,id'],
            'route_id'    => ['nullable', 'integer', 'exists:transport_routes,id'],
            'trip_type'   => ['required', 'in:morning,afternoon,evening,special'],
            'trip_date'   => ['required', 'date'],
            'notes'       => ['nullable', 'string'],
        ]);

        $id = DB::table('transport_trips')->insertGetId(array_merge($validated, [
            'status'     => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json(['trip' => DB::table('transport_trips')->find($id)], 201);
    }

    public function show(int $trip): JsonResponse
    {
        $t = DB::table('transport_trips')->find($trip);
        if (! $t) {
            return response()->json(['error' => 'Trip not found'], 404);
        }
        return response()->json(['trip' => $t]);
    }

    public function update(Request $request, int $trip): JsonResponse
    {
        $t = DB::table('transport_trips')->find($trip);
        if (! $t) {
            return response()->json(['error' => 'Trip not found'], 404);
        }

        $validated = $request->validate([
            'vehicle_id'      => ['nullable', 'integer'],
            'route_id'        => ['nullable', 'integer'],
            'trip_type'       => ['sometimes', 'in:morning,afternoon,evening,special'],
            'trip_date'       => ['sometimes', 'date'],
            'notes'           => ['nullable', 'string'],
            'incident_report' => ['nullable', 'string'],
        ]);

        DB::table('transport_trips')->where('id', $trip)->update(array_merge($validated, ['updated_at' => now()]));

        return response()->json(['trip' => DB::table('transport_trips')->find($trip)]);
    }

    public function start(int $trip): JsonResponse
    {
        DB::table('transport_trips')->where('id', $trip)->update([
            'status'         => 'in_progress',
            'departure_time' => now(),
            'updated_at'     => now(),
        ]);
        return response()->json(['message' => 'Trip started.', 'trip' => DB::table('transport_trips')->find($trip)]);
    }

    public function complete(int $trip): JsonResponse
    {
        $t = DB::table('transport_trips')->find($trip);
        if (! $t) {
            return response()->json(['error' => 'Trip not found'], 404);
        }

        $studentsCount = DB::table('transport_attendances')
            ->where('trip_id', $trip)
            ->where('status', 'present')
            ->count();

        DB::table('transport_trips')->where('id', $trip)->update([
            'status'         => 'completed',
            'arrival_time'   => now(),
            'students_count' => $studentsCount,
            'updated_at'     => now(),
        ]);

        return response()->json(['message' => 'Trip completed.', 'trip' => DB::table('transport_trips')->find($trip)]);
    }

    public function recordAttendance(Request $request, int $trip): JsonResponse
    {
        $request->validate([
            'attendance'              => ['required', 'array'],
            'attendance.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'attendance.*.status'     => ['required', 'in:present,absent,dropped_off'],
            'attendance.*.pickup_point'  => ['nullable', 'string'],
            'attendance.*.dropoff_point' => ['nullable', 'string'],
        ]);

        $now    = now();
        $userId = Auth::id();
        $rows   = [];

        foreach ($request->attendance as $item) {
            $rows[] = [
                'trip_id'       => $trip,
                'student_id'    => $item['student_id'],
                'status'        => $item['status'],
                'pickup_point'  => $item['pickup_point'] ?? null,
                'dropoff_point' => $item['dropoff_point'] ?? null,
                'confirmed_by'  => $userId,
                'boarded_at'    => $item['status'] === 'present' ? $now : null,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        // Upsert — one row per student per trip
        foreach ($rows as $row) {
            DB::table('transport_attendances')->upsert(
                $row,
                ['trip_id', 'student_id'],
                ['status', 'pickup_point', 'dropoff_point', 'confirmed_by', 'boarded_at', 'updated_at']
            );
        }

        return response()->json([
            'message' => count($rows) . ' attendance record(s) saved.',
            'count'   => count($rows),
        ]);
    }

    public function getAttendance(int $trip): JsonResponse
    {
        $attendance = DB::table('transport_attendances as ta')
            ->join('students as s', 'ta.student_id', '=', 's.id')
            ->select('ta.*', 's.first_name', 's.last_name', 's.admission_number')
            ->where('ta.trip_id', $trip)
            ->get();

        return response()->json(['attendance' => $attendance]);
    }
}
