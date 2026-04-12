<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DropdownController
 *
 * Single endpoint: GET /dropdowns
 *
 * Returns every list that any frontend form needs to populate a <select>
 * or autocomplete field — one request instead of ~12 separate calls.
 *
 * All queries are lightweight (id + display columns only).
 * Missing tables are silently returned as empty arrays so the frontend
 * does not need to handle 500 errors during provisioning.
 */
class DropdownController extends Controller
{
    public function all(): JsonResponse
    {
        return response()->json([
            'academic_years'   => $this->academicYears(),
            'terms'            => $this->terms(),
            'departments'      => $this->departments(),
            'classes'          => $this->classes(),
            'arms'             => $this->arms(),
            'subjects'         => $this->subjects(),
            'teachers'         => $this->teachers(),
            'grading_systems'  => $this->gradingSystems(),
            'students'         => $this->students(),
            'drivers'          => $this->drivers(),
            'vehicles'         => $this->vehicles(),
            'transport_routes' => $this->transportRoutes(),
            'hostel_rooms'     => $this->hostelRooms(),
            'inventory_categories' => $this->inventoryCategories(),
            'result_configurations' => $this->resultConfigurations(),
        ]);
    }

    // ── Private fetchers ──────────────────────────────────────────────────────

    private function academicYears(): array
    {
        return $this->safe(fn () =>
            DB::table('academic_years')
                ->select('id', 'name', 'start_date', 'end_date', 'is_current')
                ->orderByDesc('is_current')
                ->orderByDesc('start_date')
                ->get()->toArray()
        );
    }

    private function terms(): array
    {
        return $this->safe(fn () =>
            DB::table('terms')
                ->select('id', 'name', 'academic_year_id', 'start_date', 'end_date', 'is_current')
                ->orderByDesc('is_current')
                ->orderBy('academic_year_id')
                ->orderBy('start_date')
                ->get()->toArray()
        );
    }

    private function departments(): array
    {
        return $this->safe(fn () =>
            DB::table('departments as d')
                ->leftJoin('teachers as t', 'd.head_id', '=', 't.id')
                ->select(
                    'd.id',
                    'd.name',
                    'd.description',
                    'd.status',
                    'd.head_id',
                    DB::raw("CONCAT(COALESCE(t.first_name,''), ' ', COALESCE(t.last_name,'')) as head_name")
                )
                ->where('d.status', 'active')
                ->orderBy('d.name')
                ->get()->toArray()
        );
    }

    private function classes(): array
    {
        return $this->safe(fn () =>
            DB::table('classes')
                ->select('id', 'name', 'level', 'section_type', 'academic_year_id', 'capacity')
                ->orderBy('name')
                ->get()->toArray()
        );
    }

    private function arms(): array
    {
        // Arms are global; they are associated to classes via the class_arm pivot.
        // Return each arm with a JSON-encoded list of the class IDs it belongs to
        // so the frontend can filter "arms available for class X" client-side.
        return $this->safe(fn () =>
            DB::table('arms as a')
                ->leftJoin(
                    DB::raw('(SELECT arm_id, GROUP_CONCAT(class_id) AS class_ids FROM class_arm GROUP BY arm_id) AS ca'),
                    'ca.arm_id', '=', 'a.id'
                )
                ->select('a.id', 'a.name', 'a.description', 'a.status', 'ca.class_ids')
                ->where('a.status', 'active')
                ->orderBy('a.name')
                ->get()
                ->map(function ($arm) {
                    $arm->class_ids = $arm->class_ids
                        ? array_map('intval', explode(',', $arm->class_ids))
                        : [];
                    return $arm;
                })
                ->toArray()
        );
    }

    private function subjects(): array
    {
        return $this->safe(fn () =>
            DB::table('subjects as s')
                ->leftJoin('departments as d', 's.department_id', '=', 'd.id')
                ->select(
                    's.id', 's.name', 's.code',
                    's.department_id', 'd.name as department_name',
                    's.class_id'
                )
                ->orderBy('s.name')
                ->get()->toArray()
        );
    }

    private function teachers(): array
    {
        return $this->safe(fn () =>
            DB::table('teachers as t')
                ->leftJoin('departments as d', 't.department_id', '=', 'd.id')
                ->select(
                    't.id', 't.employee_id',
                    't.first_name', 't.last_name',
                    DB::raw("CONCAT(COALESCE(t.first_name,''), ' ', COALESCE(t.last_name,'')) as full_name"),
                    't.department_id', 'd.name as department_name',
                    't.status'
                )
                ->where('t.status', 'active')
                ->orderBy('t.first_name')
                ->orderBy('t.last_name')
                ->get()->toArray()
        );
    }

    private function gradingSystems(): array
    {
        return $this->safe(fn () =>
            DB::table('grading_systems')
                ->select('id', 'name', 'is_default')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()->toArray()
        );
    }

    private function students(): array
    {
        // Students list is used in small dropdowns (e.g., attendance, transport pickup).
        // Return active students with class+arm for scoped dropdowns.
        return $this->safe(fn () =>
            DB::table('students as s')
                ->leftJoin('classes as c', 's.class_id', '=', 'c.id')
                ->leftJoin('arms as a', 's.arm_id', '=', 'a.id')
                ->select(
                    's.id', 's.admission_number',
                    's.first_name', 's.last_name',
                    DB::raw("CONCAT(COALESCE(s.first_name,''), ' ', COALESCE(s.last_name,'')) as full_name"),
                    's.class_id', 'c.name as class_name',
                    's.arm_id', 'a.name as arm_name'
                )
                ->where('s.status', 'active')
                ->orderBy('c.name')
                ->orderBy('s.first_name')
                ->orderBy('s.last_name')
                ->get()->toArray()
        );
    }

    private function drivers(): array
    {
        return $this->safe(fn () =>
            DB::table('drivers')
                ->select('id', 'name', 'license_number', 'phone', 'status')
                ->where('status', 'active')
                ->orderBy('name')
                ->get()->toArray()
        );
    }

    private function vehicles(): array
    {
        return $this->safe(fn () =>
            DB::table('vehicles')
                ->select('id', 'plate_number', 'make', 'model', 'capacity', 'status')
                ->where('status', 'active')
                ->orderBy('plate_number')
                ->get()->toArray()
        );
    }

    private function transportRoutes(): array
    {
        return $this->safe(fn () =>
            DB::table('transport_routes')
                ->select('id', 'name', 'description')
                ->orderBy('name')
                ->get()->toArray()
        );
    }

    private function hostelRooms(): array
    {
        return $this->safe(function () {
            if (! Schema::hasTable('hostel_rooms')) return [];
            return DB::table('hostel_rooms')
                ->select('id', 'room_number', 'room_type', 'capacity', 'status')
                ->where('status', 'available')
                ->orderBy('room_number')
                ->get()->toArray();
        });
    }

    private function inventoryCategories(): array
    {
        return $this->safe(function () {
            if (! Schema::hasTable('inventory_categories')) return [];
            return DB::table('inventory_categories')
                ->select('id', 'name', 'description')
                ->orderBy('name')
                ->get()->toArray();
        });
    }

    private function resultConfigurations(): array
    {
        return $this->safe(function () {
            if (! Schema::hasTable('result_configurations')) return [];
            return DB::table('result_configurations')
                ->select('id', 'name', 'section_type', 'ca_weight', 'exam_weight', 'pass_mark', 'grade_style', 'is_active')
                ->where('is_active', true)
                ->orderBy('section_type')
                ->get()->toArray();
        });
    }

    // ── Helper: never let one failing query break the whole response ──────────

    private function safe(callable $fn): array
    {
        try {
            $result = $fn();
            return is_array($result) ? $result : (array) $result;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
