<?php

namespace App\Http\Controllers;

use App\Models\Medication;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MedicationController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    public function index(Request $request): JsonResponse
    {
        $query = Medication::where('school_id', $this->school($request)?->id ?? 0);

        if ($request->filled('status'))     $query->where('status', $request->status);
        if ($request->filled('student_id')) $query->where('student_id', $request->student_id);

        return response()->json([
            'medications' => $query->with('student')->orderByDesc('start_date')->paginate($request->get('per_page', 15)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id'   => 'required|exists:students,id',
            'name'         => 'required|string|max:150',
            'dosage'       => 'required|string|max:100',
            'frequency'    => 'required|string|max:100',
            'start_date'   => 'required|date',
            'end_date'     => 'nullable|date|after_or_equal:start_date',
            'prescribed_by'=> 'nullable|string|max:150',
            'reason'       => 'nullable|string|max:500',
            'side_effects' => 'nullable|string',
            'notes'        => 'nullable|string',
            'status'       => 'nullable|in:active,completed,discontinued',
        ]);

        $data['status'] = $data['status'] ?? 'active';
        $medication = Medication::create(array_merge($data, ['school_id' => $this->school($request)?->id]));

        return response()->json(['message' => 'Medication added', 'medication' => $medication->load('student')], 201);
    }

    public function show(string $id): JsonResponse
    {
        $medication = Medication::with('student')->findOrFail($id);
        return response()->json(['medication' => $medication]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $medication = Medication::findOrFail($id);
        $data = $request->validate([
            'name'         => 'sometimes|string|max:150',
            'dosage'       => 'sometimes|string|max:100',
            'frequency'    => 'sometimes|string|max:100',
            'start_date'   => 'sometimes|date',
            'end_date'     => 'nullable|date',
            'prescribed_by'=> 'nullable|string|max:150',
            'reason'       => 'nullable|string|max:500',
            'side_effects' => 'nullable|string',
            'notes'        => 'nullable|string',
            'status'       => 'nullable|in:active,completed,discontinued',
        ]);
        $medication->update($data);
        return response()->json(['message' => 'Medication updated', 'medication' => $medication]);
    }

    public function destroy(string $id): JsonResponse
    {
        Medication::findOrFail($id)->delete();
        return response()->json(['message' => 'Medication deleted']);
    }
}
