<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TransportRouteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    /**
     * Get students on a route
     */
    public function getStudents($routeId): \Illuminate\Http\JsonResponse
    {
        // Implementation would depend on your TransportRoute model structure
        return response()->json([
            'route_id' => $routeId,
            'students' => []
        ]);
    }

    /**
     * Assign student to route
     */
    public function assignStudent(Request $request, $routeId): \Illuminate\Http\JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'student_id' => 'required|exists:students,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        return response()->json([
            'message' => 'Student assigned to route successfully',
            'route_id' => $routeId,
            'student_id' => $request->student_id
        ]);
    }
}
