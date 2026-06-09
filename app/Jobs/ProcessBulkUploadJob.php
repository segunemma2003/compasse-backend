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
        Storage::delete($upload->file_path);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CSV helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function parseCsv(string $filePath): \Generator
    {
        $path = Storage::path($filePath);
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // XLSX: convert to in-memory CSV rows via ZipArchive
        if ($ext === 'xlsx' || $ext === 'xls') {
            $rows = $this->readXlsxRows($path);
            $headers   = $rows[0] ?? [];
            $rowNumber = 1;
            foreach (array_slice($rows, 1) as $row) {
                $rowNumber++;
                $padded = array_pad($row, count($headers), '');
                if (count(array_filter($padded, fn($v) => $v !== '')) > 0) {
                    yield $rowNumber => array_combine($headers, $padded);
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
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if (count(array_filter($row, fn($v) => $v !== '')) > 0) {
                yield $rowNumber => array_combine(
                    $headers,
                    array_pad($row, count($headers), '')
                );
            }
        }

        fclose($handle);
    }

    private function countCsvRows(string $filePath): int
    {
        $path = Storage::path($filePath);
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'xlsx' || $ext === 'xls') {
            $rows = $this->readXlsxRows($path);
            return max(0, count($rows) - 1); // minus header
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            return 0;
        }
        $count = -1; // subtract header
        while (fgetcsv($handle) !== false) {
            $count++;
        }
        fclose($handle);
        return max(0, $count);
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

    private function schoolDomain(School $school): string
    {
        if ($school->website) {
            return rtrim(preg_replace('/^(https?:\/\/)?(www\.)?/', '', $school->website), '/');
        }
        if ($school->tenant) {
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

                $email = trim($data['email'] ?? '');
                if (!$email) {
                    $email = strtolower($data['first_name'] . '.' . $data['last_name'] . rand(100, 999)) . '@' . $domain;
                }

                if (User::where('email', $email)->exists()) {
                    throw new \Exception("Email already exists: {$email}");
                }

                $user = User::create([
                    'name'               => trim($data['first_name'] . ' ' . $data['last_name']),
                    'email'              => $email,
                    'password'           => bcrypt('Student@123'),
                    'role'               => 'student',
                    'status'             => 'active',
                    'email_verified_at'  => now(),
                ]);

                $admissionNumber = trim($data['admission_number'] ?? '');
                if (!$admissionNumber) {
                    $prefix = strtoupper(substr($school->name, 0, 3));
                    $count = Student::where('school_id', $upload->school_id)->count() + $processed + 1;
                    $admissionNumber = $prefix . date('Y') . str_pad($count, 4, '0', STR_PAD_LEFT);
                }

                Student::create([
                    'school_id'       => $upload->school_id,
                    'user_id'         => $user->id,
                    'first_name'      => trim($data['first_name']),
                    'last_name'       => trim($data['last_name']),
                    'middle_name'     => trim($data['middle_name'] ?? '') ?: null,
                    'email'           => $email,
                    'phone'           => trim($data['phone'] ?? '') ?: null,
                    'admission_number'=> $admissionNumber,
                    'class_id'        => $data['class_id'] ?: null,
                    'arm_id'          => $data['arm_id'] ?: null,
                    'date_of_birth'   => $data['date_of_birth'] ?: null,
                    'gender'          => strtolower(trim($data['gender'] ?? '')) ?: null,
                    'address'         => trim($data['address'] ?? '') ?: null,
                    'parent_name'     => trim($data['parent_name'] ?? '') ?: null,
                    'parent_phone'    => trim($data['parent_phone'] ?? '') ?: null,
                    'status'          => 'active',
                    'admission_date'  => $data['admission_date'] ?: now(),
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

                $email = trim($data['email'] ?? '');
                if (!$email) {
                    $email = strtolower($data['first_name'] . '.' . $data['last_name'] . rand(100, 999)) . '@' . $domain;
                }

                if (User::where('email', $email)->exists()) {
                    throw new \Exception("Email already exists: {$email}");
                }

                $user = User::create([
                    'name'              => trim($data['first_name'] . ' ' . $data['last_name']),
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
                }

                Teacher::create([
                    'school_id'       => $upload->school_id,
                    'user_id'         => $user->id,
                    'employee_id'     => $employeeId,
                    'first_name'      => trim($data['first_name']),
                    'last_name'       => trim($data['last_name']),
                    'middle_name'     => trim($data['middle_name'] ?? '') ?: null,
                    'email'           => $email,
                    'phone'           => trim($data['phone'] ?? '') ?: null,
                    'gender'          => strtolower(trim($data['gender'] ?? '')) ?: null,
                    'date_of_birth'   => $data['date_of_birth'] ?: null,
                    'address'         => trim($data['address'] ?? '') ?: null,
                    'qualification'   => trim($data['qualification'] ?? '') ?: null,
                    'specialization'  => trim($data['specialization'] ?? '') ?: null,
                    'experience_years'=> (int) ($data['experience_years'] ?? 0),
                    'employment_date' => $data['employment_date'] ?: ($data['hire_date'] ?: now()),
                    'employment_type' => $data['employment_type'] ?: 'full_time',
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

                $email = trim($data['email'] ?? '');
                if (!$email) {
                    $email = strtolower($data['first_name'] . '.' . $data['last_name'] . rand(100, 999)) . '@' . $domain;
                }

                if (User::where('email', $email)->exists()) {
                    throw new \Exception("Email already exists: {$email}");
                }

                $fullName = trim($data['first_name'] . ' ' . ($data['middle_name'] ?? '') . ' ' . $data['last_name']);

                $user = User::create([
                    'name'              => preg_replace('/\s+/', ' ', $fullName),
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
                }

                Staff::create([
                    'school_id'       => $upload->school_id,
                    'user_id'         => $user->id,
                    'employee_id'     => $employeeId,
                    'first_name'      => trim($data['first_name']),
                    'last_name'       => trim($data['last_name']),
                    'middle_name'     => trim($data['middle_name'] ?? '') ?: null,
                    'email'           => $email,
                    'phone'           => trim($data['phone'] ?? '') ?: null,
                    'role'            => trim($data['role'] ?? 'staff'),
                    'department'      => trim($data['department'] ?? '') ?: null,
                    'employment_date' => $data['employment_date'] ?: ($data['hire_date'] ?: now()),
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

                $caId = $meta['continuous_assessment_id'] ?? ($data['continuous_assessment_id'] ?: null);

                if ($caId) {
                    CAScore::updateOrCreate(
                        [
                            'continuous_assessment_id' => (int) $caId,
                            'student_id'               => $student->id,
                        ],
                        [
                            'score'       => (float) $data['score'],
                            'remarks'     => trim($data['remarks'] ?? '') ?: null,
                            'recorded_by' => $upload->user_id,
                        ]
                    );
                } else {
                    $examId    = $meta['exam_id'] ?? ($data['exam_id'] ?: null);
                    $subjectId = $data['subject_id'] ?: null;

                    Result::updateOrCreate(
                        [
                            'student_id' => $student->id,
                            'exam_id'    => $examId ? (int) $examId : null,
                            'subject_id' => $subjectId ? (int) $subjectId : null,
                        ],
                        [
                            'score'       => (float) $data['score'],
                            'total_marks' => (float) ($data['total_marks'] ?? 100),
                            'grade'       => trim($data['grade'] ?? '') ?: null,
                            'remarks'     => trim($data['remarks'] ?? '') ?: null,
                            'status'      => 'active',
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
