<?php

namespace App\Http\Controllers;

use App\Models\HealthRecord;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class HealthRecordController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    public function index(Request $request): JsonResponse
    {
        $query = HealthRecord::where('school_id', $this->school($request)?->id ?? 0);

        if ($request->filled('student_id')) $query->where('student_id', $request->student_id);
        if ($request->filled('blood_group')) $query->where('blood_group', $request->blood_group);

        return response()->json([
            'records' => $query->with('student')->orderByDesc('updated_at')->paginate($request->get('per_page', 15)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id'              => 'required|exists:students,id',
            'blood_group'             => 'nullable|string|max:10',
            'height_cm'               => 'nullable|numeric|min:0',
            'weight_kg'               => 'nullable|numeric|min:0',
            'allergies'               => 'nullable|array',
            'medical_conditions'      => 'nullable|array',
            'current_medications'     => 'nullable|array',
            'immunization_records'    => 'nullable|array',
            'emergency_contact_name'  => 'nullable|string|max:150',
            'emergency_contact_phone' => 'nullable|string|max:20',
            'family_doctor_name'      => 'nullable|string|max:150',
            'family_doctor_phone'     => 'nullable|string|max:20',
            'last_checkup_date'       => 'nullable|date',
            'notes'                   => 'nullable|string',
        ]);

        $existing = HealthRecord::where('student_id', $data['student_id'])->first();
        if ($existing) {
            return response()->json(['error' => 'Health record already exists for this student. Use update instead.'], 422);
        }

        $record = HealthRecord::create(array_merge($data, ['school_id' => $this->school($request)?->id]));

        return response()->json(['message' => 'Health record created', 'record' => $record->load('student')], 201);
    }

    public function show(string $id): JsonResponse
    {
        $record = HealthRecord::with(['student'])->findOrFail($id);
        return response()->json(['record' => $record, 'bmi' => $record->bmi]);
    }

    public function showByStudent(string $studentId): JsonResponse
    {
        $record = HealthRecord::with('student')->where('student_id', $studentId)->firstOrFail();
        return response()->json(['record' => $record, 'bmi' => $record->bmi]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $record = HealthRecord::findOrFail($id);
        $data = $request->validate([
            'blood_group'             => 'nullable|string|max:10',
            'height_cm'               => 'nullable|numeric|min:0',
            'weight_kg'               => 'nullable|numeric|min:0',
            'allergies'               => 'nullable|array',
            'medical_conditions'      => 'nullable|array',
            'current_medications'     => 'nullable|array',
            'immunization_records'    => 'nullable|array',
            'emergency_contact_name'  => 'nullable|string|max:150',
            'emergency_contact_phone' => 'nullable|string|max:20',
            'family_doctor_name'      => 'nullable|string|max:150',
            'family_doctor_phone'     => 'nullable|string|max:20',
            'last_checkup_date'       => 'nullable|date',
            'notes'                   => 'nullable|string',
        ]);
        $record->update($data);
        return response()->json(['message' => 'Health record updated', 'record' => $record]);
    }

    public function destroy(string $id): JsonResponse
    {
        HealthRecord::findOrFail($id)->delete();
        return response()->json(['message' => 'Health record deleted']);
    }
}
