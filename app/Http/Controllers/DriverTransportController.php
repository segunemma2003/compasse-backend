<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\UserEffectiveRoles;

class DriverTransportController extends Controller
{
    /**
     * Driver shift: route, stops, roster, active trip.
     */
    public function shift(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! in_array('driver', UserEffectiveRoles::forUser($user), true)) {
            return response()->json(['error' => 'Drivers only'], 403);
        }

        $driver = DB::table('drivers')->where('user_id', $user->id)->first();
        if (! $driver) {
            return response()->json(['error' => 'Driver profile not found'], 404);
        }

        $route = DB::table('transport_routes')->where('driver_id', $driver->id)->first();
        if (! $route) {
            return response()->json([
                'clocked_in' => false,
                'route'      => null,
                'stops'      => [],
                'students'   => [],
                'trip'       => null,
            ]);
        }

        $stops = json_decode($route->stops ?? '[]', true);
        if (! is_array($stops)) {
            $stops = array_filter(array_map('trim', explode(',', (string) ($route->stops ?? ''))));
        }

        $students = [];
        if (Schema::hasTable('student_transport_routes')) {
            $students = DB::table('student_transport_routes as str')
                ->join('students', 'str.student_id', '=', 'students.id')
                ->join('users', 'students.user_id', '=', 'users.id')
                ->leftJoin('classes', 'students.class_id', '=', 'classes.id')
                ->where('str.route_id', $route->id)
                ->get([
                    'students.id as student_id',
                    'users.name as student_name',
                    'classes.name as class_name',
                    'str.pickup_stop',
                    'str.dropoff_stop',
                ]);
        }

        $trip = null;
        if (Schema::hasTable('transport_trips')) {
            $trip = DB::table('transport_trips')
                ->where('driver_id', $driver->id)
                ->whereDate('trip_date', today())
                ->orderByDesc('id')
                ->first();
        }

        return response()->json([
            'clocked_in' => $trip && ($trip->status ?? '') === 'in_progress',
            'route'      => [
                'id'   => $route->id,
                'name' => $route->name,
            ],
            'stops'      => array_values($stops),
            'students'   => $students,
            'trip'       => $trip,
            'last_location' => $trip && isset($trip->last_lat)
                ? ['lat' => (float) $trip->last_lat, 'lng' => (float) $trip->last_lng]
                : null,
        ]);
    }

    /**
     * Live GPS ping while on route.
     */
    public function updateLocation(Request $request): JsonResponse
    {
        $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
        ]);

        $user = Auth::user();
        $driver = DB::table('drivers')->where('user_id', $user->id)->first();
        if (! $driver) {
            return response()->json(['error' => 'Driver profile not found'], 404);
        }

        $trip = DB::table('transport_trips')
            ->where('driver_id', $driver->id)
            ->whereDate('trip_date', today())
            ->where('status', 'in_progress')
            ->orderByDesc('id')
            ->first();

        if (! $trip) {
            return response()->json(['error' => 'No active trip — clock in first'], 422);
        }

        $this->recordTripLocation((int) $trip->id, $request->lat, $request->lng);

        return response()->json([
            'message' => 'Location updated',
            'trip_id' => $trip->id,
            'lat'     => (float) $request->lat,
            'lng'     => (float) $request->lng,
        ]);
    }

    private function recordTripLocation(int $tripId, $lat, $lng): void
    {
        if ($lat === null || $lng === null || ! Schema::hasColumn('transport_trips', 'last_lat')) {
            return;
        }

        $trip = DB::table('transport_trips')->find($tripId);
        $trace = [];
        if (! empty($trip->location_trace)) {
            $decoded = json_decode($trip->location_trace, true);
            if (is_array($decoded)) {
                $trace = $decoded;
            }
        }
        $trace[] = [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'at'  => now()->toIso8601String(),
        ];
        if (count($trace) > 200) {
            $trace = array_slice($trace, -200);
        }

        DB::table('transport_trips')->where('id', $tripId)->update([
            'last_lat'        => $lat,
            'last_lng'        => $lng,
            'location_trace'  => json_encode($trace),
            'updated_at'      => now(),
        ]);
    }

    /**
     * Clock in / start morning route trip.
     */
    public function clockIn(Request $request): JsonResponse
    {
        $user = Auth::user();
        $driver = DB::table('drivers')->where('user_id', $user->id)->first();
        if (! $driver) {
            return response()->json(['error' => 'Driver profile not found'], 404);
        }

        $route = DB::table('transport_routes')->where('driver_id', $driver->id)->first();
        if (! $route) {
            return response()->json(['error' => 'No route assigned'], 422);
        }

        $existing = DB::table('transport_trips')
            ->where('driver_id', $driver->id)
            ->whereDate('trip_date', today())
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->first();

        if ($existing) {
            DB::table('transport_trips')->where('id', $existing->id)->update([
                'status'          => 'in_progress',
                'departure_time'  => now(),
                'updated_at'      => now(),
            ]);
            $trip = DB::table('transport_trips')->find($existing->id);
        } else {
            $id = DB::table('transport_trips')->insertGetId([
                'driver_id'      => $driver->id,
                'route_id'       => $route->id,
                'vehicle_id'     => $route->vehicle_id,
                'trip_type'      => $request->input('trip_type', 'morning'),
                'trip_date'      => today()->toDateString(),
                'status'         => 'in_progress',
                'departure_time' => now(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
            $trip = DB::table('transport_trips')->find($id);
        }

        $this->recordTripLocation($trip->id, $request->input('lat'), $request->input('lng'));

        return response()->json(['message' => 'Clocked in — route started', 'trip' => DB::table('transport_trips')->find($trip->id)]);
    }

    /**
     * Notify guardians that bus is approaching a stop.
     */
    public function stopAlert(Request $request): JsonResponse
    {
        $request->validate([
            'stop_name' => 'required|string|max:255',
            'message'   => 'nullable|string|max:500',
        ]);

        $user = Auth::user();
        $driver = DB::table('drivers')->where('user_id', $user->id)->first();
        if (! $driver) {
            return response()->json(['error' => 'Driver profile not found'], 404);
        }

        $route = DB::table('transport_routes')->where('driver_id', $driver->id)->first();
        if (! $route) {
            return response()->json(['error' => 'No route assigned'], 422);
        }

        $stop = $request->stop_name;
        $msg  = $request->message ?? "School bus is approaching {$stop}.";

        $studentIds = DB::table('student_transport_routes')
            ->where('route_id', $route->id)
            ->where(function ($q) use ($stop) {
                $q->where('pickup_stop', 'like', "%{$stop}%")
                    ->orWhere('dropoff_stop', 'like', "%{$stop}%");
            })
            ->pluck('student_id');

        $notified = 0;
        if (Schema::hasTable('notifications') && Schema::hasTable('guardian_students')) {
            $guardianUserIds = DB::table('guardian_students')
                ->join('guardians', 'guardian_students.guardian_id', '=', 'guardians.id')
                ->whereIn('guardian_students.student_id', $studentIds)
                ->whereNotNull('guardians.user_id')
                ->pluck('guardians.user_id');

            foreach ($guardianUserIds as $uid) {
                DB::table('notifications')->insert([
                    'user_id'    => $uid,
                    'title'      => 'Transport update',
                    'message'    => $msg,
                    'type'       => 'transport',
                    'is_read'    => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $notified++;
            }
        }

        $activeTripId = DB::table('transport_trips')
            ->where('driver_id', $driver->id)
            ->whereDate('trip_date', today())
            ->orderByDesc('id')
            ->value('id');
        if ($activeTripId) {
            $this->recordTripLocation((int) $activeTripId, $request->input('lat'), $request->input('lng'));
        }

        return response()->json([
            'message'            => 'Stop alert sent',
            'stop'             => $stop,
            'guardians_notified' => $notified,
        ]);
    }
}
