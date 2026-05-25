<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StaffController extends Controller
{
    /**
     * List staff.
     * Returns records from the `staff` table UNION users with staff-type roles
     * who have no corresponding staff record (e.g. added via the Users page).
     */
    public function index(Request $request): JsonResponse
    {
        $staffRoles = ['admin','staff','accountant','librarian','driver','security','cleaner','caterer','nurse','school_admin'];

        try {
            // Primary: staff table records.
            $staffQuery = DB::table('staff')
                ->select([
                    'id', 'first_name', 'last_name',
                    DB::raw("'' AS middle_name"),
                    'email', 'phone', 'employee_id', 'role',
                    'department',
                    DB::raw("NULL AS employment_date"),
                    'status', 'user_id',
                    DB::raw("'staff_record' AS source"),
                ]);

            // Secondary: users with staff roles who have no staff record.
            $usersQuery = DB::table('users')
                ->leftJoin('staff', 'staff.user_id', '=', 'users.id')
                ->whereIn('users.role', $staffRoles)
                ->whereNull('staff.id')
                ->select([
                    'users.id',
                    DB::raw("SUBSTRING_INDEX(users.name, ' ', 1) AS first_name"),
                    DB::raw("SUBSTRING_INDEX(users.name, ' ', -1) AS last_name"),
                    DB::raw("'' AS middle_name"),
                    'users.email', 'users.phone',
                    DB::raw("NULL AS employee_id"),
                    'users.role',
                    DB::raw("NULL AS department"),
                    DB::raw("NULL AS employment_date"),
                    'users.status', 'users.id AS user_id',
                    DB::raw("'user_record' AS source"),
                ]);

            // Apply shared filters to both sides before UNION.
            foreach ([$staffQuery, $usersQuery] as $q) {
                if ($request->filled('role')) {
                    $q->where('role', $request->role);
                }
                if ($request->filled('status')) {
                    $q->where('status', $request->status);
                }
            }

            if ($request->filled('search')) {
                $s = $request->search;
                $staffQuery->where(function ($q) use ($s) {
                    $q->where('first_name',   'like', "%{$s}%")
                      ->orWhere('last_name',  'like', "%{$s}%")
                      ->orWhere('email',      'like', "%{$s}%")
                      ->orWhere('employee_id','like', "%{$s}%");
                });
                $usersQuery->where(function ($q) use ($s) {
                    $q->where('users.name',  'like', "%{$s}%")
                      ->orWhere('users.email','like', "%{$s}%");
                });
            }

            if ($request->filled('department')) {
                $staffQuery->where('department', $request->department);
                // users table has no department column; exclude from department filter
                $usersQuery->whereRaw('1=0');
            }

            $perPage = (int) $request->get('per_page', 20);
            $page    = (int) $request->get('page', 1);
            $offset  = ($page - 1) * $perPage;

            // Execute UNION and paginate manually.
            $union      = $staffQuery->unionAll($usersQuery)->orderBy('first_name');
            $totalSql   = "SELECT COUNT(*) as agg FROM ({$union->toSql()}) AS u";
            $total      = DB::selectOne($totalSql, $union->getBindings())->agg ?? 0;

            $rows = DB::table(DB::raw("({$union->toSql()}) AS u"))
                ->mergeBindings($union)
                ->offset($offset)
                ->limit($perPage)
                ->orderBy('first_name')
                ->get();

            $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
                $rows, $total, $perPage, $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            return response()->json($paginator);
        } catch (\Exception $e) {
            return response()->json([
                'data' => [], 'current_page' => 1, 'per_page' => 20, 'total' => 0,
                'error' => $e->getMessage(),
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
     * Create staff with auto-generated user account
     * Email pattern: firstname.lastname{id}@schoolurl
     * Password: Password@123
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'sometimes|string|max:50|unique:staff,employee_id',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'email' => 'sometimes|email|unique:staff,email',
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

        try {
            DB::beginTransaction();

            // Auto-get school_id from tenant context (no need to pass it in request)
            $schoolId = $this->getSchoolIdFromTenant($request);
            if (!$schoolId) {
                return response()->json([
                    'error' => 'School not found',
                    'message' => 'Unable to determine school from tenant context'
                ], 400);
            }

            // Auto-generate employee ID if not provided
            $employeeId = $request->employee_id ?? $this->generateEmployeeId($schoolId);

            // Generate temporary email if not provided
            $tempEmail = $request->email ?? "temp.{$employeeId}@temp.samschool.com";

            // Create staff record first with temporary email
            $staffId = DB::table('staff')->insertGetId([
                'school_id' => $schoolId,
                'employee_id' => $employeeId,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'middle_name' => $request->middle_name,
                'email' => $tempEmail,
                'phone' => $request->phone,
                'role' => $request->role,
                'department' => $request->department,
                'employment_date' => $request->employment_date,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Generate proper email with staff ID
            $email = $request->email ?? $this->generateStaffEmail(
                $request->first_name,
                $request->last_name,
                $staffId,
                $schoolId
            );

            // Update staff with proper generated email
            if (!$request->email) {
                DB::table('staff')->where('id', $staffId)->update(['email' => $email]);
            }

            // Create user account for staff
            $user = User::create([
                'name' => trim($request->first_name . ' ' . $request->last_name),
                'email' => $email,
                'password' => Hash::make('Password@123'), // Standard password for all staff
                'role' => $request->role,
                'status' => 'active',
            ]);

            // Link user to staff
            DB::table('staff')->where('id', $staffId)->update(['user_id' => $user->id]);

            DB::commit();

            $staff = DB::table('staff')->find($staffId);

            return response()->json([
                'message' => 'Staff created successfully',
                'staff' => $staff,
                'login_credentials' => [
                    'email' => $email,
                    'password' => 'Password@123',
                    'note' => 'Staff should change password on first login'
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Staff creation failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate employee ID
     */
    protected function generateEmployeeId(int $schoolId): string
    {
        $school = DB::table('schools')->find($schoolId);
        if (!$school) {
            throw new \Exception('School not found');
        }

        $schoolAbbr = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $school->name), 0, 3));
        $currentYear = date('Y');
        
        $lastStaff = DB::table('staff')
            ->where('school_id', $schoolId)
            ->where('employee_id', 'like', $schoolAbbr . $currentYear . '%')
            ->orderBy('employee_id', 'desc')
            ->first();

        $sequence = 1;
        if ($lastStaff && $lastStaff->employee_id) {
            $lastSequence = (int) substr($lastStaff->employee_id, -4);
            $sequence = $lastSequence + 1;
        }

        return $schoolAbbr . $currentYear . 'ST' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Generate staff email: firstname.lastname{id}@schoolurl
     */
    protected function generateStaffEmail(string $firstName, string $lastName, int $staffId, int $schoolId): string
    {
        $school = DB::table('schools')->find($schoolId);
        if (!$school) {
            throw new \Exception('School not found');
        }

        // Extract domain from school website or use subdomain
        if ($school->website) {
            // Remove http://, https://, www. from website
            $domain = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $school->website);
            // Remove trailing slash
            $domain = rtrim($domain, '/');
        } else {
            // Fallback to subdomain
            $tenant = DB::table('tenants')->find($school->tenant_id);
            $domain = $tenant ? $tenant->subdomain . '.samschool.com' : strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $school->name)) . '.samschool.com';
        }

        // Clean names
        $cleanFirstName = strtolower(preg_replace('/[^a-zA-Z]/', '', $firstName));
        $cleanLastName = strtolower(preg_replace('/[^a-zA-Z]/', '', $lastName));

        return $cleanFirstName . '.' . $cleanLastName . $staffId . '@' . $domain;
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
