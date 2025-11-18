<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class StaffController extends Controller
{
    /**
     * List staff
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = DB::table('staff');

            if ($request->has('role')) {
                $query->where('role', $request->role);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('department')) {
                $query->where('department', $request->department);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('employee_id', 'like', "%{$search}%");
                });
            }

            $staff = $query->orderBy('first_name')->paginate($request->get('per_page', 15));

            return response()->json($staff);
        } catch (\Exception $e) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'per_page' => 15,
                'total' => 0
            ]);
        }
    }

    /**
     * Get staff details
     */
    public function show($id): JsonResponse
    {
        $staff = DB::table('staff')->find($id);

        if (!$staff) {
            return response()->json(['error' => 'Staff not found'], 404);
        }

        return response()->json(['staff' => $staff]);
    }

    /**
     * Create staff
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|string|max:50|unique:staff,employee_id',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'email' => 'required|email|unique:staff,email',
            'phone' => 'nullable|string|max:20',
            'role' => 'required|in:admin,staff,accountant,librarian,driver,security,cleaner,caterer,nurse',
            'department' => 'nullable|string|max:255',
            'employment_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $staffId = DB::table('staff')->insertGetId([
            'school_id' => $request->school_id ?? 1,
            'employee_id' => $request->employee_id,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'middle_name' => $request->middle_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'role' => $request->role,
            'department' => $request->department,
            'employment_date' => $request->employment_date,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $staff = DB::table('staff')->find($staffId);

        return response()->json([
            'message' => 'Staff created successfully',
            'staff' => $staff
        ], 201);
    }

    /**
     * Update staff
     */
    public function update(Request $request, $id): JsonResponse
    {
        $staff = DB::table('staff')->find($id);

        if (!$staff) {
            return response()->json(['error' => 'Staff not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:staff,email,' . $id,
            'phone' => 'nullable|string|max:20',
            'role' => 'sometimes|in:admin,staff,accountant,librarian,driver,security,cleaner,caterer,nurse',
            'department' => 'nullable|string|max:255',
            'status' => 'sometimes|in:active,inactive,suspended',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        DB::table('staff')
            ->where('id', $id)
            ->update(array_merge(
                $request->only([
                    'first_name', 'last_name', 'middle_name', 'email', 'phone',
                    'role', 'department', 'status'
                ]),
                ['updated_at' => now()]
            ));

        $staff = DB::table('staff')->find($id);

        return response()->json([
            'message' => 'Staff updated successfully',
            'staff' => $staff
        ]);
    }

    /**
     * Delete staff
     */
    public function destroy($id): JsonResponse
    {
        $staff = DB::table('staff')->find($id);

        if (!$staff) {
            return response()->json(['error' => 'Staff not found'], 404);
        }

        DB::table('staff')->where('id', $id)->delete();

        return response()->json([
            'message' => 'Staff deleted successfully'
        ]);
    }
}
