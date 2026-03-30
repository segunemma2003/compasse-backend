<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DriverController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    public function index(Request $request): JsonResponse
    {
        $query = Driver::where('school_id', $this->school($request)?->id ?? 0);

        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('name',           'like', "%$s%")
                                      ->orWhere('license_number','like', "%$s%")
                                      ->orWhere('phone',         'like', "%$s%"));
        }

        return response()->json([
            'drivers' => $query->with('routes')->orderBy('name')->paginate($request->get('per_page', 15)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'           => 'required|string|max:150',
            'license_number' => 'required|string|max:50|unique:drivers,license_number',
            'license_expiry' => 'required|date',
            'phone'          => 'required|string|max:20',
            'address'        => 'nullable|string',
            'date_of_birth'  => 'nullable|date',
            'status'         => 'nullable|in:active,inactive,suspended',
            'user_id'        => 'nullable|exists:users,id',
        ]);

        $driver = Driver::create(array_merge($data, ['school_id' => $this->school($request)?->id]));

        return response()->json(['message' => 'Driver created successfully', 'driver' => $driver], 201);
    }

    public function show(string $id): JsonResponse
    {
        $driver = Driver::with(['routes.vehicle', 'user'])->findOrFail($id);
        return response()->json(['driver' => $driver]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $driver = Driver::findOrFail($id);
        $data   = $request->validate([
            'name'           => 'sometimes|string|max:150',
            'license_number' => 'sometimes|string|max:50|unique:drivers,license_number,' . $id,
            'license_expiry' => 'sometimes|date',
            'phone'          => 'sometimes|string|max:20',
            'address'        => 'nullable|string',
            'date_of_birth'  => 'nullable|date',
            'status'         => 'nullable|in:active,inactive,suspended',
        ]);
        $driver->update($data);
        return response()->json(['message' => 'Driver updated successfully', 'driver' => $driver]);
    }

    public function destroy(string $id): JsonResponse
    {
        $driver = Driver::findOrFail($id);
        if ($driver->routes()->where('status', 'active')->exists()) {
            return response()->json(['error' => 'Cannot delete a driver assigned to active routes.'], 422);
        }
        $driver->delete();
        return response()->json(['message' => 'Driver deleted successfully']);
    }
}
