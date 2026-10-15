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
    private const STAFF_ROLES = ['admin','staff','accountant','librarian','driver','security','cleaner','caterer','nurse','school_admin','care_assistant','sen_facilitator','sen_therapist','counsellor'];

    /** Profile fields shown and edited for everyone who works at the school. */
    private const PROFILE = ['first_name', 'last_name', 'middle_name', 'email', 'phone', 'gender', 'date_of_birth', 'employment_date',
        'address', 'emergency_contact_name', 'emergency_contact_phone', 'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship'];

    /** Every role a member of staff can be given here (leadership, teaching and support). */
    private const ALL_ROLES = ['principal', 'vice_principal', 'admin', 'teacher', 'class_teacher', 'subject_teacher', 'year_tutor', 'hod',
        'staff', 'accountant', 'librarian', 'driver', 'security', 'cleaner', 'caterer', 'nurse', 'housemaster',
        'care_assistant', 'sen_facilitator', 'sen_therapist', 'counsellor'];

    private static function d($v): ?string
    {
        return $v ? substr((string) $v, 0, 10) : null;
    }

    /**
     * Everyone who works at the school: support staff (staff table), teachers (teachers table),
     * and leadership/other accounts with no profile row. Each row says where it lives (source)
     * so edits go to the right record.
     */
    public function index(Request $request): JsonResponse
    {
        $cols = fn (string $table) => array_flip(\Illuminate\Support\Facades\Schema::getColumnListing($table));
        $pick = function ($row, array $have) {
            $out = [];
            foreach (self::PROFILE as $f) {
                $out[$f] = isset($have[$f]) ? ($row->$f ?? null) : null;
            }
            $out['date_of_birth'] = self::d($out['date_of_birth']);
            $out['employment_date'] = self::d($out['employment_date']);

            return $out;
        };

        $rows = collect();
        $staffCols = $cols('staff');
        $roles = DB::table('users')->pluck('role', 'id');
        foreach (DB::table('staff')->whereNull('deleted_at')->get() as $r) {
            $rows->push(['id' => $r->id, 'source' => 'staff_record', 'user_id' => $r->user_id, 'employee_id' => $r->employee_id,
                'role' => $roles[$r->user_id] ?? $r->role, 'department' => $r->department, 'status' => $r->status] + $pick($r, $staffCols));
        }
        $teacherCols = $cols('teachers');
        $depts = \Illuminate\Support\Facades\Schema::hasTable('departments') ? DB::table('departments')->pluck('name', 'id') : collect();
        foreach (DB::table('teachers')->whereNull('deleted_at')->get() as $r) {
            $rows->push(['id' => $r->id, 'source' => 'teacher', 'user_id' => $r->user_id, 'employee_id' => $r->employee_id,
                'role' => $roles[$r->user_id] ?? 'teacher', 'department' => $depts[$r->department_id ?? 0] ?? null, 'status' => $r->status] + $pick($r, $teacherCols));
        }
        $taken = $rows->pluck('user_id')->filter()->all();
        $users = DB::table('users')->whereNull('deleted_at')
            ->whereIn('role', array_merge(self::ALL_ROLES, ['school_admin']))
            ->whereNotIn('id', $taken ?: [0])->get();
        foreach ($users as $u) {
            $parts = preg_split('/\s+/', trim((string) $u->name), 2);
            $rows->push(array_merge(array_fill_keys(self::PROFILE, null), [
                'id' => $u->id, 'source' => 'user_record', 'user_id' => $u->id, 'employee_id' => null, 'role' => $u->role,
                'department' => null, 'status' => $u->status ?? 'active',
                'first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? '', 'email' => $u->email, 'phone' => $u->phone ?? null,
            ]));
        }

        if ($request->filled('role')) {
            $rows = $rows->where('role', $request->role);
        }
        if ($request->filled('status')) {
            $rows = $rows->where('status', $request->status);
        }
        if ($request->filled('department')) {
            $rows = $rows->where('department', $request->department);
        }
        if ($request->filled('search')) {
            $q = mb_strtolower($request->search);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower("{$r['first_name']} {$r['last_name']} {$r['email']} {$r['employee_id']}"), $q));
        }
        $rows = $rows->sortBy(fn ($r) => mb_strtolower("{$r['first_name']} {$r['last_name']}"))->values();

        $perPage = max(1, (int) $request->get('per_page', 20));
        $page = max(1, (int) $request->get('page', 1));

        return response()->json(new \Illuminate\Pagination\LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        ));
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
            'role' => 'required|in:admin,staff,accountant,librarian,driver,security,cleaner,caterer,nurse,care_assistant,sen_facilitator,sen_therapist,counsellor',
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
            ] + array_filter($request->only(['gender', 'date_of_birth', 'address', 'emergency_contact_name', 'emergency_contact_phone',
                'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship']), fn ($v) => $v !== null && $v !== '') + [
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
        $source = $request->input('source', $request->query('source', 'staff_record'));
        $table = ['staff_record' => 'staff', 'teacher' => 'teachers', 'user_record' => 'users'][$source] ?? 'staff';
        $row = DB::table($table)->find($id);
        if (! $row) {
            return response()->json(['error' => 'Staff not found'], 404);
        }
        $userId = $table === 'users' ? $row->id : ($row->user_id ?? null);

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'email' => 'sometimes|email|max:255',
            'phone' => 'nullable|string|max:30',
            'role' => 'sometimes|in:' . implode(',', self::ALL_ROLES),
            'department' => 'nullable|string|max:255',
            'gender' => 'nullable|in:male,female,other',
            'date_of_birth' => 'nullable|date',
            'employment_date' => 'nullable|date',
            'address' => 'nullable|string|max:1000',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:30',
            'next_of_kin_name' => 'nullable|string|max:255',
            'next_of_kin_phone' => 'nullable|string|max:30',
            'next_of_kin_relationship' => 'nullable|string|max:60',
            'status' => 'sometimes|in:active,inactive,suspended',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }
        // Making someone principal / vice principal / admin is a school-admin decision.
        if ($request->filled('role') && in_array($request->role, ['principal', 'vice_principal', 'admin'], true)
            && ($denied = $this->requireSchoolAdmin($request))) {
            return $denied;
        }

        DB::transaction(function () use ($request, $table, $id, $userId) {
            if ($table !== 'users') {
                $have = array_flip(\Illuminate\Support\Facades\Schema::getColumnListing($table));
                $fields = array_merge(self::PROFILE, ['status'], $table === 'staff' ? ['department'] : []);
                $update = [];
                foreach ($fields as $f) {
                    if ($request->exists($f) && isset($have[$f])) {
                        $v = $request->input($f);
                        $update[$f] = $v === '' ? null : $v;
                    }
                }
                if ($table === 'staff' && $request->filled('role') && in_array($request->role, ['admin', 'staff', 'accountant', 'librarian', 'driver', 'security', 'cleaner', 'caterer', 'nurse', 'care_assistant', 'sen_facilitator', 'sen_therapist', 'counsellor', 'housemaster'], true)) {
                    $update['role'] = $request->role;
                }
                if ($update) {
                    DB::table($table)->where('id', $id)->update($update + ['updated_at' => now()]);
                }
            }
            // The login account carries the role, name, email and status everywhere in the app.
            if ($userId) {
                $u = [];
                if ($request->filled('role')) {
                    $u['role'] = $request->role;
                }
                if ($request->filled('first_name') || $request->filled('last_name')) {
                    $u['name'] = trim($request->input('first_name') . ' ' . $request->input('last_name'));
                }
                foreach (['email', 'phone', 'status'] as $f) {
                    if ($request->filled($f)) {
                        $u[$f] = $request->input($f);
                    }
                }
                if ($u) {
                    DB::table('users')->where('id', $userId)->update($u + ['updated_at' => now()]);
                }
            }
        });

        return response()->json(['message' => 'Staff updated successfully', 'staff' => DB::table($table)->find($id)]);
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
            'items.*.source' => 'nullable|in:staff_record,user_record,teacher',
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
            'items.*.source' => 'nullable|in:staff_record,user_record,teacher',
            'status'         => 'required|in:active,inactive,suspended',
        ]);

        $items = collect($validated['items']);
        $staffIds = $items->filter(fn ($i) => ($i['source'] ?? 'staff_record') === 'staff_record')->pluck('id');
        $teacherIds = $items->where('source', 'teacher')->pluck('id');
        DB::table('teachers')->whereIn('id', $teacherIds)->update(['status' => $validated['status'], 'updated_at' => now()]);
        $userIds  = $items->where('source', 'user_record')->pluck('id')
            ->merge(DB::table('staff')->whereIn('id', $staffIds)->whereNotNull('user_id')->pluck('user_id'))
            ->merge(DB::table('teachers')->whereIn('id', $teacherIds)->whereNotNull('user_id')->pluck('user_id'))
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
        if ($source === 'teacher') {
            // Teachers carry class/subject links; they are removed from the Teachers page.
            return [422, 'Teachers are deleted from the Teachers page.'];
        }
        if ($source === 'user_record') {
            $staff = null;
            $user  = User::whereIn('role', array_merge(self::STAFF_ROLES, self::ALL_ROLES))->find($id);
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
