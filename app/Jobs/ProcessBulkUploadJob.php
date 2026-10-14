<?php

namespace App\Jobs;

use App\Events\BulkUploadProgressEvent;
use App\Jobs\SendEmailJob;
use App\Models\BulkUpload;
use App\Models\Guardian;
use App\Models\CAScore;
use App\Models\Exam;
use App\Models\Result;
use App\Models\School;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use App\Support\BulkUploadLookups;
use App\Support\ExamScoreSync;

class ProcessBulkUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Two workers share the `bulk-uploads` queue (see supervisor config), so the
    // very first write for a tenant can race both processes into creating the
    // same tenant storage directory at once — Flysystem throws
    // UnableToCreateDirectory for the loser instead of treating "already
    // exists" as success. Retrying lets the job succeed once the winner has
    // finished creating it; ensureTenantStorageReady() below also shrinks the
    // race window up front.
    public int $tries = 3;
    public int $timeout = 3600;

    private const BROADCAST_EVERY = 10; // rows between progress broadcasts

    public function backoff(): array
    {
        return [5, 15];
    }

    /** Rows for people already on the books — reported apart from failures. */
    private int $skipped = 0;

    public function __construct(public readonly int $uploadId)
    {
        $this->onQueue('bulk-uploads');
    }

    /**
     * Pre-create this tenant's storage skeleton with a plain, race-tolerant
     * mkdir before any Storage::disk() call lazily (and unsafely) does it.
     * Safe to call from every attempt / every worker: is_dir() short-circuits
     * once the directory exists, and @mkdir() on a dir another worker just
     * created is a harmless no-op, not an exception.
     */
    private function ensureTenantStorageReady(): void
    {
        foreach (['app', 'app/public', 'app/private', 'framework/cache', 'framework/sessions', 'framework/views'] as $dir) {
            $path = storage_path($dir);
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
        }
    }

    public function handle(): void
    {
        $this->ensureTenantStorageReady();

        $upload = BulkUpload::find($this->uploadId);
        if (!$upload || $upload->status === 'cancelled') {
            return;
        }

        if (!Storage::disk('local')->exists($upload->file_path)) {
            $upload->update([
                'status'       => 'failed',
                'completed_at' => now(),
                'errors'       => [['row' => 0, 'error' => 'Upload file not found. It may have expired.']],
            ]);
            $this->tryBroadcast($upload->fresh());
            return;
        }

        $upload->update(['status' => 'processing', 'started_at' => now()]);
        $this->tryBroadcast($upload->fresh());

        try {
            match ($upload->type) {
                'students' => $this->processStudents($upload),
                'teachers' => $this->processTeachers($upload),
                'staff'    => $this->processStaff($upload),
                'guardians' => $this->processGuardians($upload),
                'scores'   => $this->processScores($upload),
                default    => throw new \InvalidArgumentException("Unknown upload type: {$upload->type}"),
            };

            $upload->update(['status' => 'completed', 'completed_at' => now()]);

            // The lists and dashboard counts are cached; without this the
            // teachers page kept showing its pre-upload (empty) list for up
            // to five minutes after an upload reported success.
            $cache = app(\App\Services\CacheService::class);
            match ($upload->type) {
                'teachers' => $cache->invalidateTeacherCache(),
                'scores'   => $cache->invalidateByPattern('exams:*'),
                default    => null,
            };
            \App\Http\Controllers\DashboardController::bustCache();

        } catch (\Exception $e) {
            Log::error("Bulk upload {$this->uploadId} failed", ['error' => $e->getMessage()]);
            $current = $upload->fresh();
            $errors = array_merge($current->errors ?? [], [['row' => 0, 'error' => $e->getMessage()]]);
            $upload->update(['status' => 'failed', 'completed_at' => now(), 'errors' => $errors]);
        }

        $this->tryBroadcast($upload->fresh());
        Storage::disk('local')->delete($upload->file_path);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CSV helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function parseCsv(string $filePath): \Generator
    {
        $path = Storage::disk('local')->path($filePath);
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // XLSX: convert to in-memory CSV rows via ZipArchive
        if ($ext === 'xlsx' || $ext === 'xls') {
            $rows = $this->readXlsxRows($path);
            $headers     = $this->normalizeHeaders($rows[0] ?? []);
            $headerCount = count($headers);
            $rowNumber   = 1;
            foreach (array_slice($rows, 1) as $row) {
                $rowNumber++;
                // Truncate to header count first, then pad — avoids array_combine mismatch
                $fitted = array_pad(array_slice($row, 0, $headerCount), $headerCount, '');
                if (count(array_filter($fitted, fn($v) => $v !== '')) > 0) {
                    yield $rowNumber => $this->cleanRow(array_combine($headers, $fitted));
                }
            }
            return;
        }

        // CSV / TXT path
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new \RuntimeException("Cannot open file for reading.");
        }

        $headers     = $this->normalizeHeaders(fgetcsv($handle) ?: []);
        $headerCount = count($headers);
        $rowNumber   = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if (count(array_filter($row, fn($v) => $v !== null && $v !== '')) > 0) {
                // Truncate to header count first, then pad — avoids array_combine mismatch
                $fitted = array_pad(array_slice($row, 0, $headerCount), $headerCount, '');
                yield $rowNumber => $this->cleanRow(array_combine($headers, $fitted));
            }
        }

        fclose($handle);
    }

    private function countCsvRows(string $filePath): int
    {
        $path = Storage::disk('local')->path($filePath);
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'xlsx' || $ext === 'xls') {
            $rows = $this->readXlsxRows($path);
            $count = 0;
            foreach (array_slice($rows, 1) as $row) {
                if (count(array_filter($row, fn($v) => $v !== '')) > 0) {
                    $count++;
                }
            }
            return $count;
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            return 0;
        }
        fgetcsv($handle); // skip header
        $count = 0;
        while (($row = fgetcsv($handle)) !== false) {
            // Only count non-blank rows, matching parseCsv behaviour
            if (count(array_filter($row, fn($v) => $v !== null && $v !== '')) > 0) {
                $count++;
            }
        }
        fclose($handle);
        return $count;
    }

    /**
     * Parse the first worksheet of an XLSX file without external dependencies.
     * Returns array of rows (each row is an array of string values).
     */
    private function readXlsxRows(string $path): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('ZipArchive extension required for XLSX parsing.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Cannot open XLSX file.');
        }

        // Load shared strings
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $ss = new \SimpleXMLElement($ssXml);
            foreach ($ss->si as $si) {
                // Plain <t>, or the <t> of each rich-text run <r>. Read via
                // child access, not xpath('.//t'): Excel declares a default
                // namespace that an unprefixed xpath never matches, which
                // blanked every text cell (headers included) in Excel files.
                $text = isset($si->t) ? (string) $si->t : '';
                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }
                $sharedStrings[] = $text;
            }
        }

        // Load first worksheet
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            return [];
        }

        $sheet = new \SimpleXMLElement($sheetXml);
        $rows  = [];

        foreach ($sheet->sheetData->row ?? [] as $row) {
            $rowData = [];
            $lastCol = -1;
            foreach ($row->c as $cell) {
                // Determine column index from cell reference (e.g. "B3" → 1)
                preg_match('/^([A-Z]+)/', (string) ($cell['r'] ?? ''), $m);
                $colStr = $m[1] ?? 'A';
                $colIdx = 0;
                foreach (str_split($colStr) as $ch) {
                    $colIdx = $colIdx * 26 + (ord($ch) - ord('A') + 1);
                }
                $colIdx--; // 0-based

                // Fill gaps with empty strings
                while ($lastCol < $colIdx - 1) {
                    $rowData[] = '';
                    $lastCol++;
                }

                $type  = (string) ($cell['t'] ?? '');
                $value = (string) ($cell->v ?? '');

                if ($type === 's') {
                    // Shared string index
                    $value = $sharedStrings[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string) ($cell->is->t ?? '');
                }

                $rowData[] = $value;
                $lastCol   = $colIdx;
            }
            $rows[] = $rowData;
        }

        return $rows;
    }

    private function saveProgress(BulkUpload $upload, int $processed, int $success, int $failed, array $errors): void
    {
        $upload->update([
            'processed_rows' => $processed,
            'success_rows'   => $success,
            'failed_rows'    => $failed,
            'errors'         => array_slice($errors, -100), // keep last 100 errors only
        ] + (\Illuminate\Support\Facades\Schema::hasColumn('bulk_uploads', 'skipped_rows') ? ['skipped_rows' => $this->skipped] : []));

        if ($processed % self::BROADCAST_EVERY === 0) {
            $this->tryBroadcast($upload->fresh());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Row-level validation helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function requireField(array $data, string $field): string
    {
        $value = trim($data[$field] ?? '');
        if ($value === '') {
            throw new \Exception("'{$field}' is required and cannot be empty.");
        }
        return $value;
    }

    /** A per-person password (letters and digits only, easy to type from an email). */
    private function newPassword(): string
    {
        return \Illuminate\Support\Str::password(10, letters: true, numbers: true, symbols: false);
    }

    /**
     * Emails a bulk-uploaded teacher/staff member their login, same as
     * adding one person at a time does. Only called after the row is
     * committed, and only for addresses that came from the sheet — an
     * auto-generated address has no real mailbox. A mail failure never
     * fails the row: the admin can still resend from the list.
     */
    private function emailLoginDetails(School $school, string $email, string $name, string $password, string $kind, ?string $parentEmail = null, ?string $parentName = null): void
    {
        try {
            $subdomain = tenant()?->subdomain ?? $school->tenant?->subdomain;
            $loginUrl  = $subdomain
                ? 'https://' . $subdomain . '.' . config('app.root_domain', 'compasse.net') . '/login'
                : null;

            // A student with no email of their own: their login goes to the
            // parent instead, addressed to the parent.
            $intro = $parentEmail
                ? 'Hello ' . ($parentName ?: 'Parent/Guardian') . ",\n\n"
                    . "A {$kind} account for {$name} at {$school->name} has been created. Here are their login details:\n\n"
                : "Hello {$name},\n\n"
                    . "Your {$kind} account at {$school->name} has been created.\n\n";

            $body = $intro
                . "Login Email: {$email}\n"
                . "Password: {$password}\n"
                . ($loginUrl ? "Login here: {$loginUrl}\n" : '')
                . "\nPlease log in and change your password.\n\n"
                . "Regards,\n{$school->name}";

            SendEmailJob::dispatch(
                to:       $parentEmail ?: $email,
                subject:  $parentEmail ? ucfirst($kind) . " Login Credentials for {$name}" : 'Your ' . ucfirst($kind) . ' Login Credentials',
                body:     $body,
                schoolId: (string) $school->id,
                type:     'credentials',
            )->onQueue('emails');
        } catch (\Throwable $e) {
            Log::warning('Bulk upload: could not queue login email', ['email' => $email, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The login account for a new row. A brand-new email gets a new user.
     * An email that is already taken is only refused when it belongs to
     * someone still on the books; an account that was soft-deleted, or left
     * behind with no student/teacher/staff record by an earlier delete, is
     * reclaimed and reset instead — otherwise re-uploading people after
     * deleting them failed with "Email already exists" (or a raw duplicate
     * key error, since soft-deleted users still hold their email).
     */
    private function claimUser(string $email, string $name, string $password, string $role): User
    {
        $existing = User::withTrashed()->where('email', $email)->first();

        if (! $existing) {
            return User::create([
                'name'              => $name,
                'email'             => $email,
                'password'          => bcrypt($password),
                'role'              => $role,
                'status'            => 'active',
                'email_verified_at' => now(),
            ]);
        }

        $protectedRoles = ['super_admin', 'school_admin', 'principal', 'vice_principal'];
        if ($this->userHasLiveRecord($existing->id)) {
            throw new SkippedRow("Skipped — {$email} already has an account.");
        }
        if (in_array($existing->role, $protectedRoles, true)) {
            throw new \Exception("Email {$email} belongs to an administrator account.");
        }

        if ($existing->trashed()) {
            $existing->restore();
        }
        $existing->forceFill([
            'name'              => $name,
            'password'          => bcrypt($password),
            'role'              => $role,
            'status'            => 'active',
            'email_verified_at' => now(),
        ])->save();

        return $existing;
    }

    private function userHasLiveRecord(int $userId): bool
    {
        return Student::where('user_id', $userId)->exists()
            || Teacher::where('user_id', $userId)->exists()
            || DB::table('staff')->where('user_id', $userId)->whereNull('deleted_at')->exists()
            || (Schema::hasTable('guardians') && DB::table('guardians')->where('user_id', $userId)->exists());
    }

    /** An auto-generated login email that no account (deleted ones included) holds. */
    private function freeEmail(string $firstName, string $lastName, int $n, string $domain): string
    {
        $base  = strtolower(preg_replace('/[^a-z0-9]/i', '', $firstName) . '.' . preg_replace('/[^a-z0-9]/i', '', $lastName));
        $email = $base . $n . '@' . $domain;
        while (User::withTrashed()->where('email', $email)->exists()) {
            $email = $base . $n . '.' . uniqid() . '@' . $domain;
        }

        return $email;
    }

    /**
     * Creates the student/teacher row, or brings back a deleted one with the
     * same email (keeping its id, history and — unless the sheet gives a new
     * one — its admission number / employee id) instead of colliding with it.
     */
    private function createOrRestore(string $model, array $attrs, string $idField, bool $idGiven)
    {
        if ($model::where('email', $attrs['email'])->exists()) {
            throw new SkippedRow('Skipped — ' . strtolower(class_basename($model)) . " {$attrs['email']} already exists.");
        }

        $trashed = $model::onlyTrashed()->where('email', $attrs['email'])->first();
        if (! $trashed) {
            return $model::create($attrs);
        }

        if (! $idGiven && $trashed->{$idField}) {
            $attrs[$idField] = $trashed->{$idField};
        }
        $trashed->restore();
        $trashed->update($attrs);

        return $trashed;
    }

    /**
     * Schools fill in their own spreadsheets ("FIRST NAME", "PHONE NUMBER",
     * "DATE OF BIRTH", even "DEPRTMENT") rather than the template's
     * snake_case headers, so headers are normalised and common variants
     * mapped onto the template's column names.
     */
    private const HEADER_ALIASES = [
        'firstname' => 'first_name', 'first' => 'first_name', 'given_name' => 'first_name',
        'lastname' => 'last_name', 'surname' => 'last_name', 'last' => 'last_name', 'family_name' => 'last_name',
        'middlename' => 'middle_name', 'other_name' => 'middle_name', 'other_names' => 'middle_name', 'othernames' => 'middle_name',
        'email_address' => 'email', 'e_mail' => 'email',
        'phone_number' => 'phone', 'phone_no' => 'phone', 'mobile' => 'phone', 'mobile_number' => 'phone', 'telephone' => 'phone', 'gsm' => 'phone',
        'staff_id' => 'employee_id', 'employee_no' => 'employee_id', 'employee_number' => 'employee_id', 'staff_no' => 'employee_id',
        'deprtment' => 'department', 'departmet' => 'department', 'dept' => 'department', 'department_name' => 'department',
        'class_name' => 'class', 'current_class' => 'class', 'arm_name' => 'arm', 'class_arm' => 'arm', 'section' => 'arm',
        'subject_name' => 'subject', 'subject_code' => 'subject', 'exam_name' => 'exam',
        'years_of_experience' => 'experience_years', 'experience' => 'experience_years', 'years_experience' => 'experience_years',
        'date_of_employment' => 'employment_date', 'employment_start_date' => 'employment_date', 'date_employed' => 'employment_date',
        'dob' => 'date_of_birth', 'birth_date' => 'date_of_birth', 'birthday' => 'date_of_birth',
        'home_address' => 'address', 'residential_address' => 'address', 'contact_address' => 'address',
        'sex' => 'gender',
        'admission_no' => 'admission_number', 'admission_num' => 'admission_number', 'reg_no' => 'admission_number', 'registration_number' => 'admission_number',
        'date_of_admission' => 'admission_date',
        'guardian2_name' => 'parent2_name', 'guardian2_email' => 'parent2_email', 'guardian2_phone' => 'parent2_phone', 'guardian2_relationship' => 'parent2_relationship',
        'guardian3_name' => 'parent3_name', 'guardian3_email' => 'parent3_email', 'guardian3_phone' => 'parent3_phone', 'guardian3_relationship' => 'parent3_relationship',
        'parent1_name' => 'parent_name', 'parent1_email' => 'parent_email', 'parent1_phone' => 'parent_phone', 'parent1_relationship' => 'parent_relationship',
        'guardian_relationship' => 'parent_relationship', 'parent_relation' => 'parent_relationship',
        'guardian_name' => 'parent_name', 'parent_guardian_name' => 'parent_name', 'guardian_phone' => 'parent_phone', 'parent_phone_number' => 'parent_phone', 'guardian_email' => 'parent_email',
        'type_of_employment' => 'employment_type',
        // Parent sheets: the children column.
        'wards' => 'children', 'students' => 'children', 'child' => 'children', 'children_admission_numbers' => 'children',
        'student_admission_numbers' => 'children', 'admission_numbers' => 'children', 'relationship_to_student' => 'relationship', 'relation' => 'relationship',
    ];

    private function normalizeHeaders(array $headers): array
    {
        return array_map(function ($h) {
            $key = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(ltrim((string) $h, "\xEF\xBB\xBF"))), '_');
            // "Parent 2 Name", "Guardian_2_Email", "parent1_phone" → parent2_name, parent2_email, parent_phone.
            $key = preg_replace_callback('/^(?:parent|guardian)_?([123])_/', fn ($m) => $m[1] === '1' ? 'parent_' : "parent{$m[1]}_", $key);

            return self::HEADER_ALIASES[$key] ?? $key;
        }, $headers);
    }

    /** "N/A", "nil", "-" and the like mean "no value" — treat them as blank. */
    private function cleanRow(array $row): array
    {
        return array_map(function ($v) {
            $v = trim((string) $v);

            return preg_match('/^(n\/?a|nil|none|null|-+)$/i', $v) ? '' : $v;
        }, $row);
    }

    /**
     * Accepts YYYY-MM-DD (and 2025-9-8), day-first dates as written in
     * Nigeria (23/12/1990, 23-12-1990, 23.12.1990, 26/11/80), and Excel date serial
     * numbers (45878), which is how .xlsx stores a date-formatted cell.
     */
    private function parseDate(string $value, string $field): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{5}(\.\d+)?$/', $value)) {
            return \Carbon\Carbon::create(1899, 12, 30)->addDays((int) $value)->format('Y-m-d');
        }

        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4}|\d{2})$/', $value, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($month > 12 && $day <= 12) {
                [$day, $month] = [$month, $day];
            }
            // Two-digit years: up to this year → 20xx, otherwise 19xx (26/11/80 → 1980).
            if (strlen($m[3]) === 2) {
                $year += $year <= (int) date('y') ? 2000 : 1900;
            }
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception) {
            throw new \Exception("'{$field}' has an invalid date: \"{$value}\". Use YYYY-MM-DD or DD/MM/YYYY.");
        }
    }

    private function normalizeGender(string $raw): ?string
    {
        $map = [
            'm' => 'male', 'male' => 'male', 'boy' => 'male',
            'f' => 'female', 'female' => 'female', 'girl' => 'female',
            'o' => 'other', 'other' => 'other',
        ];
        $key = strtolower(trim($raw));
        if ($key === '') {
            return null;
        }
        if (!isset($map[$key])) {
            throw new \Exception("'gender' must be male, female, or other — got \"{$raw}\".");
        }
        return $map[$key];
    }

    private function normalizeEmploymentType(string $raw): string
    {
        $map = [
            'full_time'  => 'full_time', 'fulltime'  => 'full_time', 'full time'  => 'full_time', 'full-time' => 'full_time',
            'part_time'  => 'part_time', 'parttime'  => 'part_time', 'part time'  => 'part_time', 'part-time' => 'part_time',
            'contract'   => 'contract',
        ];
        $key = strtolower(trim($raw));
        if ($key === '') {
            return 'full_time';
        }
        if (!isset($map[$key])) {
            throw new \Exception("'employment_type' must be full_time, part_time, or contract — got \"{$raw}\".");
        }
        return $map[$key];
    }

    private function parseNumeric(string $raw, string $field): float
    {
        $value = trim($raw);
        if (!is_numeric($value)) {
            throw new \Exception("'{$field}' must be a number — got \"{$value}\".");
        }
        return (float) $value;
    }

    private function schoolDomain(School $school): string
    {
        return \App\Support\SchoolEmailDomain::for($school);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Students
    // ─────────────────────────────────────────────────────────────────────────

    private function processStudents(BulkUpload $upload): void
    {
        $school = School::find($upload->school_id);
        if (!$school) {
            throw new \RuntimeException("School #{$upload->school_id} not found.");
        }
        $upload->update(['total_rows' => $this->countCsvRows($upload->file_path)]);

        $errors = [];
        $success = 0;
        $failed = 0;
        $processed = 0;
        $domain = $this->schoolDomain($school);
        $lookups = new BulkUploadLookups();

        foreach ($this->parseCsv($upload->file_path) as $row => $data) {
            if ($upload->fresh()->status === 'cancelled') {
                break;
            }

            try {
                DB::beginTransaction();

                $firstName = $this->requireField($data, 'first_name');
                $lastName  = $this->requireField($data, 'last_name');

                // Class/arm by name ("JSS 1", "A"); the old class_id/arm_id
                // columns are still read so earlier templates keep working.
                // Resolved before anything is created so a bad name fails
                // the row cleanly.
                $classId = $lookups->classId($data['class'] ?? $data['class_id'] ?? null);
                $armId   = $lookups->armId($data['arm'] ?? $data['arm_id'] ?? null, $classId);

                $emailGiven = trim($data['email'] ?? '') !== '';
                $email    = trim($data['email'] ?? '') ?: $this->freeEmail($firstName, $lastName, $processed + 1, $domain);
                $password = $this->newPassword();
                $user     = $this->claimUser($email, trim($firstName . ' ' . $lastName), $password, 'student');

                $admissionNumber = trim($data['admission_number'] ?? '');
                $admissionGiven  = $admissionNumber !== '';
                if (!$admissionNumber) {
                    $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $school->name), 0, 3));
                    $base   = $prefix . date('Y');
                    $seq    = Student::where('school_id', $upload->school_id)->count() + $processed + 1;
                    $admissionNumber = $base . str_pad($seq, 4, '0', STR_PAD_LEFT);
                    // Ensure uniqueness
                    while (Student::withTrashed()->where('admission_number', $admissionNumber)->exists()) {
                        $seq++;
                        $admissionNumber = $base . str_pad($seq, 4, '0', STR_PAD_LEFT);
                    }
                } elseif (Student::withTrashed()->where('admission_number', $admissionNumber)->where('email', '!=', $email)->exists()) {
                    throw new \Exception("Admission number already exists: {$admissionNumber}");
                }

                $student = $this->createOrRestore(Student::class, [
                    'school_id'       => $upload->school_id,
                    'user_id'         => $user->id,
                    'first_name'      => $firstName,
                    'last_name'       => $lastName,
                    'middle_name'     => trim($data['middle_name'] ?? '') ?: null,
                    'email'           => $email,
                    'phone'           => trim($data['phone'] ?? '') ?: null,
                    'admission_number'=> $admissionNumber,
                    'class_id'        => $classId,
                    'arm_id'          => $armId,
                    'date_of_birth'   => $this->parseDate($data['date_of_birth'] ?? '', 'date_of_birth'),
                    'gender'          => $this->normalizeGender($data['gender'] ?? ''),
                    'address'         => trim($data['address'] ?? '') ?: null,
                    'parent_name'     => trim($data['parent_name'] ?? '') ?: null,
                    'parent_phone'    => trim($data['parent_phone'] ?? '') ?: null,
                    'parent_email'    => trim($data['parent_email'] ?? '') ?: null,
                    'status'          => 'active',
                    'admission_date'  => $this->parseDate($data['admission_date'] ?? '', 'admission_date') ?? now()->format('Y-m-d'),
                ], 'admission_number', $admissionGiven);

                // Up to three parents per student (parent_*, parent2_*,
                // parent3_*): each is linked to an existing parent or created
                // with their own login, so they appear on the Parents page.
                $newParents = [];
                foreach ($this->parentsFromRow($data) as $i => $p) {
                    [$guardian, $parentPassword] = $this->findOrCreateGuardian($upload, $p, $lastName, $domain);
                    $guardian->students()->syncWithoutDetaching([$student->id => [
                        'relationship' => $p['relationship'], 'is_primary' => $i === 0, 'emergency_contact' => $i === 0,
                    ]]);
                    if ($parentPassword && $p['email']) {
                        $newParents[] = [$guardian, $parentPassword];
                    }
                }

                DB::commit();
                $success++;

                foreach ($newParents as [$guardian, $parentPassword]) {
                    $this->emailLoginDetails($school, $guardian->email, trim("{$guardian->first_name} {$guardian->last_name}"), $parentPassword, 'parent');
                }

                // Their own email if the sheet has one, otherwise the parent's.
                $parentEmail = trim($data['parent_email'] ?? '');
                if ($emailGiven) {
                    $this->emailLoginDetails($school, $email, trim($firstName . ' ' . $lastName), $password, 'student');
                } elseif (filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
                    $this->emailLoginDetails($school, $email, trim($firstName . ' ' . $lastName), $password, 'student',
                        $parentEmail, trim($data['parent_name'] ?? '') ?: null);
                }

            } catch (SkippedRow $e) {
                DB::rollBack();
                $this->skipped++;
                $errors[] = ['row' => $row, 'error' => $e->getMessage(), 'skipped' => true];
            } catch (\Exception $e) {
                DB::rollBack();
                $failed++;
                $errors[] = ['row' => $row, 'error' => $e->getMessage()];
            }

            $processed++;
            $this->saveProgress($upload, $processed, $success, $failed, $errors);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Teachers
    // ─────────────────────────────────────────────────────────────────────────

    private function processTeachers(BulkUpload $upload): void
    {
        $school = School::find($upload->school_id);
        if (!$school) {
            throw new \RuntimeException("School #{$upload->school_id} not found.");
        }
        $upload->update(['total_rows' => $this->countCsvRows($upload->file_path)]);

        $errors = [];
        $success = 0;
        $failed = 0;
        $processed = 0;
        $domain = $this->schoolDomain($school);
        $prefix = strtoupper(substr($school->name, 0, 3));
        $lookups = new BulkUploadLookups();

        foreach ($this->parseCsv($upload->file_path) as $row => $data) {
            if ($upload->fresh()->status === 'cancelled') {
                break;
            }

            try {
                DB::beginTransaction();

                $firstName = $this->requireField($data, 'first_name');
                $lastName  = $this->requireField($data, 'last_name');

                // Department by name; department_id still read for old templates.
                $departmentId = $lookups->departmentId($data['department'] ?? $data['department_id'] ?? null);

                $emailGiven = trim($data['email'] ?? '') !== '';
                $email    = trim($data['email'] ?? '') ?: $this->freeEmail($firstName, $lastName, $processed + 1, $domain);
                $password = $this->newPassword();
                $user     = $this->claimUser($email, trim($firstName . ' ' . $lastName), $password, 'teacher');

                $employeeId = trim($data['employee_id'] ?? '');
                $employeeGiven = $employeeId !== '';
                if (!$employeeId) {
                    $lastTeacher = Teacher::where('school_id', $upload->school_id)->orderByDesc('id')->first();
                    $num = $lastTeacher ? (intval(substr($lastTeacher->employee_id ?? '0', -4)) + $processed + 1) : ($processed + 1);
                    $employeeId = $prefix . 'TE' . str_pad($num, 4, '0', STR_PAD_LEFT);
                    // Deleted teachers still hold their ids — skip past any taken one.
                    while (Teacher::withTrashed()->where('employee_id', $employeeId)->exists()) {
                        $employeeId = $prefix . 'TE' . str_pad(++$num, 4, '0', STR_PAD_LEFT);
                    }
                } elseif (Teacher::withTrashed()->where('employee_id', $employeeId)->where('email', '!=', $email)->exists()) {
                    throw new \Exception("Employee ID already exists: {$employeeId}");
                }

                $this->createOrRestore(Teacher::class, [
                    'school_id'       => $upload->school_id,
                    'user_id'         => $user->id,
                    'employee_id'     => $employeeId,
                    'first_name'      => $firstName,
                    'last_name'       => $lastName,
                    'middle_name'     => trim($data['middle_name'] ?? '') ?: null,
                    'email'           => $email,
                    'phone'           => trim($data['phone'] ?? '') ?: null,
                    'gender'          => $this->normalizeGender($data['gender'] ?? ''),
                    'date_of_birth'   => $this->parseDate($data['date_of_birth'] ?? '', 'date_of_birth'),
                    'address'         => trim($data['address'] ?? '') ?: null,
                    'qualification'   => trim($data['qualification'] ?? '') ?: null,
                    'specialization'  => trim($data['specialization'] ?? '') ?: null,
                    'experience_years'=> is_numeric($data['experience_years'] ?? '') ? (int) $data['experience_years'] : 0,
                    'employment_date' => $this->parseDate($data['employment_date'] ?? '', 'employment_date') ?? now()->format('Y-m-d'),
                    'employment_type' => $this->normalizeEmploymentType($data['employment_type'] ?? ''),
                    'department_id'   => $departmentId,
                    'title'           => trim($data['title'] ?? '') ?: null,
                    'status'          => 'active',
                ], 'employee_id', $employeeGiven);

                DB::commit();
                $success++;

                if ($emailGiven) {
                    $this->emailLoginDetails($school, $email, trim($firstName . ' ' . $lastName), $password, 'teacher');
                }

            } catch (SkippedRow $e) {
                DB::rollBack();
                $this->skipped++;
                $errors[] = ['row' => $row, 'error' => $e->getMessage(), 'skipped' => true];
            } catch (\Exception $e) {
                DB::rollBack();
                $failed++;
                $errors[] = ['row' => $row, 'error' => $e->getMessage()];
            }

            $processed++;
            $this->saveProgress($upload, $processed, $success, $failed, $errors);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Staff
    // ─────────────────────────────────────────────────────────────────────────

    private function processStaff(BulkUpload $upload): void
    {
        $school = School::find($upload->school_id);
        if (!$school) {
            throw new \RuntimeException("School #{$upload->school_id} not found.");
        }
        $upload->update(['total_rows' => $this->countCsvRows($upload->file_path)]);

        $errors = [];
        $success = 0;
        $failed = 0;
        $processed = 0;
        $domain = $this->schoolDomain($school);
        $prefix = strtoupper(substr($school->name, 0, 3));

        foreach ($this->parseCsv($upload->file_path) as $row => $data) {
            if ($upload->fresh()->status === 'cancelled') {
                break;
            }

            try {
                DB::beginTransaction();

                $firstName = $this->requireField($data, 'first_name');
                $lastName  = $this->requireField($data, 'last_name');

                $allowedRoles = ['admin', 'staff', 'accountant', 'librarian', 'driver', 'security', 'cleaner', 'caterer', 'nurse', 'care_assistant', 'sen_facilitator', 'sen_therapist', 'counsellor'];
                $role = strtolower(trim($data['role'] ?? '')) ?: 'staff';
                if (!in_array($role, $allowedRoles, true)) {
                    throw new \Exception("'role' must be one of: " . implode(', ', $allowedRoles) . " — got \"{$role}\".");
                }

                $middle   = trim($data['middle_name'] ?? '');
                $fullName = trim($firstName . ($middle ? ' ' . $middle : '') . ' ' . $lastName);

                $emailGiven = trim($data['email'] ?? '') !== '';
                $email    = trim($data['email'] ?? '') ?: $this->freeEmail($firstName, $lastName, $processed + 1, $domain);
                $password = $this->newPassword();
                // The login gets the row's actual role (it was always 'staff').
                $user     = $this->claimUser($email, $fullName, $password, $role);

                // The staff model has no SoftDeletes, but the table has
                // deleted_at and StaffController soft-deletes into it.
                if (Staff::where('email', $email)->whereNull('deleted_at')->exists()) {
                    throw new SkippedRow("Skipped — staff {$email} already exists.");
                }
                $deletedStaff = Staff::where('email', $email)->whereNotNull('deleted_at')->first();

                $employeeId = trim($data['employee_id'] ?? '');
                if (!$employeeId) {
                    $lastStaff = Staff::where('school_id', $upload->school_id)->orderByDesc('id')->first();
                    $num = $lastStaff ? (intval(substr($lastStaff->employee_id ?? '0', -4)) + $processed + 1) : ($processed + 1);
                    $employeeId = $deletedStaff?->employee_id ?: $prefix . 'STF' . str_pad($num, 4, '0', STR_PAD_LEFT);
                    while (! $deletedStaff?->employee_id && Staff::where('employee_id', $employeeId)->exists()) {
                        $employeeId = $prefix . 'STF' . str_pad(++$num, 4, '0', STR_PAD_LEFT);
                    }
                } elseif (Staff::where('employee_id', $employeeId)->where('email', '!=', $email)->exists()) {
                    throw new \Exception("Employee ID already exists: {$employeeId}");
                }

                $staffAttrs = [
                    'school_id'       => $upload->school_id,
                    'user_id'         => $user->id,
                    'employee_id'     => $employeeId,
                    'first_name'      => $firstName,
                    'last_name'       => $lastName,
                    'middle_name'     => $middle ?: null,
                    'email'           => $email,
                    'phone'           => trim($data['phone'] ?? '') ?: null,
                    'gender'          => $this->normalizeGender($data['gender'] ?? ''),
                    'date_of_birth'   => $this->parseDate($data['date_of_birth'] ?? '', 'date_of_birth'),
                    'role'            => $role,
                    'department'      => trim($data['department'] ?? '') ?: null,
                    'employment_date' => $this->parseDate($data['employment_date'] ?? '', 'employment_date') ?? now()->format('Y-m-d'),
                    'status'          => 'active',
                ];

                if ($deletedStaff) {
                    DB::table('staff')->where('id', $deletedStaff->id)->update(['deleted_at' => null]);
                    $deletedStaff->update($staffAttrs);
                } else {
                    Staff::create($staffAttrs);
                }

                DB::commit();
                $success++;

                if ($emailGiven) {
                    $this->emailLoginDetails($school, $email, $fullName, $password, 'staff');
                }

            } catch (SkippedRow $e) {
                DB::rollBack();
                $this->skipped++;
                $errors[] = ['row' => $row, 'error' => $e->getMessage(), 'skipped' => true];
            } catch (\Exception $e) {
                DB::rollBack();
                $failed++;
                $errors[] = ['row' => $row, 'error' => $e->getMessage()];
            }

            $processed++;
            $this->saveProgress($upload, $processed, $success, $failed, $errors);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scores (CA or Exam Results)
    // ─────────────────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────────────────
    // Parents / guardians
    // ─────────────────────────────────────────────────────────────────────────

    private function processGuardians(BulkUpload $upload): void
    {
        $school = School::find($upload->school_id);
        if (!$school) {
            throw new \RuntimeException("School #{$upload->school_id} not found.");
        }
        $upload->update(['total_rows' => $this->countCsvRows($upload->file_path)]);

        $errors = [];
        $success = 0;
        $failed = 0;
        $processed = 0;
        $domain = $this->schoolDomain($school);

        foreach ($this->parseCsv($upload->file_path) as $row => $data) {
            if ($upload->fresh()->status === 'cancelled') {
                break;
            }

            try {
                DB::beginTransaction();

                $firstName = $this->requireField($data, 'first_name');
                $lastName  = $this->requireField($data, 'last_name');
                $middle    = trim($data['middle_name'] ?? '');
                $fullName  = trim("{$firstName} {$lastName}");
                $relationship = trim($data['relationship'] ?? '') ?: 'Parent';

                // Children by admission number — all must exist, checked
                // before anything is created so a typo fails the row cleanly.
                $childRefs = array_values(array_filter(array_map('trim',
                    preg_split('/[;,]+/', (string) ($data['children'] ?? $data['admission_number'] ?? '')))));
                $children = Student::whereIn('admission_number', $childRefs)->get();
                $unknown = array_diff($childRefs, $children->pluck('admission_number')->all());
                if ($unknown) {
                    throw new \Exception('Student admission number(s) not found: ' . implode(', ', $unknown) . '.');
                }

                $emailGiven = trim($data['email'] ?? '') !== '';
                $email = trim($data['email'] ?? '') ?: $this->freeEmail($firstName, $lastName, $processed + 1, $domain);

                // Already on the list: just link any new children, then skip.
                $existing = $emailGiven ? Guardian::where('email', $email)->first() : null;
                if ($existing) {
                    // Keep the new links, then record the skip without
                    // throwing — our transaction is already committed.
                    $linked = $this->linkChildren($existing, $children, $relationship);
                    DB::commit();
                    $this->skipped++;
                    $errors[] = ['row' => $row, 'skipped' => true,
                        'error' => "Skipped — parent {$email} already exists" . ($linked ? "; linked {$linked} new child(ren)." : '.')];
                    $processed++;
                    $this->saveProgress($upload, $processed, $success, $failed, $errors);
                    continue;
                }

                $password = $this->newPassword();
                $user = $this->claimUser($email, $fullName, $password, 'guardian');

                $guardian = Guardian::create([
                    'school_id'   => $upload->school_id,
                    'user_id'     => $user->id,
                    'first_name'  => $firstName,
                    'last_name'   => $lastName,
                    'middle_name' => $middle ?: null,
                    'email'       => $email,
                    'phone'       => trim($data['phone'] ?? '') ?: null,
                    'address'     => trim($data['address'] ?? '') ?: null,
                    'occupation'  => trim($data['occupation'] ?? '') ?: null,
                    'relationship_to_student' => $relationship,
                    'status'      => 'active',
                ]);
                $this->linkChildren($guardian, $children, $relationship);

                DB::commit();
                $success++;

                if ($emailGiven) {
                    $this->emailLoginDetails($school, $email, $fullName, $password, 'parent');
                }

            } catch (SkippedRow $e) {
                DB::rollBack();
                $this->skipped++;
                $errors[] = ['row' => $row, 'error' => $e->getMessage(), 'skipped' => true];
            } catch (\Exception $e) {
                DB::rollBack();
                $failed++;
                $errors[] = ['row' => $row, 'error' => $e->getMessage()];
            }

            $processed++;
            $this->saveProgress($upload, $processed, $success, $failed, $errors);
        }
    }

    /**
     * The parents given on a student row: parent_* , parent2_* and parent3_*
     * (name, email, phone, relationship). A slot with no name, email or
     * phone is ignored.
     *
     * @return array<int, array{name:string, email:string, phone:string, relationship:string}>
     */
    private function parentsFromRow(array $data): array
    {
        $parents = [];
        foreach (['parent', 'parent2', 'parent3'] as $n => $prefix) {
            $p = [
                'name'  => trim($data["{$prefix}_name"] ?? ''),
                'email' => trim($data["{$prefix}_email"] ?? ''),
                'phone' => trim($data["{$prefix}_phone"] ?? ''),
                'relationship' => trim($data["{$prefix}_relationship"] ?? ''),
            ];
            if ($p['name'] === '' && $p['email'] === '' && $p['phone'] === '') {
                continue;
            }
            if ($p['email'] !== '' && ! filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
                throw new \Exception("{$prefix}_email is not a valid email: \"{$p['email']}\".");
            }
            $p['relationship'] = $p['relationship'] ?: ($n === 0 ? 'Parent' : 'Guardian');
            $parents[] = $p;
        }

        return $parents;
    }

    /**
     * An existing parent (same email, or same phone when no email is given)
     * or a new one with their own login. Returns [guardian, new password or
     * null]. If the email already belongs to someone else's account (e.g. a
     * teacher who is also a parent) the parent is still created and linked,
     * just without a separate parent login, rather than failing the row.
     */
    private function findOrCreateGuardian(BulkUpload $upload, array $p, string $studentLastName, string $domain): array
    {
        $existing = $p['email'] !== ''
            ? Guardian::where('email', $p['email'])->first()
            : ($p['phone'] !== '' ? Guardian::where('phone', $p['phone'])->first() : null);
        if ($existing) {
            return [$existing, null];
        }

        $parts = preg_split('/\s+/', $p['name'] ?: 'Parent');
        $first = array_shift($parts);
        $last  = implode(' ', $parts) ?: $studentLastName;

        $email = $p['email'] ?: $this->freeEmail($first, $last, random_int(1000, 9999), $domain);
        $password = $this->newPassword();
        try {
            $userId = $this->claimUser($email, trim("{$first} {$last}"), $password, 'guardian')->id;
        } catch (SkippedRow|\Exception $e) {
            $userId = null;
            $password = null;
        }

        $guardian = Guardian::create([
            'school_id'  => $upload->school_id,
            'user_id'    => $userId,
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => $email,
            'phone'      => $p['phone'] ?: null,
            'relationship_to_student' => $p['relationship'],
            'status'     => 'active',
        ]);

        return [$guardian, $password];
    }

    /** Links children not already linked; returns how many were added. */
    private function linkChildren(Guardian $guardian, $children, string $relationship): int
    {
        $already = $guardian->students()->pluck('students.id')->all();
        $new = $children->reject(fn ($c) => in_array($c->id, $already, true));
        foreach ($new as $child) {
            $guardian->students()->attach($child->id, ['relationship' => $relationship, 'is_primary' => false, 'emergency_contact' => false]);
        }

        return $new->count();
    }

    private function processScores(BulkUpload $upload): void
    {
        $meta = $upload->meta ?? [];
        $upload->update(['total_rows' => $this->countCsvRows($upload->file_path)]);
        $lookups = new BulkUploadLookups();
        $termId = isset($meta['term_id']) ? (int) $meta['term_id'] : null;

        $errors = [];
        $success = 0;
        $failed = 0;
        $processed = 0;

        foreach ($this->parseCsv($upload->file_path) as $row => $data) {
            if ($upload->fresh()->status === 'cancelled') {
                break;
            }

            try {
                DB::beginTransaction();

                $student = null;
                $admissionNo = trim($data['admission_number'] ?? '');
                $studentId = trim($data['student_id'] ?? '');

                if ($admissionNo) {
                    $student = Student::where('admission_number', $admissionNo)
                        ->where('school_id', $upload->school_id)
                        ->first();
                } elseif ($studentId) {
                    $student = Student::where('id', $studentId)
                        ->where('school_id', $upload->school_id)
                        ->first();
                }

                if (!$student) {
                    throw new \Exception("Student not found: " . ($admissionNo ?: $studentId ?: 'no identifier'));
                }

                $caId = $meta['continuous_assessment_id'] ?? (($data['continuous_assessment_id'] ?? '') ?: null);

                // Exam by name (e.g. "First Term Examination"), matched within
                // the student's class and the selected term; exam_id still read.
                $exam = null;
                if (! $caId) {
                    $exam = isset($meta['exam_id'])
                        ? Exam::find((int) $meta['exam_id'])
                        : $lookups->exam($data['exam'] ?? $data['exam_id'] ?? null, $student->class_id, $termId);
                }

                if (!$caId && !$exam) {
                    throw new \Exception("Pick the assessment on the Scores page before uploading, or fill the exam column with the exam's name.");
                }

                $score = $this->parseNumeric($data['score'] ?? '', 'score');

                if ($caId) {
                    CAScore::updateOrCreate(
                        [
                            'continuous_assessment_id' => (int) $caId,
                            'student_id'               => $student->id,
                        ],
                        [
                            'score'       => $score,
                            'remarks'     => trim($data['remarks'] ?? '') ?: null,
                            'recorded_by' => $upload->user_id,
                        ]
                    );
                } else {
                    // Subject by name or code within the student's class; an
                    // exam set for a single subject supplies it when blank.
                    $subjectId = $lookups->subjectId($data['subject'] ?? $data['subject_id'] ?? null, $student->class_id)
                        ?? $exam->subject_id;
                    if (!$subjectId) {
                        throw new \Exception("Fill the subject column (subject name or code, e.g. Mathematics or MTH).");
                    }

                    $totalMarks = ($data['total_marks'] ?? '') !== '' ? $this->parseNumeric($data['total_marks'], 'total_marks') : 100.0;
                    $grade = trim($data['grade'] ?? '') ?: null;

                    ExamScoreSync::write(
                        examId: (int) $exam->id,
                        studentId: (int) $student->id,
                        subjectId: (int) $subjectId,
                        score: $score,
                        totalMarks: (float) $totalMarks,
                        grade: $grade,
                        remarks: trim($data['remarks'] ?? '') ?: null,
                        recordedBy: $upload->user_id,
                    );
                }

                DB::commit();
                $success++;

            } catch (SkippedRow $e) {
                DB::rollBack();
                $this->skipped++;
                $errors[] = ['row' => $row, 'error' => $e->getMessage(), 'skipped' => true];
            } catch (\Exception $e) {
                DB::rollBack();
                $failed++;
                $errors[] = ['row' => $row, 'error' => $e->getMessage()];
            }

            $processed++;
            $this->saveProgress($upload, $processed, $success, $failed, $errors);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function failed(\Throwable $e): void
    {
        $upload = BulkUpload::find($this->uploadId);
        if (!$upload) {
            return;
        }

        $errors = array_merge($upload->errors ?? [], [['row' => 0, 'error' => $e->getMessage()]]);
        $upload->update(['status' => 'failed', 'completed_at' => now(), 'errors' => $errors]);
        $this->tryBroadcast($upload->fresh());

        Log::error("ProcessBulkUploadJob permanently failed", [
            'upload_id' => $this->uploadId,
            'error'     => $e->getMessage(),
        ]);
    }

    private function tryBroadcast(BulkUpload $upload): void
    {
        try {
            broadcast(new BulkUploadProgressEvent($upload));
        } catch (\Throwable $e) {
            Log::warning("Bulk upload broadcast failed (upload #{$upload->id})", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
