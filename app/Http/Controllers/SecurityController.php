<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SecurityController extends Controller
{
    // =========================================================================
    // VISITORS
    // =========================================================================

    public function visitorIndex(Request $request): JsonResponse
    {
        $query = DB::table('visitors');

        if ($request->filled('date'))   { $query->whereDate('entry_time', $request->date); }
        if ($request->boolean('active')){ $query->whereNull('exit_time'); }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('host_name', 'like', '%' . $request->search . '%');
            });
        }

        return response()->json([
            'visitors' => $query->orderByDesc('entry_time')->paginate(50),
        ]);
    }

    public function visitorStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'         => ['required', 'string', 'max:120'],
            'phone'        => ['nullable', 'string', 'max:20'],
            'email'        => ['nullable', 'email'],
            'id_type'      => ['nullable', 'string'],
            'id_number'    => ['nullable', 'string'],
            'purpose'      => ['required', 'string', 'max:255'],
            'host_name'    => ['nullable', 'string'],
            'host_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes'        => ['nullable', 'string'],
        ]);

        $id = DB::table('visitors')->insertGetId(array_merge($validated, [
            'entry_time'     => now(),
            'checked_in_by'  => Auth::id(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]));

        return response()->json(['visitor' => DB::table('visitors')->find($id)], 201);
    }

    public function visitorUpdate(Request $request, int $id): JsonResponse
    {
        $visitor = DB::table('visitors')->find($id);
        if (! $visitor) {
            return response()->json(['error' => 'Visitor not found'], 404);
        }

        $validated = $request->validate([
            'host_name'    => ['nullable', 'string'],
            'host_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes'        => ['nullable', 'string'],
            'badge_number' => ['nullable', 'string'],
        ]);

        DB::table('visitors')->where('id', $id)->update(array_merge($validated, ['updated_at' => now()]));

        return response()->json(['visitor' => DB::table('visitors')->find($id)]);
    }

    public function visitorExit(int $id): JsonResponse
    {
        $visitor = DB::table('visitors')->find($id);
        if (! $visitor) {
            return response()->json(['error' => 'Visitor not found'], 404);
        }
        if ($visitor->exit_time) {
            return response()->json(['error' => 'Visitor has already exited'], 422);
        }

        DB::table('visitors')->where('id', $id)->update([
            'exit_time'       => now(),
            'checked_out_by'  => Auth::id(),
            'updated_at'      => now(),
        ]);

        return response()->json(['message' => 'Visitor checked out.', 'visitor' => DB::table('visitors')->find($id)]);
    }

    // =========================================================================
    // GATE PASSES
    // =========================================================================

    public function gatePassIndex(Request $request): JsonResponse
    {
        $query = DB::table('gate_passes');

        if ($request->filled('type'))   { $query->where('type', $request->type); }
        if ($request->filled('date'))   { $query->whereDate('created_at', $request->date); }
        if ($request->filled('is_used')){ $query->where('is_used', $request->boolean('is_used')); }

        return response()->json([
            'gate_passes' => $query->orderByDesc('created_at')->paginate(50),
        ]);
    }

    public function gatePassStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'        => ['required', 'in:student_exit,staff_exit,visitor,delivery,other'],
            'issued_to'   => ['required', 'string', 'max:120'],
            'person_type' => ['required', 'in:student,staff,visitor,other'],
            'student_id'  => ['nullable', 'integer', 'exists:students,id'],
            'staff_id'    => ['nullable', 'integer', 'exists:staff,id'],
            'reason'      => ['required', 'string'],
            'valid_from'  => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after:valid_from'],
            'notes'       => ['nullable', 'string'],
        ]);

        $passNumber = strtoupper(now()->format('Ymd') . '-' . Str::random(6));

        $id = DB::table('gate_passes')->insertGetId(array_merge($validated, [
            'pass_number' => $passNumber,
            'is_used'     => false,
            'issued_by'   => Auth::id(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]));

        return response()->json(['gate_pass' => DB::table('gate_passes')->find($id)], 201);
    }

    public function gatePassUse(int $id): JsonResponse
    {
        $pass = DB::table('gate_passes')->find($id);
        if (! $pass) {
            return response()->json(['error' => 'Gate pass not found'], 404);
        }
        if ($pass->is_used) {
            return response()->json(['error' => 'Gate pass already used'], 422);
        }
        if (now()->gt($pass->valid_until)) {
            return response()->json(['error' => 'Gate pass has expired'], 422);
        }

        DB::table('gate_passes')->where('id', $id)->update([
            'is_used'    => true,
            'used_at'    => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Gate pass marked as used.', 'gate_pass' => DB::table('gate_passes')->find($id)]);
    }

    // =========================================================================
    // INCIDENTS
    // =========================================================================

    public function incidentIndex(Request $request): JsonResponse
    {
        $query = DB::table('security_incidents');

        if ($request->filled('type'))     { $query->where('type', $request->type); }
        if ($request->filled('status'))   { $query->where('status', $request->status); }
        if ($request->filled('severity')) { $query->where('severity', $request->severity); }
        if ($request->filled('from'))     { $query->where('reported_time', '>=', $request->from); }
        if ($request->filled('to'))       { $query->where('reported_time', '<=', $request->to); }

        return response()->json([
            'incidents' => $query->orderByDesc('reported_time')->paginate(50),
        ]);
    }

    public function incidentStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'        => ['required', 'in:theft,vandalism,trespassing,fight,accident,fire,unauthorized_access,suspicious_activity,other'],
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'location'    => ['nullable', 'string'],
            'severity'    => ['required', 'in:low,medium,high,critical'],
        ]);

        $id = DB::table('security_incidents')->insertGetId(array_merge($validated, [
            'status'        => 'open',
            'reported_time' => now(),
            'reported_by'   => Auth::id(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]));

        return response()->json(['incident' => DB::table('security_incidents')->find($id)], 201);
    }

    public function incidentUpdate(Request $request, int $id): JsonResponse
    {
        $incident = DB::table('security_incidents')->find($id);
        if (! $incident) {
            return response()->json(['error' => 'Incident not found'], 404);
        }

        $validated = $request->validate([
            'title'            => ['sometimes', 'string', 'max:255'],
            'description'      => ['sometimes', 'string'],
            'location'         => ['nullable', 'string'],
            'severity'         => ['sometimes', 'in:low,medium,high,critical'],
            'status'           => ['sometimes', 'in:open,investigating,resolved,closed'],
            'assigned_to'      => ['nullable', 'integer', 'exists:users,id'],
            'resolution_notes' => ['nullable', 'string'],
        ]);

        DB::table('security_incidents')->where('id', $id)->update(array_merge($validated, ['updated_at' => now()]));

        return response()->json(['incident' => DB::table('security_incidents')->find($id)]);
    }

    public function incidentResolve(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'resolution_notes' => ['required', 'string'],
        ]);

        DB::table('security_incidents')->where('id', $id)->update([
            'status'           => 'resolved',
            'resolved_time'    => now(),
            'resolution_notes' => $request->resolution_notes,
            'updated_at'       => now(),
        ]);

        return response()->json(['message' => 'Incident resolved.', 'incident' => DB::table('security_incidents')->find($id)]);
    }

    // =========================================================================
    // ACCESS LOGS
    // =========================================================================

    public function accessLogIndex(Request $request): JsonResponse
    {
        $query = DB::table('access_logs');

        if ($request->filled('location'))    { $query->where('location', $request->location); }
        if ($request->filled('person_type')) { $query->where('person_type', $request->person_type); }
        if ($request->filled('date'))        { $query->whereDate('accessed_at', $request->date); }
        if ($request->filled('granted'))     { $query->where('granted', $request->boolean('granted')); }

        return response()->json([
            'access_logs' => $query->orderByDesc('accessed_at')->paginate(100),
        ]);
    }

    public function accessLogStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'person_type'  => ['required', 'string'],
            'person_id'    => ['required', 'integer'],
            'location'     => ['required', 'string'],
            'direction'    => ['required', 'in:in,out'],
            'method'       => ['required', 'in:badge,manual,biometric,qr'],
            'device_id'    => ['nullable', 'string'],
            'granted'      => ['boolean'],
            'denial_reason'=> ['nullable', 'string'],
        ]);

        $id = DB::table('access_logs')->insertGetId(array_merge($validated, [
            'accessed_at' => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]));

        return response()->json(['access_log' => DB::table('access_logs')->find($id)], 201);
    }
}
