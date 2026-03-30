<?php

namespace App\Http\Controllers;

use App\Models\HostelMaintenance;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class HostelMaintenanceController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    public function index(Request $request): JsonResponse
    {
        $query = HostelMaintenance::where('school_id', $this->school($request)?->id ?? 0);

        if ($request->filled('status'))   $query->where('status', $request->status);
        if ($request->filled('priority')) $query->where('priority', $request->priority);
        if ($request->filled('room_id'))  $query->where('room_id', $request->room_id);
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('title',      'like', "%$s%")
                                      ->orWhere('description','like', "%$s%"));
        }

        return response()->json([
            'maintenance' => $query->with(['room', 'reporter', 'assignee'])
                                   ->orderByDesc('reported_at')
                                   ->paginate($request->get('per_page', 15)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id'          => 'required|exists:hostel_rooms,id',
            'title'            => 'required|string|max:200',
            'description'      => 'nullable|string',
            'priority'         => 'nullable|in:low,medium,high,urgent',
            'assigned_to'      => 'nullable|exists:users,id',
            'cost'             => 'nullable|numeric|min:0',
            'resolution_notes' => 'nullable|string',
        ]);

        $data['reported_by'] = auth()->id();
        $data['reported_at'] = now();
        $data['status']      = 'pending';

        $maintenance = HostelMaintenance::create(array_merge($data, ['school_id' => $this->school($request)?->id]));

        return response()->json([
            'message'     => 'Maintenance request created',
            'maintenance' => $maintenance->load(['room', 'reporter']),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $maintenance = HostelMaintenance::with(['room', 'reporter', 'assignee'])->findOrFail($id);
        return response()->json(['maintenance' => $maintenance]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $maintenance = HostelMaintenance::findOrFail($id);
        $data = $request->validate([
            'title'            => 'sometimes|string|max:200',
            'description'      => 'nullable|string',
            'priority'         => 'nullable|in:low,medium,high,urgent',
            'status'           => 'sometimes|in:pending,in_progress,completed,cancelled',
            'assigned_to'      => 'nullable|exists:users,id',
            'cost'             => 'nullable|numeric|min:0',
            'resolution_notes' => 'nullable|string',
        ]);

        if (isset($data['status']) && $data['status'] === 'completed' && !$maintenance->completed_at) {
            $data['completed_at'] = now();
        }

        $maintenance->update($data);
        return response()->json(['message' => 'Maintenance request updated', 'maintenance' => $maintenance]);
    }

    public function destroy(string $id): JsonResponse
    {
        HostelMaintenance::findOrFail($id)->delete();
        return response()->json(['message' => 'Maintenance request deleted']);
    }
}
