<?php

namespace App\Http\Controllers;

use App\Models\ClassLevel;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ClassLevelController extends Controller
{
    /**
     * List all class levels ordered by `order` then name.
     */
    public function index(): JsonResponse
    {
        $levels = ClassLevel::withCount('classes')
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $levels]);
    }

    /**
     * Create a new class level.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:100',
            'order'       => 'nullable|integer|min:0',
            'description' => 'nullable|string|max:500',
            'status'      => 'nullable|in:active,inactive',
        ]);

        $school = School::first();
        if (! $school) {
            return response()->json(['error' => 'School not found'], 400);
        }

        $level = ClassLevel::create([
            'school_id'   => $school->id,
            'name'        => $request->input('name'),
            'order'       => $request->input('order', 0),
            'description' => $request->input('description'),
            'status'      => $request->input('status', 'active'),
        ]);

        $level->loadCount('classes');

        return response()->json([
            'message' => 'Class level created.',
            'level'   => $level,
        ], 201);
    }

    /**
     * Update a class level.
     */
    public function update(Request $request, ClassLevel $classLevel): JsonResponse
    {
        $request->validate([
            'name'        => 'sometimes|string|max:100',
            'order'       => 'nullable|integer|min:0',
            'description' => 'nullable|string|max:500',
            'status'      => 'nullable|in:active,inactive',
        ]);

        $classLevel->update($request->only(['name', 'order', 'description', 'status']));
        $classLevel->loadCount('classes');

        return response()->json([
            'message' => 'Class level updated.',
            'level'   => $classLevel,
        ]);
    }

    /**
     * Delete a class level. Blocks if classes are assigned to it.
     */
    public function destroy(ClassLevel $classLevel): JsonResponse
    {
        $count = $classLevel->classes()->count();
        if ($count > 0) {
            return response()->json([
                'error'   => 'Cannot delete class level',
                'message' => "Reassign or remove the {$count} class(es) using this level first.",
            ], 422);
        }

        $classLevel->delete();

        return response()->json(['message' => 'Class level deleted.']);
    }
}
