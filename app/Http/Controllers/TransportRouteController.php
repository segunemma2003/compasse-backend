<?php

namespace App\Http\Controllers;

use App\Models\TransportRoute;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TransportRouteController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    public function index(Request $request): JsonResponse
    {
        $query = TransportRoute::where('school_id', $this->school($request)?->id ?? 0);

        if ($request->filled('status'))    $query->where('status', $request->status);
        if ($request->filled('driver_id')) $query->where('driver_id', $request->driver_id);
        if ($request->filled('vehicle_id'))$query->where('vehicle_id', $request->vehicle_id);
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('name',        'like', "%$s%")
                                      ->orWhere('route_code', 'like', "%$s%"));
        }

        return response()->json([
            'routes' => $query->with(['vehicle', 'driver'])
                              ->withCount('students')
                              ->orderBy('name')
                              ->paginate($request->get('per_page', 15)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                   => 'required|string|max:150',
            'route_code'             => 'nullable|string|max:20|unique:transport_routes,route_code',
            'description'            => 'nullable|string',
            'vehicle_id'             => 'nullable|exists:vehicles,id',
            'driver_id'              => 'nullable|exists:drivers,id',
            'start_point'            => 'required|string|max:200',
            'end_point'              => 'required|string|max:200',
            'stops'                  => 'nullable|array',
            'distance_km'            => 'nullable|numeric|min:0',
            'fare'                   => 'nullable|numeric|min:0',
            'morning_pickup_time'    => 'nullable|date_format:H:i',
            'afternoon_dropoff_time' => 'nullable|date_format:H:i',
            'status'                 => 'nullable|in:active,inactive',
        ]);

        $route = TransportRoute::create(array_merge($data, ['school_id' => $this->school($request)?->id]));

        return response()->json(['message' => 'Route created successfully', 'route' => $route->load(['vehicle', 'driver'])], 201);
    }

    public function show(string $id): JsonResponse
    {
        $route = TransportRoute::with(['vehicle', 'driver', 'students'])->findOrFail($id);
        return response()->json(['route' => $route]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $route = TransportRoute::findOrFail($id);
        $data  = $request->validate([
            'name'                   => 'sometimes|string|max:150',
            'route_code'             => 'sometimes|string|max:20|unique:transport_routes,route_code,' . $id,
            'description'            => 'nullable|string',
            'vehicle_id'             => 'nullable|exists:vehicles,id',
            'driver_id'              => 'nullable|exists:drivers,id',
            'start_point'            => 'sometimes|string|max:200',
            'end_point'              => 'sometimes|string|max:200',
            'stops'                  => 'nullable|array',
            'distance_km'            => 'nullable|numeric|min:0',
            'fare'                   => 'nullable|numeric|min:0',
            'morning_pickup_time'    => 'nullable|date_format:H:i',
            'afternoon_dropoff_time' => 'nullable|date_format:H:i',
            'status'                 => 'nullable|in:active,inactive',
        ]);
        $route->update($data);
        return response()->json(['message' => 'Route updated successfully', 'route' => $route->load(['vehicle', 'driver'])]);
    }

    public function destroy(string $id): JsonResponse
    {
        $route = TransportRoute::findOrFail($id);
        if ($route->students()->exists()) {
            return response()->json(['error' => 'Cannot delete a route with assigned students.'], 422);
        }
        $route->delete();
        return response()->json(['message' => 'Route deleted successfully']);
    }

    public function getStudents(string $routeId): JsonResponse
    {
        $route = TransportRoute::with('students')->findOrFail($routeId);
        return response()->json([
            'route'    => $route->only(['id', 'name', 'route_code']),
            'students' => $route->students,
        ]);
    }

    public function assignStudent(Request $request, string $routeId): JsonResponse
    {
        $route = TransportRoute::findOrFail($routeId);

        $data = $request->validate([
            'student_id'    => 'required|exists:students,id',
            'pickup_stop'   => 'nullable|string|max:200',
            'dropoff_stop'  => 'nullable|string|max:200',
        ]);

        if ($route->students()->where('students.id', $data['student_id'])->exists()) {
            return response()->json(['error' => 'Student is already assigned to this route.'], 422);
        }

        $route->students()->attach($data['student_id'], [
            'pickup_stop'  => $data['pickup_stop']  ?? null,
            'dropoff_stop' => $data['dropoff_stop'] ?? null,
        ]);

        return response()->json(['message' => 'Student assigned to route successfully']);
    }

    public function removeStudent(Request $request, string $routeId): JsonResponse
    {
        $route = TransportRoute::findOrFail($routeId);
        $request->validate(['student_id' => 'required|exists:students,id']);

        $route->students()->detach($request->student_id);
        return response()->json(['message' => 'Student removed from route successfully']);
    }
}
