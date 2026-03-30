<?php

namespace App\Http\Controllers;

use App\Models\HostelAllocation;
use App\Models\HostelRoom;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HostelAllocationController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    public function index(Request $request): JsonResponse
    {
        $query = HostelAllocation::where('school_id', $this->school($request)?->id ?? 0);

        if ($request->filled('status'))           $query->where('status', $request->status);
        if ($request->filled('payment_status'))   $query->where('payment_status', $request->payment_status);
        if ($request->filled('room_id'))          $query->where('room_id', $request->room_id);
        if ($request->filled('student_id'))       $query->where('student_id', $request->student_id);
        if ($request->filled('academic_year_id')) $query->where('academic_year_id', $request->academic_year_id);

        return response()->json([
            'allocations' => $query->with(['room', 'student', 'academicYear', 'term'])
                                   ->orderByDesc('allocated_at')
                                   ->paginate($request->get('per_page', 15)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id'          => 'required|exists:hostel_rooms,id',
            'student_id'       => 'required|exists:students,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'term_id'          => 'nullable|exists:terms,id',
            'allocated_at'     => 'required|date',
            'amount_paid'      => 'nullable|numeric|min:0',
            'payment_status'   => 'nullable|in:paid,partial,unpaid',
            'notes'            => 'nullable|string',
        ]);

        $room = HostelRoom::findOrFail($data['room_id']);

        if ($room->occupied_count >= $room->capacity) {
            return response()->json(['error' => 'Room has no available beds.'], 422);
        }

        $existing = HostelAllocation::where('student_id', $data['student_id'])
            ->where('status', 'active')
            ->first();
        if ($existing) {
            return response()->json(['error' => 'Student already has an active hostel allocation.'], 422);
        }

        $allocation = DB::transaction(function () use ($data, $room) {
            $alloc = HostelAllocation::create(array_merge($data, [
                'school_id' => $room->school_id,
                'status'    => 'active',
            ]));
            $room->increment('occupied_count');
            return $alloc;
        });

        return response()->json([
            'message'    => 'Student allocated to room successfully',
            'allocation' => $allocation->load(['room', 'student']),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $allocation = HostelAllocation::with(['room', 'student', 'academicYear', 'term'])->findOrFail($id);
        return response()->json(['allocation' => $allocation]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $allocation = HostelAllocation::findOrFail($id);
        $data = $request->validate([
            'amount_paid'    => 'nullable|numeric|min:0',
            'payment_status' => 'nullable|in:paid,partial,unpaid',
            'notes'          => 'nullable|string',
        ]);
        $allocation->update($data);
        return response()->json(['message' => 'Allocation updated successfully', 'allocation' => $allocation]);
    }

    public function destroy(string $id): JsonResponse
    {
        $allocation = HostelAllocation::with('room')->findOrFail($id);

        DB::transaction(function () use ($allocation) {
            if ($allocation->status === 'active') {
                $allocation->room->decrement('occupied_count');
            }
            $allocation->delete();
        });

        return response()->json(['message' => 'Allocation deleted successfully']);
    }

    public function vacate(Request $request, string $id): JsonResponse
    {
        $allocation = HostelAllocation::with('room')->findOrFail($id);

        if ($allocation->status !== 'active') {
            return response()->json(['error' => 'Allocation is not active.'], 422);
        }

        $request->validate(['vacated_at' => 'required|date']);

        DB::transaction(function () use ($allocation, $request) {
            $allocation->update(['status' => 'vacated', 'vacated_at' => $request->vacated_at]);
            $allocation->room->decrement('occupied_count');
        });

        return response()->json(['message' => 'Student vacated successfully', 'allocation' => $allocation]);
    }
}
