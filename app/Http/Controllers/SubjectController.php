<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SubjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        try {
            $subjects = Subject::with(['department', 'teachers'])->get();
            return response()->json($subjects);
        } catch (\Exception $e) {
            return response()->json([]);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'department_id' => 'required|exists:departments,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:10',
            'description' => 'nullable|string',
            'credits' => 'nullable|integer|min:1',
        ]);

        // Auto-get school_id from tenant context
        $schoolId = $this->getSchoolIdFromTenant($request);
        if (!$schoolId) {
            return response()->json([
                'error' => 'School not found',
                'message' => 'Unable to determine school from tenant context'
            ], 400);
        }

        $subjectData = array_merge($request->all(), ['school_id' => $schoolId]);
        $subject = Subject::create($subjectData);

        return response()->json($subject, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Subject $subject): JsonResponse
    {
        $subject->load(['department', 'teachers']);
        return response()->json($subject);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Subject $subject): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|max:10',
            'description' => 'sometimes|string',
            'credits' => 'sometimes|integer|min:1',
        ]);

        $subject->update($request->all());

        return response()->json($subject);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Subject $subject): JsonResponse
    {
        $subject->delete();
        return response()->json(null, 204);
    }
}
