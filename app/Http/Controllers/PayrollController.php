<?php

namespace App\Http\Controllers;

use App\Models\Payroll;
use App\Models\School;
use App\Models\SchoolSignature;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use App\Support\AuditLog;
use App\Support\SpreadsheetRows;

class PayrollController extends Controller
{
    private const FINANCE_ROLES = ['school_admin', 'principal', 'accountant', 'admin'];

    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    private function canManagePayroll(?\App\Models\User $user = null): bool
    {
        $user ??= Auth::user();

        return $user && in_array($user->role, self::FINANCE_ROLES, true);
    }

    private function authorizePayrollRecord(Payroll $payroll): ?JsonResponse
    {
        if ($this->canManagePayroll()) {
            return null;
        }

        $user = Auth::user();
        if (! $user || (int) $payroll->staff_id !== (int) $user->id) {
            return response()->json([
                'error'   => 'Forbidden',
                'message' => 'You may only view your own pay stubs.',
            ], 403);
        }

        return null;
    }

    public function index(Request $request): JsonResponse
    {
        $query = Payroll::where('school_id', $this->school($request)?->id ?? 0)
            ->with(['staff', 'processedBy', 'academicYear']);

        if ($request->filled('staff_id'))       $query->where('staff_id', $request->staff_id);
        if ($request->filled('month'))          $query->where('month', $request->month);
        if ($request->filled('year'))           $query->where('year', $request->year);
        if ($request->filled('status'))         $query->where('status', $request->status);
        if ($request->filled('academic_year_id')) $query->where('academic_year_id', $request->academic_year_id);

        $paginator = $query->orderByDesc('year')->orderByDesc('month')->paginate($request->get('per_page', 15));
        // The table shows name / role / department and each slip's lines.
        $depts = \App\Models\Teacher::whereIn('user_id', $paginator->getCollection()->pluck('staff_id'))->with('department:id,name')->get()
            ->mapWithKeys(fn ($t) => [$t->user_id => $t->department?->name]);
        $paginator->getCollection()->transform(fn (Payroll $p) => $p->setAttribute('staff_name', $p->staff?->name)
            ->setAttribute('staff_role', $p->staff?->role)->setAttribute('department', $depts[$p->staff_id] ?? null)
            ->setAttribute('lines', $p->lines())->setAttribute('processed_by_name', $p->processedBy?->name));

        // Build summary from the full (unfiltered by page) result set
        $summaryQuery = Payroll::where('school_id', $this->school($request)?->id ?? 0);
        if ($request->filled('month'))  $summaryQuery->where('month', $request->month);
        if ($request->filled('year'))   $summaryQuery->where('year',  $request->year);
        $all = $summaryQuery->get(['basic_salary','allowances','deductions','net_salary','status','staff_id']);

        $summary = [
            'total_staff'      => $all->pluck('staff_id')->unique()->count(),
            'total_gross'      => (float) $all->sum(fn($p) => (float)$p->basic_salary + (float)($p->allowances ?? 0)),
            'total_deductions' => (float) $all->sum(fn($p) => (float)($p->deductions ?? 0)),
            'total_net'        => (float) $all->sum(fn($p) => (float)$p->net_salary),
            'paid_count'       => $all->where('status', 'paid')->count(),
            'pending_count'    => $all->where('status', 'pending')->count(),
        ];

        return response()->json(array_merge($paginator->toArray(), ['summary' => $summary]));
    }

    /**
     * Staff self-service: list payroll rows for the authenticated user only.
     * GET /financial/payroll/mine?year=&month=
     */
    public function mine(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $schoolId = $this->school($request)?->id ?? 0;
        $query    = Payroll::where('school_id', $schoolId)->where('staff_id', $user->id);

        if ($request->filled('month')) {
            $query->where('month', $request->month);
        }
        if ($request->filled('year')) {
            $query->where('year', $request->year);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $paginator = $query->orderByDesc('year')->orderByDesc('month')
            ->paginate($request->get('per_page', 15));

        return response()->json($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $school = $this->school($request);
        if (!$school) {
            return response()->json(['error' => 'School context not found'], 400);
        }

        $data = $request->validate([
            'staff_id'        => 'required|exists:users,id',
            'academic_year_id'=> 'nullable|exists:academic_years,id',
            'month'           => 'required|integer|between:1,12',
            'year'            => 'required|integer|min:2000|max:2100',
            'basic_salary'    => 'required|numeric|min:0',
            'allowances'      => 'nullable|numeric|min:0',
            'deductions'      => 'nullable|numeric|min:0',
            'items'           => 'nullable|array|max:40',
            'items.*.name'    => 'required|string|max:100',
            'items.*.type'    => 'required|in:earning,deduction',
            'items.*.amount'  => 'nullable|numeric|min:0',
            'payment_date'    => 'nullable|date',
            'payment_method'  => 'nullable|in:bank_transfer,cash,cheque',
            'notes'           => 'nullable|string',
        ]);

        // Prevent duplicate payroll for same staff/month/year
        $exists = Payroll::where('school_id', $school->id)
            ->where('staff_id', $data['staff_id'])
            ->where('month', $data['month'])
            ->where('year', $data['year'])
            ->exists();

        if ($exists) {
            return response()->json([
                'error' => 'Payroll already exists for this staff member for the given month/year.',
            ], 422);
        }

        $academicYearId = $data['academic_year_id'] ?? $school->getCurrentAcademicYear()?->id;
        if (! $academicYearId) {
            return response()->json([
                'error' => 'No academic year set. Set a current academic year, or pass academic_year_id explicitly.',
            ], 422);
        }

        $data['academic_year_id'] = $academicYearId;
        $data = $this->withTotals($data);
        $data['school_id']    = $school->id;
        $data['status']       = 'pending';
        $data['processed_by'] = auth()->id();

        $payroll = Payroll::create($data);
        AuditLog::record('payroll', $payroll->id, 'created', 'Created ' . $this->describe($payroll) . ' — net ₦' . number_format((float) $payroll->net_salary, 2));

        return response()->json([
            'message' => 'Payroll created successfully',
            'payroll' => $payroll->load(['staff', 'processedBy']),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $payroll = Payroll::with(['staff', 'processedBy', 'academicYear', 'school'])->findOrFail($id);
        return response()->json(['payroll' => $payroll]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $payroll = Payroll::findOrFail($id);

        $data = $request->validate([
            'basic_salary'   => 'sometimes|numeric|min:0',
            'allowances'     => 'nullable|numeric|min:0',
            'deductions'     => 'nullable|numeric|min:0',
            'items'          => 'nullable|array|max:40',
            'items.*.name'   => 'required|string|max:100',
            'items.*.type'   => 'required|in:earning,deduction',
            'items.*.amount' => 'nullable|numeric|min:0',
            'month'          => 'sometimes|integer|between:1,12',
            'year'           => 'sometimes|integer|min:2000|max:2100',
            'payment_date'   => 'nullable|date',
            'payment_method' => 'nullable|in:bank_transfer,cash,cheque',
            'status'         => 'sometimes|in:pending,paid,cancelled',
            'notes'          => 'nullable|string',
        ]);

        // Moving it to another month must not clash with that month's slip.
        if ((isset($data['month']) || isset($data['year'])) && Payroll::where('school_id', $payroll->school_id)->where('staff_id', $payroll->staff_id)
            ->where('month', $data['month'] ?? $payroll->month)->where('year', $data['year'] ?? $payroll->year)->where('id', '!=', $payroll->id)->exists()) {
            return response()->json(['error' => 'This staff member already has payroll for that month.'], 422);
        }

        // Recalculate the totals if any amount changes
        if (array_key_exists('items', $data) || isset($data['basic_salary']) || isset($data['allowances']) || isset($data['deductions'])) {
            $data += ['basic_salary' => (float) $payroll->basic_salary];
            if (! array_key_exists('items', $data) && ! isset($data['allowances']) && ! isset($data['deductions'])) {
                $data['items'] = $payroll->lines();
            }
            $data += ['allowances' => (float) $payroll->allowances, 'deductions' => (float) $payroll->deductions];
            $data = $this->withTotals($data);
        }

        $before = $payroll->only(array_keys($data));
        $before['payment_date'] = $payroll->payment_date?->toDateString();
        $payroll->update($data);
        $changes = AuditLog::diff($before, $data);
        if ($changes) {
            $what = isset($changes['status']) && count($changes) <= 2 ? 'Marked ' . $this->describe($payroll) . ' as ' . $payroll->status : 'Edited ' . $this->describe($payroll);
            AuditLog::record('payroll', $payroll->id, 'updated', $what, $changes);
        }

        return response()->json([
            'message' => 'Payroll updated successfully',
            'payroll' => $payroll->load(['staff', 'processedBy']),
        ]);
    }

    /**
     * Admins / finance only (finance.manage). Paid slips can be deleted too — every delete is
     * logged with who did it, and a copy goes to the recycle bin.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $payroll = Payroll::with('staff')->findOrFail($id);
        $reason = trim((string) $request->input('reason', ''));
        AuditLog::record('payroll', $payroll->id, 'deleted', 'Deleted ' . $this->describe($payroll) . ' (' . $payroll->status . ', net ₦' . number_format((float) $payroll->net_salary, 2) . ')' . ($reason ? " — {$reason}" : ''));
        $payroll->delete();

        return response()->json(['message' => 'Payroll deleted successfully']);
    }

    /** GET /financial/payroll/{id}/history, or /financial/payroll-history for every slip. */
    public function history(Request $request, ?string $id = null): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        return response()->json(['history' => AuditLog::for('payroll', $id !== null ? (int) $id : null)]);
    }

    /** The school's pay slip lines: [{name, type: earning|deduction, percent?}]. */
    public function lineSettings(Request $request): JsonResponse
    {
        return response()->json(['lines' => $this->configuredLines($this->school($request)), 'defaults' => Payroll::DEFAULT_LINES]);
    }

    public function saveLineSettings(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }
        $data = $request->validate([
            'lines'           => 'present|array|max:40',
            'lines.*.name'    => 'required|string|max:100',
            'lines.*.type'    => 'required|in:earning,deduction',
            'lines.*.percent' => 'nullable|numeric|min:0|max:100',
        ]);
        $school = $this->school($request);
        if (! $school) {
            return response()->json(['error' => 'School context not found'], 400);
        }
        $lines = collect($data['lines'])->map(fn ($l) => array_filter([
            'name' => trim($l['name']), 'type' => $l['type'], 'percent' => isset($l['percent']) && $l['percent'] !== '' ? (float) $l['percent'] : null,
        ], fn ($v) => $v !== null))->unique(fn ($l) => strtolower($l['name']))->values()->all();
        $school->settings = array_merge($school->settings ?? [], ['payroll_lines' => $lines]);
        $school->save();
        AuditLog::record('payroll', null, 'settings', 'Changed the pay slip lines: ' . implode(', ', array_column($lines, 'name')));

        return response()->json(['lines' => $lines]);
    }

    private function configuredLines(?School $school): array
    {
        $lines = $school?->settings['payroll_lines'] ?? null;

        return is_array($lines) ? $lines : Payroll::DEFAULT_LINES;
    }

    /** People who can be paid: every account except students, parents and the platform admin. */
    private function payableStaff()
    {
        $users = \App\Models\User::whereNotIn('role', ['student', 'guardian', 'parent', 'super_admin'])
            ->orderBy('name')->get(['id', 'name', 'email', 'role'] + (\Illuminate\Support\Facades\Schema::hasColumn('users', 'username') ? [4 => 'username'] : []));
        $ids = \App\Models\Teacher::whereIn('user_id', $users->pluck('id'))->pluck('employee_id', 'user_id')
            ->union(\Illuminate\Support\Facades\Schema::hasTable('staff') ? \Illuminate\Support\Facades\DB::table('staff')->whereIn('user_id', $users->pluck('id'))->pluck('employee_id', 'user_id') : collect());

        return $users->map(fn ($u) => $u->setAttribute('staff_code', $ids[$u->id] ?? null));
    }

    /**
     * GET /financial/payroll/import-template?month=&year= — a CSV with every staff member, their
     * last basic salary and lines filled in, and a column per pay slip line, ready to edit and upload.
     */
    public function importTemplate(Request $request): Response|JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }
        $school = $this->school($request);
        $lines = $this->configuredLines($school);
        $last = Payroll::where('school_id', $school?->id ?? 0)->orderByDesc('year')->orderByDesc('month')->get()->unique('staff_id')->keyBy('staff_id');

        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_merge(['Staff ID or Email', 'Name', 'Basic Salary'], array_column($lines, 'name'), ['Notes']), ',', '"', '');
        foreach ($this->payableStaff() as $u) {
            $p = $last[$u->id] ?? null;
            $prev = collect($p?->lines() ?? [])->keyBy(fn ($l) => strtolower($l['name']));
            $row = [$u->staff_code ?: $u->email, $u->name, $p ? (float) $p->basic_salary : ''];
            foreach ($lines as $l) {
                $row[] = isset($prev[strtolower($l['name'])]) ? $prev[strtolower($l['name'])]['amount'] : '';
            }
            $row[] = '';
            fputcsv($out, $row, ',', '"', '');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        $period = $request->filled('month') && $request->filled('year')
            ? \Carbon\Carbon::createFromDate((int) $request->year, (int) $request->month, 1)->format('F-Y') : 'template';

        return response("\xEF\xBB\xBF" . $csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="salaries-' . $period . '.csv"',
        ]);
    }

    /**
     * POST /financial/payroll/import — monthly salaries from an Excel/CSV sheet. One row per person:
     * "Staff ID or Email" (or username / exact name), "Basic Salary", then any pay slip line columns
     * (PAYE, Pension, Bonus, …) and optional "Notes". Creates the month's slips, or updates ones
     * already there that are not yet paid (unless update_paid is set). Everything is logged.
     */
    /** POST /financial/payroll/import-preview {file, header_row?} — what each column of the school's sheet looks like. */
    public function importPreview(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }
        $request->validate(['file' => 'required|file|max:10240|mimes:csv,txt,xlsx,xls', 'header_row' => 'nullable|integer|min:0']);
        $rows = SpreadsheetRows::read($request->file('file')->getRealPath(), $request->file('file')->getClientOriginalExtension());
        if (count($rows) < 2) {
            return response()->json(['error' => 'The sheet looks empty.'], 422);
        }

        return response()->json(\App\Support\SpreadsheetMapping::preview('payroll', $rows, $request->filled('header_row') ? (int) $request->header_row : null));
    }

    public function import(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }
        $request->validate([
            'file'         => 'required|file|max:10240|mimes:csv,txt,xlsx,xls',
            'month'        => 'required|integer|between:1,12',
            'year'         => 'required|integer|min:2000|max:2100',
            'payment_method' => 'nullable|in:bank_transfer,cash,cheque',
            'update_paid'  => 'nullable|boolean',
            'mapping'      => 'nullable|string',
        ]);
        $school = $this->school($request);
        if (! $school) {
            return response()->json(['error' => 'School context not found'], 400);
        }
        $academicYearId = $school->getCurrentAcademicYear()?->id ?? \App\Models\AcademicYear::orderByDesc('id')->value('id');
        if (! $academicYearId) {
            return response()->json(['error' => 'No academic year set. Set a current academic year first.'], 422);
        }

        $rows = SpreadsheetRows::read($request->file('file')->getRealPath(), $request->file('file')->getClientOriginalExtension());
        // The school's own payment sheet: heading row found, columns matched (as confirmed in the preview).
        [$rows, $forcedTypes] = \App\Support\SpreadsheetMapping::rowsFor('payroll', $rows, $request->input('mapping'));
        if (count($rows) < 2) {
            return response()->json(['error' => 'The sheet is empty — it needs a header row and one row per staff member.'], 422);
        }
        $norm = fn ($h) => strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9%\/ ]/i', ' ', (string) $h))));
        $headers = array_map($norm, array_shift($rows));

        $find = function (array $names) use ($headers) {
            foreach ($headers as $i => $h) {
                if (in_array($h, $names, true)) {
                    return $i;
                }
            }

            return null;
        };
        $idCol = $find(['staff id or email', 'staff id', 'staff', 'email', 'employee id', 'staff email', 'username', 'id']);
        $nameCol = $find(['name', 'staff name', 'full name', 'employee', 'employee name']);
        $basicCol = $find(['basic salary', 'basic', 'basic pay', 'salary', 'gross salary', 'monthly salary']);
        $notesCol = $find(['notes', 'note', 'remark', 'remarks', 'comment']);
        if ($basicCol === null || ($idCol === null && $nameCol === null)) {
            return response()->json(['error' => 'The sheet needs a "Staff ID or Email" (or "Name") column and a "Basic Salary" column. Download the template to see the layout.'], 422);
        }

        // Every other column with a heading is a pay slip line; its type comes from the school's lines,
        // else a guess from the name (tax, pension, loan, … are deductions).
        $configured = collect($this->configuredLines($school))->keyBy(fn ($l) => $norm($l['name']));
        $lineCols = [];
        foreach ($headers as $i => $h) {
            if ($h === '' || in_array($i, [$idCol, $nameCol, $basicCol, $notesCol], true) || in_array($h, ['s/n', 'sn', 'no', 'role', 'department', 'net', 'net pay', 'net salary', 'total', 'bank', 'account number', 'account'], true)) {
                continue;
            }
            $cfg = $configured[$h] ?? null;
            $lineCols[$i] = [
                'name' => $cfg['name'] ?? ucwords($h),
                'type' => $forcedTypes[$h] ?? $cfg['type'] ?? (preg_match('/tax|paye|pension|loan|penalt|deduct|welfare|levy|union|advance|fine|absent|late|nhf|nhis|contribution/', $h) ? 'deduction' : 'earning'),
            ];
        }

        $staff = $this->payableStaff();
        $byKey = [];
        foreach ($staff as $u) {
            foreach ([$u->email, $u->username ?? null, $u->staff_code] as $k) {
                if ($k) {
                    $byKey[strtolower(trim($k))] = $u;
                }
            }
        }
        $byName = $staff->groupBy(fn ($u) => strtolower(trim(preg_replace('/\s+/', ' ', $u->name))));
        $money = fn ($v) => (float) preg_replace('/[^0-9.\-]/', '', (string) $v);

        $created = $updated = 0;
        $errors = [];
        $seen = [];
        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            foreach ($rows as $n => $row) {
                $line = $n + 2;
                $key = $idCol !== null ? strtolower(trim((string) ($row[$idCol] ?? ''))) : '';
                $name = $nameCol !== null ? strtolower(trim(preg_replace('/\s+/', ' ', (string) ($row[$nameCol] ?? '')))) : '';
                $u = $key !== '' ? ($byKey[$key] ?? null) : null;
                if (! $u && $name !== '') {
                    $matches = $byName[$name] ?? collect();
                    if ($matches->count() > 1) {
                        $errors[] = ['row' => $line, 'message' => "More than one staff member is called \"{$row[$nameCol]}\" — use their staff ID or email."];
                        continue;
                    }
                    $u = $matches->first();
                }
                if (! $u) {
                    $errors[] = ['row' => $line, 'message' => 'No staff member found for "' . trim(($row[$idCol] ?? '') . ' ' . ($nameCol !== null ? ($row[$nameCol] ?? '') : '')) . '".'];
                    continue;
                }
                if (isset($seen[$u->id])) {
                    $errors[] = ['row' => $line, 'message' => "{$u->name} is on the sheet twice (row {$seen[$u->id]}); this row was skipped."];
                    continue;
                }
                $seen[$u->id] = $line;
                $basicRaw = trim((string) ($row[$basicCol] ?? ''));
                if ($basicRaw === '') {
                    $errors[] = ['row' => $line, 'message' => "No basic salary for {$u->name}; skipped."];
                    continue;
                }
                $items = [];
                foreach ($lineCols as $i => $l) {
                    $items[] = $l + ['amount' => abs($money($row[$i] ?? 0))];
                }
                $data = $this->withTotals(['basic_salary' => abs($money($basicRaw)), 'items' => $items]);
                if ($notesCol !== null && trim((string) ($row[$notesCol] ?? '')) !== '') {
                    $data['notes'] = trim((string) $row[$notesCol]);
                }

                $existing = Payroll::where('school_id', $school->id)->where('staff_id', $u->id)
                    ->where('month', $request->month)->where('year', $request->year)->first();
                if ($existing) {
                    if ($existing->status === 'paid' && ! $request->boolean('update_paid')) {
                        $errors[] = ['row' => $line, 'message' => "{$u->name} is already paid for this month; left as it was."];
                        continue;
                    }
                    $before = $existing->only(array_keys($data));
                    $existing->update($data);
                    if ($changes = AuditLog::diff($before, $data)) {
                        AuditLog::record('payroll', $existing->id, 'updated', 'Updated ' . $this->describe($existing) . ' from a salary sheet', $changes);
                    }
                    $updated++;
                } else {
                    $p = Payroll::create($data + [
                        'school_id' => $school->id, 'staff_id' => $u->id, 'academic_year_id' => $academicYearId,
                        'month' => (int) $request->month, 'year' => (int) $request->year, 'status' => 'pending',
                        'payment_method' => $request->input('payment_method', 'bank_transfer'), 'processed_by' => auth()->id(),
                    ]);
                    $p->setRelation('staff', $u);
                    AuditLog::record('payroll', $p->id, 'created', 'Created ' . $this->describe($p) . ' from a salary sheet — net ₦' . number_format((float) $p->net_salary, 2));
                    $created++;
                }
            }
            \Illuminate\Support\Facades\DB::commit();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            throw $e;
        }

        return response()->json([
            'message' => "{$created} created, {$updated} updated" . ($errors ? ', ' . count($errors) . ' row(s) need attention' : ''),
            'created' => $created, 'updated' => $updated, 'errors' => $errors,
            'lines' => array_values($lineCols),
        ]);
    }

    /** Totals from the lines (or the old allowances / deductions pair when no lines are sent). */
    private function withTotals(array $data): array
    {
        $basic = (float) ($data['basic_salary'] ?? 0);
        if (array_key_exists('items', $data) && is_array($data['items'])) {
            $t = Payroll::totals($basic, $data['items']);

            return array_merge($data, $t);
        }
        $data['allowances'] = (float) ($data['allowances'] ?? 0);
        $data['deductions'] = (float) ($data['deductions'] ?? 0);
        $data['net_salary'] = round($basic + $data['allowances'] - $data['deductions'], 2);

        return $data;
    }

    private function describe(Payroll $p): string
    {
        $p->loadMissing('staff');

        return ($p->staff?->name ?? 'staff #' . $p->staff_id) . "'s payroll for " . $p->period;
    }

    /**
     * Generate a pay stub for a single payroll record.
     *
     * Response shape matches the PayStub interface in MyPayslips.tsx:
     * { stub_id, generated_at, school, employee, period, earnings, deductions, net_salary,
     *   payment_info, processed_by, notes, signatures }
     */
    public function payStub(string $id): JsonResponse
    {
        $payroll = Payroll::with(['staff', 'processedBy', 'school', 'academicYear'])->findOrFail($id);

        if ($denied = $this->authorizePayrollRecord($payroll)) {
            return $denied;
        }

        $staff  = $payroll->staff;   // User model
        $school = $payroll->school;

        // Attempt to load the teacher profile for bank details / department
        $teacher = $staff ? \App\Models\Teacher::where('user_id', $staff->id)->with('department')->first() : null;

        $signatures = $school
            ? \App\Models\SchoolSignature::activeForSchool($school->id)->map(fn ($s) => array_merge($s->toArray(), ['signature_url' => $s->signature_url]))
            : collect();

        $monthLabel = \Carbon\Carbon::createFromDate($payroll->year, $payroll->month, 1)
            ->format('F Y');

        $gross      = (float) $payroll->basic_salary + (float) $payroll->allowances;
        $deductions = (float) $payroll->deductions;
        $lines      = collect($payroll->lines());
        $byName     = fn (string $re) => (float) $lines->where('type', 'deduction')->filter(fn ($l) => preg_match($re, strtolower($l['name'])))->sum('amount');

        return response()->json([
            'pay_stub' => [
                'stub_id'      => 'PS-' . str_pad($payroll->id, 8, '0', STR_PAD_LEFT),
                'generated_at' => now()->toIso8601String(),

                'school' => [
                    'name'     => $school?->name,
                    'address'  => $school?->address,
                    'phone'    => $school?->phone,
                    'email'    => $school?->email,
                    'logo_url' => $school?->logo,
                ],

                'employee' => [
                    'id'                 => $staff?->id,
                    'name'               => $staff?->name,
                    'email'              => $staff?->email,
                    'role'               => $staff?->role,
                    'department'         => $teacher?->department?->name ?? '—',
                    'employment_type'    => $teacher?->employment_type ?? 'full_time',
                    'bank_name'          => $teacher?->bank_name ?? '—',
                    'bank_account_number'=> $teacher?->bank_account_number ?? '—',
                ],

                'period' => [
                    'month'        => $payroll->month,
                    'year'         => $payroll->year,
                    'label'        => $monthLabel,
                    'payment_date' => $payroll->payment_date,
                ],

                'earnings' => [
                    'basic_salary' => (float) $payroll->basic_salary,
                    'allowances'   => (float) $payroll->allowances,
                    'gross_salary' => $gross,
                    'lines'        => $lines->where('type', 'earning')->values(),
                ],

                'deductions' => [
                    'total'     => $deductions,
                    'lines'     => $lines->where('type', 'deduction')->values(),
                    'breakdown' => [
                        'tax_paye'       => $tax = $byName('/paye|tax/'),
                        'pension'        => $pen = $byName('/pension/'),
                        'loan_repayment' => $loan = $byName('/loan/'),
                        'other'          => max(0, $deductions - $tax - $pen - $loan),
                    ],
                ],

                'net_salary' => (float) $payroll->net_salary,

                'payment_info' => [
                    'method'    => $payroll->payment_method ?? 'bank_transfer',
                    'status'    => $payroll->status,
                    'reference' => null,
                ],

                'processed_by' => $payroll->processedBy?->name,
                'notes'        => $payroll->notes,
                'signatures'   => $signatures,
            ],
        ]);
    }

    /**
     * Return a print-ready HTML pay stub page.
     * GET /payroll/{payroll}/pay-stub/print
     */
    public function payStubPrint(string $id): Response
    {
        $payroll = Payroll::with(['staff', 'processedBy', 'school', 'academicYear'])->findOrFail($id);

        if ($denied = $this->authorizePayrollRecord($payroll)) {
            return response($denied->getContent(), $denied->getStatusCode())
                ->header('Content-Type', 'application/json');
        }

        $staff      = $payroll->staff;
        $school     = $payroll->school;
        $teacher    = $staff ? \App\Models\Teacher::where('user_id', $staff->id)->with('department')->first() : null;
        $signatures = $school ? SchoolSignature::activeForSchool($school->id) : collect();

        $logoHtml = $school?->logo
            ? '<img src="' . e($school->logo) . '" style="max-height:70px;max-width:160px;" alt="logo">'
            : '<div style="font-size:22px;font-weight:bold;">' . e($school?->name ?? 'School') . '</div>';

        $monthLabel = \Carbon\Carbon::createFromDate($payroll->year, $payroll->month, 1)->format('F Y');
        $stubId     = 'PS-' . str_pad($payroll->id, 8, '0', STR_PAD_LEFT);
        $gross      = (float) $payroll->basic_salary + (float) $payroll->allowances;
        $deductions = (float) $payroll->deductions;
        $net        = (float) $payroll->net_salary;

        $schoolName = e($school?->name ?? '');
        $schoolAddr = e($school?->address ?? '');
        $schoolTel  = e($school?->phone ?? '');
        $staffName  = e($staff?->name ?? 'N/A');
        $staffRole  = e(ucwords(str_replace('_', ' ', $staff?->role ?? '')));
        $dept       = e($teacher?->department?->name ?? '—');
        $bankName   = e($teacher?->bank_name ?? '—');
        $bankAcct   = e($teacher?->bank_account_number ?? '—');
        $payDate    = $payroll->payment_date ? date('d M Y', strtotime($payroll->payment_date)) : 'Pending';
        $payMethod  = e(ucwords(str_replace('_', ' ', $payroll->payment_method ?? '')));
        $processedBy= e($payroll->processedBy?->name ?? '');
        $notes      = e($payroll->notes ?? '');

        $sigHtml = '';
        foreach ($signatures as $role => $sig) {
            $sigName = e($sig->name);
            $sigRole = e(ucwords(str_replace('_', ' ', $role)));
            $sigUrl  = $sig->signature_url;
            $sigImg  = $sigUrl
                ? "<img src=\"{$sigUrl}\" style=\"max-height:55px;max-width:140px;\">"
                : '<div style="border-bottom:1px solid #333;width:140px;height:55px;"></div>';
            $sigHtml .= "<div style='text-align:center;min-width:160px;'>{$sigImg}<div style='font-size:11px;margin-top:4px;'>{$sigName}</div><div style='font-size:10px;color:#666;'>{$sigRole}</div></div>";
        }
        if (! $sigHtml) {
            $sigHtml = '<div style="border-bottom:1px solid #333;width:160px;height:55px;margin:auto;"></div><div style="font-size:11px;text-align:center;margin-top:4px;">Authorised Signatory</div>';
        }

        $bSalary = number_format($payroll->basic_salary, 2);
        $lineRows = function (string $type) use ($payroll): string {
            $html = '';
            foreach ($payroll->lines() as $l) {
                if ($l['type'] === $type) {
                    $html .= '<div class="row"><span>' . e($l['name']) . '</span><span>₦' . number_format((float) $l['amount'], 2) . '</span></div>';
                }
            }

            return $html;
        };
        $earnRows = $lineRows('earning');
        $dedRows  = $lineRows('deduction');
        $bGross  = number_format($gross, 2);
        $bDeduct = number_format($deductions, 2);
        $bNet    = number_format($net, 2);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Pay Stub – {$staffName} – {$monthLabel}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; font-size: 12px; color: #111; padding: 24px; max-width: 600px; margin: auto; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #1a3a6b; padding-bottom: 14px; margin-bottom: 18px; }
  .school-info p { font-size: 11px; color: #555; margin-top: 3px; }
  .stub-meta { text-align: right; }
  .stub-meta h1 { font-size: 18px; color: #1a3a6b; letter-spacing: 1px; }
  .stub-meta p { font-size: 11px; color: #666; margin-top: 3px; }
  .emp-box { background: #f0f4ff; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  .emp-box div span { display: block; font-size: 10px; color: #888; text-transform: uppercase; }
  .emp-box div strong { font-size: 12px; }
  .section h3 { font-size: 12px; color: #1a3a6b; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-bottom: 8px; }
  .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #f0f0f0; font-size: 12px; }
  .row.total { font-weight: bold; border-bottom: 2px solid #1a3a6b; }
  .net-box { background: #1a3a6b; color: #fff; text-align: center; padding: 14px; border-radius: 8px; margin: 18px 0; }
  .net-box .lbl { font-size: 11px; opacity: .8; }
  .net-box .val { font-size: 26px; font-weight: bold; }
  .signatures { display: flex; gap: 30px; flex-wrap: wrap; margin-top: 24px; padding-top: 14px; border-top: 1px solid #ddd; }
  .footer { text-align: center; font-size: 10px; color: #aaa; margin-top: 14px; }
  @media print { body { padding: 0; max-width: 100%; } @page { margin: 1.5cm; } }
</style>
</head>
<body>

<div class="header">
  <div class="school-info">
    {$logoHtml}
    <p>{$schoolAddr}</p>
    <p>{$schoolTel}</p>
  </div>
  <div class="stub-meta">
    <h1>PAY STUB</h1>
    <p>{$monthLabel}</p>
    <p>Stub ID: {$stubId}</p>
  </div>
</div>

<div class="emp-box">
  <div><span>Employee</span><strong>{$staffName}</strong></div>
  <div><span>Role</span><strong>{$staffRole}</strong></div>
  <div><span>Department</span><strong>{$dept}</strong></div>
  <div><span>Bank</span><strong>{$bankName}</strong></div>
  <div><span>Account No.</span><strong>{$bankAcct}</strong></div>
  <div><span>Payment Date</span><strong>{$payDate}</strong></div>
</div>

<div class="section" style="margin-bottom:16px;">
  <h3>Earnings</h3>
  <div class="row"><span>Basic Salary</span><span>₦{$bSalary}</span></div>
  {$earnRows}
  <div class="row total"><span>Gross Salary</span><span>₦{$bGross}</span></div>
</div>

<div class="section" style="margin-bottom:8px;">
  <h3>Deductions</h3>
  {$dedRows}
  <div class="row total"><span>Total Deductions</span><span>₦{$bDeduct}</span></div>
</div>

<div class="net-box">
  <div class="lbl">NET PAY</div>
  <div class="val">₦{$bNet}</div>
</div>

<div class="row"><span style="color:#666;">Payment Method</span><span>{$payMethod}</span></div>
<div class="row"><span style="color:#666;">Processed By</span><span>{$processedBy}</span></div>
HTML;
        if ($notes) {
            $html .= "<div class=\"row\"><span style=\"color:#666;\">Notes</span><span>{$notes}</span></div>";
        }
        $html .= "<div class=\"signatures\">{$sigHtml}</div>";
        $html .= "<div class=\"footer\">This is a system-generated pay stub. {$schoolName}</div>";
        $html .= '<script>window.onload = function() { window.print(); }</script>';
        $html .= '</body></html>';

        return response(\App\Support\SchoolColor::apply($html), 200)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
