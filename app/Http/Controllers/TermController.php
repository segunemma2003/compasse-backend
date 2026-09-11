<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TermController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * Pass ?academic_year_id=X to scope to one session explicitly (e.g. the
     * Enroll Student form, once a specific year is picked there). With no
     * filter, defaults to the CURRENT academic year's terms rather than
     * every term ever created across every session — screens that just call
     * GET /terms and render the result were showing the school's entire
     * multi-year history in one dropdown.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Term::with('academicYear:id,name')->orderBy('academic_year_id')->orderBy('name');

            if ($request->filled('academic_year_id')) {
                $query->where('academic_year_id', $request->academic_year_id);
            } elseif (!$request->boolean('all')) {
                $currentYear = AcademicYear::where('is_current', true)->first();
                if ($currentYear) {
                    $query->where('academic_year_id', $currentYear->id);
                }
                // No current year set at all: fall through and return every
                // term, same as before — better than showing an empty list
                // with no way to tell why.
            }

            return response()->json(['data' => $query->get()]);
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
            'academic_year_id' => 'required|exists:academic_years,id',
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

        $termData = array_merge($request->all(), ['school_id' => $schoolId]);

        $term = DB::transaction(function () use ($termData, $schoolId) {
            if ($termData['is_current'] ?? false) {
                Term::where('school_id', $schoolId)->update(['is_current' => false]);
            }
            $term = Term::create($termData);
            if ($term->is_current) {
                $this->makeTermsYearCurrent($term);
            }
            return $term;
        });

        return response()->json($term, 201);
    }

    /**
     * Marking a term current implies its academic year is the live one too
     * — mirrors AcademicYearController::clearCurrentTermFromOtherYears()'s
     * side of the same invariant. Without this, picking a term from a
     * non-current year (e.g. while still finishing last year's records)
     * left "current term" and "current year" pointing at two different
     * sessions — confirmed live on 2 of 7 tenants — which breaks anything
     * that filters on both together (results, attendance, fees).
     */
    private function makeTermsYearCurrent(Term $term): void
    {
        AcademicYear::where('school_id', $term->school_id)
            ->where('id', '!=', $term->academic_year_id)
            ->update(['is_current' => false]);
        AcademicYear::where('id', $term->academic_year_id)->update(['is_current' => true]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Term $term): JsonResponse
    {
        return response()->json($term);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Term $term): JsonResponse
    {
        $request->validate([
            'academic_year_id' => 'sometimes|exists:academic_years,id',
            'name' => 'sometimes|string|max:255',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
            'is_current' => 'sometimes|boolean',
        ]);

        DB::transaction(function () use ($request, $term) {
            if ($request->boolean('is_current')) {
                Term::where('school_id', $term->school_id)
                    ->where('id', '!=', $term->id)
                    ->update(['is_current' => false]);
            }
            $term->update($request->all());
            if ($term->is_current) {
                $this->makeTermsYearCurrent($term);
            }
        });

        return response()->json($term->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Term $term): JsonResponse
    {
        $term->delete();
        return response()->json(null, 204);
    }
}
