<?php

namespace App\Http\Controllers;

use App\Jobs\SendEmailJob;
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
    private const STAFF_ROLES = ['admin','staff','accountant','librarian','driver','security','cleaner','caterer','nurse','school_admin'];

    public function index(Request $request): JsonResponse
    {
        $staffRoles = self::STAFF_ROLES;

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
                ])
                // Read with the query builder, so soft-deleted rows must be
                // excluded by hand.
                ->whereNull('deleted_at');

            // Secondary: users with staff roles who have no staff record.
            $usersQuery = DB::table('users')
                ->leftJoin('staff', 'staff.user_id', '=', 'users.id')
                ->whereIn('users.role', $staffRoles)
                ->whereNull('users.deleted_at')
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
        if ($denied = $this->requireCapability($request, 'staff.manage')) {
            return $denied;
        }

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

            // Queue welcome/credentials email — this was previously never sent,
            // leaving new staff with no way to learn their login details other
            // than being told verbally by whoever created the account.
            $body = "Hello {$request->first_name},\n\n"
                . "Your staff account has been created.\n\n"
                . "Login Email: {$email}\n"
                . "Password: Password@123\n\n"
                . "Please log in and change your password.\n\n"
                . "Regards,\nSchool Administration";

            $school = $this->getSchoolFromRequest($request);
            SendEmailJob::dispatch(
                to:       $email,
                subject:  'Your Staff Login Credentials',
                body:     $body,
                schoolId: $school ? (string) $school->id : null,
                type:     'credentials',
            )->onQueue('emails');

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
        if ($denied = $this->requireCapability($request, 'staff.manage')) {
            return $denied;
        }

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
            'employment_date' => 'nullable|date',
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
                    'role', 'department', 'employment_date', 'status'
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
    /**
     * DELETE /staff/{id}[?source=user_record]
     *
     * The list mixes staff rows with staff-role users that have no staff row
     * (source=user_record, whose id is the *user* id). Deleting only the
     * staff row used to leave the user behind, so the person came straight
     * back through that second branch on refresh.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'staff.manage')) {
            return $denied;
        }

        $error = $this->deleteStaffMember($request, (int) $id, (string) $request->query('source', 'staff_record'));
        if ($error) {
            return response()->json(['error' => $error[1]], $error[0]);
        }

        DashboardController::bustCache();

        return response()->json(['message' => 'Staff deleted successfully']);
    }

    /**
     * POST /staff/bulk-delete  { items: [{ id, source }] }
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'staff.manage')) {
            return $denied;
        }

        $validated = $request->validate([
            'items'          => 'required|array|min:1|max:500',
            'items.*.id'     => 'required|integer',
            'items.*.source' => 'nullable|in:staff_record,user_record',
        ]);

        $deleted = 0;
        $failed  = [];
        foreach ($validated['items'] as $item) {
            $error = $this->deleteStaffMember($request, (int) $item['id'], $item['source'] ?? 'staff_record');
            if ($error) {
                $failed[] = ['id' => $item['id'], 'error' => $error[1]];
            } else {
                $deleted++;
            }
        }

        DashboardController::bustCache();

        return response()->json([
            'message' => "{$deleted} staff deleted" . ($failed ? ', ' . count($failed) . ' skipped' : ''),
            'deleted' => $deleted,
            'failed'  => $failed,
        ]);
    }

    /**
     * POST /staff/bulk-status  { items: [{ id, source }], status }
     */
    public function bulkStatus(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'staff.manage')) {
            return $denied;
        }

        $validated = $request->validate([
            'items'          => 'required|array|min:1|max:500',
            'items.*.id'     => 'required|integer',
            'items.*.source' => 'nullable|in:staff_record,user_record',
            'status'         => 'required|in:active,inactive,suspended',
        ]);

        $staffIds = collect($validated['items'])->where('source', '!=', 'user_record')->pluck('id');
        $userIds  = collect($validated['items'])->where('source', 'user_record')->pluck('id')
            ->merge(DB::table('staff')->whereIn('id', $staffIds)->whereNotNull('user_id')->pluck('user_id'))
            // Never lock the person doing this out of their own account.
            ->reject(fn ($uid) => (int) $uid === (int) $request->user()?->id);

        $updated = DB::table('staff')->whereIn('id', $staffIds)->update(['status' => $validated['status'], 'updated_at' => now()]);
        User::whereIn('id', $userIds)->update(['status' => $validated['status']]);

        DashboardController::bustCache();

        return response()->json(['message' => 'Status updated', 'updated' => max($updated, $userIds->count())]);
    }

    /**
     * Soft-deletes the staff row (if any) and its login account, revoking
     * tokens — same as deleting a student. Returns [httpStatus, message]
     * when the member can't be deleted, null on success.
     */
    private function deleteStaffMember(Request $request, int $id, string $source): ?array
    {
        if ($source === 'user_record') {
            $staff = null;
            $user  = User::whereIn('role', self::STAFF_ROLES)->find($id);
            if (! $user) {
                return [404, 'Staff not found'];
            }
        } else {
            $staff = DB::table('staff')->whereNull('deleted_at')->find($id);
            if (! $staff) {
                return [404, 'Staff not found'];
            }
            $user = $staff->user_id ? User::find($staff->user_id) : null;
        }

        if ($user && (int) $user->id === (int) $request->user()?->id) {
            return [422, 'You cannot delete your own account.'];
        }
        if ($user && $user->role === 'school_admin' && User::where('role', 'school_admin')->count() <= 1) {
            return [422, 'You cannot delete the only school admin.'];
        }

        DB::transaction(function () use ($staff, $user) {
            if ($staff) {
                DB::table('staff')->where('id', $staff->id)->update(['deleted_at' => now()]);
            }
            // Only remove the login if it belongs to a staff role — never a
            // teacher/student account that a staff row happens to point at.
            if ($user && in_array($user->role, self::STAFF_ROLES, true)) {
                $user->tokens()->delete();
                $user->delete();
            }
        });

        return null;
    }
}
