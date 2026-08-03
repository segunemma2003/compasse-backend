<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Models\Teacher;
use App\Models\TeacherPeriodicReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TeacherPeriodicReportController extends Controller
{
    private const ADMIN_ROLES = ['school_admin', 'principal', 'vice_principal', 'admin'];

    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    private function resolveTeacherId(): ?int
    {
        $user = Auth::user();
        if (! $user) {
            return null;
        }

        return Teacher::where('user_id', $user->id)->value('id');
    }

    public function index(Request $request): JsonResponse
    {
        $schoolId = $this->school($request)?->id ?? 0;
        $user     = Auth::user();
        $query    = TeacherPeriodicReport::with(['class:id,name', 'teacher:id,first_name,last_name'])
            ->where('school_id', $schoolId);

        if ($request->filled('period_type')) {
            $query->where('period_type', $request->period_type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        if ($user && ! in_array($user->role, self::ADMIN_ROLES, true)) {
            $tid = $this->resolveTeacherId();
            if (! $tid) {
                return response()->json(['data' => [], 'message' => 'No teacher profile linked to this account.']);
            }
            $query->where('teacher_id', $tid);
        } elseif ($request->filled('teacher_id')) {
            $query->where('teacher_id', $request->teacher_id);
        }

        $rows = $query->orderByDesc('period_start')
            ->paginate(min((int) $request->get('per_page', 20), 50));

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_id'         => 'nullable|exists:classes,id',
            'period_type'      => 'required|in:weekly,monthly',
            'period_start'     => 'required|date',
            'period_end'       => 'required|date|after_or_equal:period_start',
            'title'            => 'nullable|string|max:200',
            'summary'          => 'required|string|max:10000',
            'challenges'       => 'nullable|string|max:5000',
            'recommendations'  => 'nullable|string|max:5000',
        ]);

        $teacherId = $this->resolveTeacherId();
        if (! $teacherId) {
            return response()->json(['error' => 'Teacher profile required to submit reports.'], 403);
        }

        $school = $this->school($request);
        $report = TeacherPeriodicReport::create([
            ...$data,
            'school_id'   => $school?->id,
            'teacher_id'  => $teacherId,
            'title'       => $data['title'] ?? ucfirst($data['period_type']) . ' report',
            'status'      => 'draft',
            'created_by'  => Auth::id(),
        ]);

        return response()->json(['message' => 'Report saved', 'report' => $report->load('class')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $report = TeacherPeriodicReport::findOrFail($id);
        if ($denied = $this->ensureCanEdit($report)) {
            return $denied;
        }

        if ($report->status === 'submitted') {
            return response()->json(['error' => 'Submitted reports cannot be edited.'], 422);
        }

        $data = $request->validate([
            'class_id'        => 'nullable|exists:classes,id',
            'period_start'    => 'sometimes|date',
            'period_end'      => 'sometimes|date',
            'title'           => 'nullable|string|max:200',
            'summary'         => 'sometimes|string|max:10000',
            'challenges'      => 'nullable|string|max:5000',
            'recommendations' => 'nullable|string|max:5000',
        ]);

        $report->update($data);

        return response()->json(['message' => 'Report updated', 'report' => $report->fresh('class')]);
    }

    public function submit(int $id): JsonResponse
    {
        $report = TeacherPeriodicReport::findOrFail($id);
        if ($denied = $this->ensureCanEdit($report)) {
            return $denied;
        }

        $report->update([
            'status'       => 'submitted',
            'submitted_at' => now(),
        ]);

        return response()->json(['message' => 'Report submitted to administration', 'report' => $report]);
    }

    private function ensureCanEdit(TeacherPeriodicReport $report): ?JsonResponse
    {
        $user = Auth::user();
        if ($user && in_array($user->role, self::ADMIN_ROLES, true)) {
            return null;
        }

        $tid = $this->resolveTeacherId();
        if (! $tid || (int) $report->teacher_id !== (int) $tid) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return null;
    }
}
