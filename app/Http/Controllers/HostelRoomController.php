<?php

namespace App\Http\Controllers;

use App\Models\HostelRoom;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class HostelRoomController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    public function index(Request $request): JsonResponse
    {
        $query = HostelRoom::where('school_id', $this->school($request)?->id ?? 0);

        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('type'))   $query->where('type',   $request->type);
        if ($request->filled('block'))  $query->where('block',  $request->block);
        if ($request->filled('available_only') && $request->boolean('available_only')) {
            $query->whereRaw('occupied_count < capacity');
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('room_number', 'like', "%$s%")
                                      ->orWhere('block',       'like', "%$s%"));
        }

        return response()->json([
            'rooms' => $query->orderBy('block')->orderBy('room_number')->paginate($request->get('per_page', 15)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_number'    => 'required|string|max:20',
            'block'          => 'nullable|string|max:50',
            'floor'          => 'nullable|string|max:20',
            'type'           => 'required|in:single,double,triple,dormitory',
            'capacity'       => 'required|integer|min:1',
            'price_per_term' => 'nullable|numeric|min:0',
            'amenities'      => 'nullable|array',
            'status'         => 'nullable|in:available,occupied,maintenance,closed',
            'notes'          => 'nullable|string',
        ]);

        $data['occupied_count'] = 0;
        $room = HostelRoom::create(array_merge($data, ['school_id' => $this->school($request)?->id]));

        return response()->json(['message' => 'Room created successfully', 'room' => $room], 201);
    }

    public function show(string $id): JsonResponse
    {
        $room = HostelRoom::with(['allocations.student', 'maintenance'])->findOrFail($id);
        return response()->json(['room' => $room]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $room = HostelRoom::findOrFail($id);
        $data = $request->validate([
            'room_number'    => 'sometimes|string|max:20',
            'block'          => 'nullable|string|max:50',
            'floor'          => 'nullable|string|max:20',
            'type'           => 'sometimes|in:single,double,triple,dormitory',
            'capacity'       => 'sometimes|integer|min:1',
            'price_per_term' => 'nullable|numeric|min:0',
            'amenities'      => 'nullable|array',
            'status'         => 'nullable|in:available,occupied,maintenance,closed',
            'notes'          => 'nullable|string',
        ]);
        $room->update($data);
        return response()->json(['message' => 'Room updated successfully', 'room' => $room]);
    }

    public function destroy(string $id): JsonResponse
    {
        $room = HostelRoom::findOrFail($id);
        if ($room->allocations()->where('status', 'active')->exists()) {
            return response()->json(['error' => 'Cannot delete a room with active allocations.'], 422);
        }
        $room->delete();
        return response()->json(['message' => 'Room deleted successfully']);
    }
}
