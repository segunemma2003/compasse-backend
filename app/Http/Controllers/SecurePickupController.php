<?php

namespace App\Http\Controllers;

use App\Models\SecurePickup;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SecurePickupController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    public function index(Request $request): JsonResponse
    {
        $query = SecurePickup::where('school_id', $this->school($request)?->id ?? 0);

        if ($request->filled('student_id')) $query->where('student_id', $request->student_id);
        if ($request->filled('status'))     $query->where('status', $request->status);
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('authorized_name', 'like', "%$s%")
                                      ->orWhere('authorized_phone','like', "%$s%"));
        }

        return response()->json([
            'pickups' => $query->with('student')->orderBy('authorized_name')->paginate($request->get('per_page', 15)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id'       => 'required|exists:students,id',
            'authorized_name'  => 'required|string|max:150',
            'authorized_phone' => 'required|string|max:20',
            'relationship'     => 'required|string|max:80',
            'authorized_photo' => 'nullable|string',
            'status'           => 'nullable|in:active,inactive',
            'notes'            => 'nullable|string',
        ]);

        $data['pickup_code'] = SecurePickup::generatePickupCode();
        $pickup = SecurePickup::create(array_merge($data, ['school_id' => $this->school($request)?->id]));

        return response()->json([
            'message' => 'Secure pickup authorization created',
            'pickup'  => $pickup->load('student'),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $pickup = SecurePickup::with('student')->findOrFail($id);
        return response()->json(['pickup' => $pickup]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $pickup = SecurePickup::findOrFail($id);
        $data   = $request->validate([
            'authorized_name'  => 'sometimes|string|max:150',
            'authorized_phone' => 'sometimes|string|max:20',
            'relationship'     => 'sometimes|string|max:80',
            'authorized_photo' => 'nullable|string',
            'status'           => 'nullable|in:active,inactive',
            'notes'            => 'nullable|string',
        ]);
        $pickup->update($data);
        return response()->json(['message' => 'Pickup authorization updated', 'pickup' => $pickup]);
    }

    public function destroy(string $id): JsonResponse
    {
        SecurePickup::findOrFail($id)->delete();
        return response()->json(['message' => 'Pickup authorization deleted']);
    }

    public function verify(Request $request): JsonResponse
    {
        $request->validate(['pickup_code' => 'required|string']);

        $pickup = SecurePickup::with('student')
            ->where('pickup_code', $request->pickup_code)
            ->where('status', 'active')
            ->first();

        if (!$pickup) {
            return response()->json(['error' => 'Invalid or inactive pickup code.'], 404);
        }

        return response()->json([
            'verified'   => true,
            'pickup'     => $pickup,
            'student'    => $pickup->student,
        ]);
    }
}
