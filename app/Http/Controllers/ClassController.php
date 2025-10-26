<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ClassController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $classes = ClassModel::with(['arms', 'students', 'teachers'])->get();
        return response()->json($classes);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'school_id' => 'required|exists:schools,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'academic_year_id' => 'required|exists:academic_years,id',
            'term_id' => 'required|exists:terms,id',
            'capacity' => 'nullable|integer|min:1',
        ]);

        $class = ClassModel::create($request->all());

        return response()->json($class, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(ClassModel $class): JsonResponse
    {
        $class->load(['arms', 'students', 'teachers']);
        return response()->json($class);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ClassModel $class): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'capacity' => 'sometimes|integer|min:1',
        ]);

        $class->update($request->all());

        return response()->json($class);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ClassModel $class): JsonResponse
    {
        $class->delete();
        return response()->json(null, 204);
    }
}
