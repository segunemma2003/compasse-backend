<?php

namespace App\Http\Controllers;

use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TermController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        try {
            $terms = Term::all();
            return response()->json($terms);
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
            'academic_year_id' => 'required|exists:academic_years,id',
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'is_current' => 'boolean',
        ]);

        $term = Term::create($request->all());

        return response()->json($term, 201);
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

        $term->update($request->all());

        return response()->json($term);
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
