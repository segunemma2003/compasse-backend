<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AcademicYearController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        try {
            $academicYears = AcademicYear::orderByDesc('is_current')->orderByDesc('start_date')->get();
            return response()->json(['data' => $academicYears]);
        } catch (\Exception $e) {
            return response()->json(['data' => []]);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'is_current' => 'boolean',
        ]);

        // Auto-get school_id from tenant context
        $schoolId = $this->getSchoolIdFromTenant($request);
        if (!$schoolId) {
            return response()->json([
                'error' => 'School not found',
                'message' => 'Unable to determine school from tenant context'
            ], 400);
        }

        $academicYearData = array_merge($request->all(), ['school_id' => $schoolId]);

        $academicYear = DB::transaction(function () use ($academicYearData, $schoolId) {
            if ($academicYearData['is_current'] ?? false) {
                AcademicYear::where('school_id', $schoolId)->update(['is_current' => false]);
            }
            return AcademicYear::create($academicYearData);
        });

        return response()->json($academicYear, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(AcademicYear $academicYear): JsonResponse
    {
        return response()->json($academicYear);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, AcademicYear $academicYear): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
            'is_current' => 'sometimes|boolean',
        ]);

        DB::transaction(function () use ($request, $academicYear) {
            if ($request->boolean('is_current')) {
                AcademicYear::where('school_id', $academicYear->school_id)
                    ->where('id', '!=', $academicYear->id)
                    ->update(['is_current' => false]);
            }
            $academicYear->update($request->all());
        });

        return response()->json($academicYear->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AcademicYear $academicYear): JsonResponse
    {
        try {
            $academicYear->delete();

            return response()->json(null, 204);
        } catch (QueryException $e) {
            return response()->json([
                'error' => 'Cannot delete academic year',
                'message' => 'This year is still linked to classes, terms, exams, or results. Remove or reassign those records first.',
            ], 409);
        }
    }
}
