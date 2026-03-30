<?php

namespace App\Http\Controllers;

use App\Models\HealthAppointment;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class HealthAppointmentController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    public function index(Request $request): JsonResponse
    {
        $query = HealthAppointment::where('school_id', $this->school($request)?->id ?? 0);

        if ($request->filled('status'))     $query->where('status', $request->status);
        if ($request->filled('student_id')) $query->where('student_id', $request->student_id);
        if ($request->filled('date_from'))  $query->whereDate('appointment_date', '>=', $request->date_from);
        if ($request->filled('date_to'))    $query->whereDate('appointment_date', '<=', $request->date_to);

        return response()->json([
            'appointments' => $query->with(['student', 'createdBy'])
                                    ->orderBy('appointment_date')
                                    ->paginate($request->get('per_page', 15)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id'       => 'required|exists:students,id',
            'doctor_name'      => 'nullable|string|max:150',
            'appointment_date' => 'required|date',
            'appointment_time' => 'nullable|date_format:H:i',
            'reason'           => 'required|string|max:500',
            'status'           => 'nullable|in:scheduled,completed,cancelled,no_show',
            'diagnosis'        => 'nullable|string',
            'prescription'     => 'nullable|string',
            'follow_up_date'   => 'nullable|date',
            'notes'            => 'nullable|string',
        ]);

        $data['created_by'] = auth()->id();
        $data['status']     = $data['status'] ?? 'scheduled';

        $appointment = HealthAppointment::create(array_merge($data, ['school_id' => $this->school($request)?->id]));

        return response()->json([
            'message'     => 'Appointment created',
            'appointment' => $appointment->load(['student', 'createdBy']),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $appointment = HealthAppointment::with(['student', 'createdBy'])->findOrFail($id);
        return response()->json(['appointment' => $appointment]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $appointment = HealthAppointment::findOrFail($id);
        $data = $request->validate([
            'doctor_name'      => 'nullable|string|max:150',
            'appointment_date' => 'sometimes|date',
            'appointment_time' => 'nullable|date_format:H:i',
            'reason'           => 'sometimes|string|max:500',
            'status'           => 'sometimes|in:scheduled,completed,cancelled,no_show',
            'diagnosis'        => 'nullable|string',
            'prescription'     => 'nullable|string',
            'follow_up_date'   => 'nullable|date',
            'notes'            => 'nullable|string',
        ]);
        $appointment->update($data);
        return response()->json(['message' => 'Appointment updated', 'appointment' => $appointment]);
    }

    public function destroy(string $id): JsonResponse
    {
        HealthAppointment::findOrFail($id)->delete();
        return response()->json(['message' => 'Appointment deleted']);
    }
}
