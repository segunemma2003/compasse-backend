<?php

namespace App\Jobs;

use App\Events\BulkUploadProgressEvent;
use App\Models\BulkUpload;
use App\Models\CAScore;
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
use Illuminate\Support\Facades\Storage;

class ProcessBulkUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600;

    private const BROADCAST_EVERY = 10; // rows between progress broadcasts

    public function __construct(public readonly int $uploadId)
    {
        $this->onQueue('bulk-uploads');
    }

    public function handle(): void
    {
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
            broadcast(new BulkUploadProgressEvent($upload->fresh()));
            return;
        }

        $upload->update(['status' => 'processing', 'started_at' => now()]);
        broadcast(new BulkUploadProgressEvent($upload->fresh()));

        try {
            match ($upload->type) {
                'students' => $this->processStudents($upload),
                'teachers' => $this->processTeachers($upload),
                'staff'    => $this->processStaff($upload),
                'scores'   => $this->processScores($upload),
                default    => throw new \InvalidArgumentException("Unknown upload type: {$upload->type}"),
            };

            $upload->update(['status' => 'completed', 'completed_at' => now()]);

        } catch (\Exception $e) {
            Log::error("Bulk upload {$this->uploadId} failed", ['error' => $e->getMessage()]);
            $current = $upload->fresh();
            $errors = array_merge($current->errors ?? [], [['row' => 0, 'error' => $e->getMessage()]]);
            $upload->update(['status' => 'failed', 'completed_at' => now(), 'errors' => $errors]);
        }

        broadcast(new BulkUploadProgressEvent($upload->fresh()));
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
            $headers   = array_map('trim', $rows[0] ?? []);
            if (isset($headers[0])) {
                $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
            }
            $headerCount = count($headers);
            $rowNumber   = 1;
            foreach (array_slice($rows, 1) as $row) {
                $rowNumber++;
                // Truncate to header count first, then pad — avoids array_combine mismatch
                $fitted = array_pad(array_slice($row, 0, $headerCount), $headerCount, '');
                if (count(array_filter($fitted, fn($v) => $v !== '')) > 0) {
                    yield $rowNumber => array_combine($headers, $fitted);
                }
            }
            return;
        }

        // CSV / TXT path
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new \RuntimeException("Cannot open file for reading.");
        }

        $headers = fgetcsv($handle);
        // Strip UTF-8 BOM from the first header if present
        if ($headers && isset($headers[0])) {
            $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
        }
        $headers     = array_map('trim', $headers ?? []);
        $headerCount = count($headers);
        $rowNumber   = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if (count(array_filter($row, fn($v) => $v !== null && $v !== '')) > 0) {
                // Truncate to header count first, then pad — avoids array_combine mismatch
                $fitted = array_pad(array_slice($row, 0, $headerCount), $headerCount, '');
                yield $rowNumber => array_combine($headers, $fitted);
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
                // Concatenate all <t> nodes (handles rich-text runs)
                $text = '';
                foreach ($si->xpath('.//t') as $t) {
                    $text .= (string) $t;
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
        ]);

        if ($processed % self::BROADCAST_EVERY === 0) {
            broadcast(new BulkUploadProgressEvent($upload->fresh()));
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

    private function parseDate(string $value, string $field): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception) {
            throw new \Exception("'{$field}' has an invalid date: \"{$value}\". Use YYYY-MM-DD format.");
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
            'full_time'  => 'full_time', 'fulltime'  => 'full_time', 'full time'  => 'full_time',
            'part_time'  => 'part_time', 'parttime'  => 'part_time', 'part time'  => 'part_time',
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
        if ($school->website) {
            return rtrim(preg_replace('/^(https?:\/\/)?(www\.)?/', '', $school->website), '/');
        }
        if ($school->tenant?->subdomain) {
            return $school->tenant->subdomain . '.compasse.net';
        }
        return 'compasse.net';
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

        foreach ($this->parseCsv($upload->file_path) as $row => $data) {
            if ($upload->fresh()->status === 'cancelled') {
                break;
            }

            try {
                DB::beginTransaction();

                $firstName = $this->requireField($data, 'first_name');
                $lastName  = $this->requireField($data, 'last_name');

                $email = trim($data['email'] ?? '');
                if (!$email) {
                    $base  = strtolower(preg_replace('/[^a-z0-9]/i', '', $firstName) . '.' . preg_replace('/[^a-z0-9]/i', '', $lastName));
                    $email = $base . ($processed + 1) . '@' . $domain;
                    while (User::where('email', $email)->exists()) {
                        $email = $base . ($processed + 1) . '.' . uniqid() . '@' . $domain;
                    }
                } elseif (User::where('email', $email)->exists()) {
                    throw new \Exception("Email already exists: {$email}");
                }

                $user = User::create([
                    'name'               => trim($firstName . ' ' . $lastName),
                    'email'              => $email,
                    'password'           => bcrypt('Student@123'),
                    'role'               => 'student',
                    'status'             => 'active',
                    'email_verified_at'  => now(),
                ]);

                $admissionNumber = trim($data['admission_number'] ?? '');
                if (!$admissionNumber) {
                    $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $school->name), 0, 3));
                    $base   = $prefix . date('Y');
                    $seq    = Student::where('school_id', $upload->school_id)->count() + $processed + 1;
                    $admissionNumber = $base . str_pad($seq, 4, '0', STR_PAD_LEFT);
                    // Ensure uniqueness
                    while (Student::where('admission_number', $admissionNumber)->exists()) {
                        $seq++;
                        $admissionNumber = $base . str_pad($seq, 4, '0', STR_PAD_LEFT);
                    }
                } elseif (Student::where('admission_number', $admissionNumber)->exists()) {
                    throw new \Exception("Admission number already exists: {$admissionNumber}");
                }

                Student::create([
                    'school_id'       => $upload->school_id,
                    'user_id'         => $user->id,
                    'first_name'      => $firstName,
                    'last_name'       => $lastName,
                    'middle_name'     => trim($data['middle_name'] ?? '') ?: null,
                    'email'           => $email,
                    'phone'           => trim($data['phone'] ?? '') ?: null,
                    'admission_number'=> $admissionNumber,
                    'class_id'        => ($data['class_id'] ?? '') ?: null,
                    'arm_id'          => ($data['arm_id'] ?? '') ?: null,
                    'date_of_birth'   => $this->parseDate($data['date_of_birth'] ?? '', 'date_of_birth'),
                    'gender'          => $this->normalizeGender($data['gender'] ?? ''),
                    'address'         => trim($data['address'] ?? '') ?: null,
                    'parent_name'     => trim($data['parent_name'] ?? '') ?: null,
                    'parent_phone'    => trim($data['parent_phone'] ?? '') ?: null,
                    'parent_email'    => trim($data['parent_email'] ?? '') ?: null,
                    'status'          => 'active',
                    'admission_date'  => $this->parseDate($data['admission_date'] ?? '', 'admission_date') ?? now()->format('Y-m-d'),
                ]);

                DB::commit();
                $success++;

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

        foreach ($this->parseCsv($upload->file_path) as $row => $data) {
            if ($upload->fresh()->status === 'cancelled') {
                break;
            }

            try {
                DB::beginTransaction();

                $firstName = $this->requireField($data, 'first_name');
                $lastName  = $this->requireField($data, 'last_name');

                $email = trim($data['email'] ?? '');
                if (!$email) {
                    $base  = strtolower(preg_replace('/[^a-z0-9]/i', '', $firstName) . '.' . preg_replace('/[^a-z0-9]/i', '', $lastName));
                    $email = $base . ($processed + 1) . '@' . $domain;
                    while (User::where('email', $email)->exists()) {
                        $email = $base . ($processed + 1) . '.' . uniqid() . '@' . $domain;
                    }
                } elseif (User::where('email', $email)->exists()) {
                    throw new \Exception("Email already exists: {$email}");
                }

                $user = User::create([
                    'name'              => trim($firstName . ' ' . $lastName),
                    'email'             => $email,
                    'password'          => bcrypt('Teacher@123'),
                    'role'              => 'teacher',
                    'status'            => 'active',
                    'email_verified_at' => now(),
                ]);

                $employeeId = trim($data['employee_id'] ?? '');
                if (!$employeeId) {
                    $lastTeacher = Teacher::where('school_id', $upload->school_id)->orderByDesc('id')->first();
                    $num = $lastTeacher ? (intval(substr($lastTeacher->employee_id ?? '0', -4)) + $processed + 1) : ($processed + 1);
                    $employeeId = $prefix . 'TE' . str_pad($num, 4, '0', STR_PAD_LEFT);
                } elseif (Teacher::where('employee_id', $employeeId)->exists()) {
                    throw new \Exception("Employee ID already exists: {$employeeId}");
                }

                Teacher::create([
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
                    'department_id'   => $data['department_id'] ?: null,
                    'title'           => trim($data['title'] ?? '') ?: null,
                    'status'          => 'active',
                ]);

                DB::commit();
                $success++;

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

                $email = trim($data['email'] ?? '');
                if (!$email) {
                    $base  = strtolower(preg_replace('/[^a-z0-9]/i', '', $firstName) . '.' . preg_replace('/[^a-z0-9]/i', '', $lastName));
                    $email = $base . ($processed + 1) . '@' . $domain;
                    while (User::where('email', $email)->exists()) {
                        $email = $base . ($processed + 1) . '.' . uniqid() . '@' . $domain;
                    }
                } elseif (User::where('email', $email)->exists()) {
                    throw new \Exception("Email already exists: {$email}");
                }

                $middle   = trim($data['middle_name'] ?? '');
                $fullName = trim($firstName . ($middle ? ' ' . $middle : '') . ' ' . $lastName);

                $user = User::create([
                    'name'              => $fullName,
                    'email'             => $email,
                    'password'          => bcrypt('Staff@123'),
                    'role'              => 'staff',
                    'status'            => 'active',
                    'email_verified_at' => now(),
                ]);

                $employeeId = trim($data['employee_id'] ?? '');
                if (!$employeeId) {
                    $lastStaff = Staff::where('school_id', $upload->school_id)->orderByDesc('id')->first();
                    $num = $lastStaff ? (intval(substr($lastStaff->employee_id ?? '0', -4)) + $processed + 1) : ($processed + 1);
                    $employeeId = $prefix . 'STF' . str_pad($num, 4, '0', STR_PAD_LEFT);
                } elseif (Staff::where('employee_id', $employeeId)->exists()) {
                    throw new \Exception("Employee ID already exists: {$employeeId}");
                }

                $allowedRoles = ['admin', 'staff', 'accountant', 'librarian', 'driver', 'security', 'cleaner', 'caterer', 'nurse'];
                $role = strtolower(trim($data['role'] ?? 'staff'));
                if (!in_array($role, $allowedRoles, true)) {
                    throw new \Exception("'role' must be one of: " . implode(', ', $allowedRoles) . " — got \"{$role}\".");
                }

                Staff::create([
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
                ]);

                DB::commit();
                $success++;

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

    private function processScores(BulkUpload $upload): void
    {
        $meta = $upload->meta ?? [];
        $upload->update(['total_rows' => $this->countCsvRows($upload->file_path)]);

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

                $caId   = $meta['continuous_assessment_id'] ?? ($data['continuous_assessment_id'] ?: null);
                $examId = $meta['exam_id'] ?? ($data['exam_id'] ?: null);

                if (!$caId && !$examId) {
                    throw new \Exception("Row must have either continuous_assessment_id or exam_id.");
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
                    $subjectId = ($data['subject_id'] ?? '') ?: null;
                    if (!$subjectId) {
                        throw new \Exception("subject_id is required for exam result rows.");
                    }

                    $totalMarks = ($data['total_marks'] ?? '') !== '' ? $this->parseNumeric($data['total_marks'], 'total_marks') : 100.0;

                    Result::updateOrCreate(
                        [
                            'student_id' => $student->id,
                            'exam_id'    => (int) $examId,
                            'subject_id' => (int) $subjectId,
                        ],
                        [
                            'score'       => $score,
                            'total_marks' => $totalMarks,
                            'grade'       => trim($data['grade'] ?? '') ?: null,
                            'remarks'     => trim($data['remarks'] ?? '') ?: null,
                            'status'      => 'pending',
                        ]
                    );
                }

                DB::commit();
                $success++;

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
        broadcast(new BulkUploadProgressEvent($upload->fresh()));

        Log::error("ProcessBulkUploadJob permanently failed", [
            'upload_id' => $this->uploadId,
            'error'     => $e->getMessage(),
        ]);
    }
}
