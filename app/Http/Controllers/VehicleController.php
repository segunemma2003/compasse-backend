<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    public function index(Request $request): JsonResponse
    {
        $query = Vehicle::where('school_id', $this->school($request)?->id ?? 0);

        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('type'))   $query->where('type',   $request->type);
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('make',         'like', "%$s%")
                                      ->orWhere('model',       'like', "%$s%")
                                      ->orWhere('plate_number','like', "%$s%"));
        }

        return response()->json([
            'vehicles' => $query->orderBy('make')->paginate($request->get('per_page', 15)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'make'              => 'required|string|max:100',
            'model'             => 'required|string|max:100',
            'plate_number'      => 'required|string|max:20|unique:vehicles,plate_number',
            'capacity'          => 'required|integer|min:1',
            'type'              => 'required|in:bus,van,car,minibus',
            'year'              => 'nullable|digits:4|integer',
            'status'            => 'nullable|in:active,inactive,maintenance',
            'insurance_expiry'  => 'nullable|date',
            'last_service_date' => 'nullable|date',
            'notes'             => 'nullable|string',
        ]);

        $vehicle = Vehicle::create(array_merge($data, ['school_id' => $this->school($request)?->id]));

        return response()->json(['message' => 'Vehicle created successfully', 'vehicle' => $vehicle], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $vehicle = Vehicle::with(['routes.driver', 'routes.students'])->findOrFail($id);
        return response()->json(['vehicle' => $vehicle]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);
        $data    = $request->validate([
            'make'              => 'sometimes|string|max:100',
            'model'             => 'sometimes|string|max:100',
            'plate_number'      => 'sometimes|string|max:20|unique:vehicles,plate_number,' . $id,
            'capacity'          => 'sometimes|integer|min:1',
            'type'              => 'sometimes|in:bus,van,car,minibus',
            'year'              => 'nullable|digits:4|integer',
            'status'            => 'nullable|in:active,inactive,maintenance',
            'insurance_expiry'  => 'nullable|date',
            'last_service_date' => 'nullable|date',
            'notes'             => 'nullable|string',
        ]);
        $vehicle->update($data);
        return response()->json(['message' => 'Vehicle updated successfully', 'vehicle' => $vehicle]);
    }

    public function destroy(string $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);
        if ($vehicle->routes()->where('status', 'active')->exists()) {
            return response()->json(['error' => 'Cannot delete a vehicle assigned to active routes.'], 422);
        }
        $vehicle->delete();
        return response()->json(['message' => 'Vehicle deleted successfully']);
    }
}
