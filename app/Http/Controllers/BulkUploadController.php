<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessBulkUploadJob;
use App\Models\BulkUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BulkUploadController extends Controller
{
    private const TYPES = ['students', 'teachers', 'staff', 'scores'];

    private const TEMPLATES = [
        'students' => [
            'headers' => [
                'first_name', 'last_name', 'middle_name', 'email', 'phone',
                'admission_number', 'class_id', 'arm_id', 'date_of_birth',
                'gender', 'address', 'admission_date', 'parent_name', 'parent_phone', 'parent_email',
            ],
            'example' => [
                'John', 'Doe', 'Michael', 'john.doe@example.com', '+2348012345678',
                'ADM2024001', '1', '1', '2010-01-15',
                'male', '123 Main St', '2024-09-01', 'Jane Doe', '+2348087654321', 'jane.doe@example.com',
            ],
            'notes' => 'first_name and last_name are required. email and admission_number are auto-generated if blank. class_id and arm_id are optional IDs — leave blank to leave the student unassigned. gender: male|female|other. Dates must be YYYY-MM-DD.',
        ],
        'teachers' => [
            'headers' => [
                'first_name', 'last_name', 'middle_name', 'email', 'phone',
                'employee_id', 'department_id', 'qualification', 'specialization',
                'experience_years', 'employment_date', 'employment_type',
                'gender', 'date_of_birth', 'address', 'title',
            ],
            'example' => [
                'Jane', 'Smith', 'A.', 'jane.smith@school.com', '+2348012345678',
                'TCH001', '1', 'B.Ed Mathematics', 'Mathematics',
                '5', '2024-01-01', 'full_time',
                'female', '1985-06-20', '456 Teacher Lane', 'Mrs.',
            ],
            'notes' => 'first_name and last_name are required. email and employee_id are auto-generated if blank. employment_type: full_time|part_time|contract (default: full_time). gender: male|female|other. Dates must be YYYY-MM-DD. department_id is the numeric ID of an existing department.',
        ],
        'staff' => [
            'headers' => [
                'first_name', 'last_name', 'middle_name', 'email', 'phone',
                'employee_id', 'role', 'department', 'employment_date',
                'gender', 'date_of_birth',
            ],
            'example' => [
                'Peter', 'Johnson', '', 'peter@school.com', '+2348012345678',
                'STF001', 'admin', 'Administration', '2024-01-01',
                'male', '1990-03-15',
            ],
            'notes' => 'first_name and last_name are required. email and employee_id are auto-generated if blank. role: admin|staff|accountant|librarian|driver|security|cleaner|caterer|nurse (default: staff). gender: male|female|other. Dates must be YYYY-MM-DD.',
        ],
        'scores' => [
            'headers' => [
                'admission_number', 'student_id', 'subject_id',
                'exam_id', 'continuous_assessment_id', 'score', 'total_marks', 'grade', 'remarks',
            ],
            'example' => [
                'ADM2024001', '', '1',
                '1', '', '85.5', '100', 'A', 'Excellent performance',
            ],
            'notes' => 'Provide either admission_number OR student_id (not both). score must be a number. For CA scores: provide continuous_assessment_id (exam_id must be blank). For exam results: provide exam_id and subject_id (continuous_assessment_id must be blank). You may also pass meta[exam_id] or meta[continuous_assessment_id] in the upload request to apply one value to all rows.',
        ],
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Upload
    // ─────────────────────────────────────────────────────────────────────────

    public function upload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file'                          => 'required|file|mimes:csv,txt,xlsx,xls|max:51200',
            'type'                          => 'required|in:students,teachers,staff,scores',
            'meta'                          => 'nullable|array',
            'meta.term_id'                  => 'required_if:type,scores|nullable|integer',
            'meta.academic_year_id'         => 'required_if:type,scores|nullable|integer',
            'meta.continuous_assessment_id' => 'nullable|integer',
            'meta.exam_id'                  => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $schoolId = $this->getSchoolIdFromTenant($request);
        if (!$schoolId) {
            return response()->json(['success' => false, 'message' => 'School context not found'], 400);
        }

        $file     = $request->file('file');
        $filePath = $file->store("bulk-uploads/{$schoolId}/{$request->type}", 'local');

        $upload = BulkUpload::create([
            'school_id'      => $schoolId,
            'user_id'        => $request->user()->id,
            'type'           => $request->type,
            'status'         => 'pending',
            'file_path'      => $filePath,
            'file_name'      => $file->getClientOriginalName(),
            'total_rows'     => 0,
            'processed_rows' => 0,
            'success_rows'   => 0,
            'failed_rows'    => 0,
            'meta'           => $request->meta ?? [],
        ]);

        ProcessBulkUploadJob::dispatch($upload->id);

        return response()->json([
            'success' => true,
            'message' => 'File uploaded and queued for processing. Connect to the WebSocket channel for live progress.',
            'data'    => [
                'upload_id'         => $upload->id,
                'type'              => $upload->type,
                'status'            => $upload->status,
                'file_name'         => $upload->file_name,
                'websocket_channel' => "private-bulk-upload.{$upload->id}",
                'school_channel'    => "private-school.{$schoolId}",
                'event'             => 'upload.progress',
            ],
        ], 202);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Status
    // ─────────────────────────────────────────────────────────────────────────

    public function status(Request $request, int $uploadId): JsonResponse
    {
        $upload   = BulkUpload::find($uploadId);
        $schoolId = $this->getSchoolIdFromTenant($request);

        if (!$upload) {
            return response()->json(['success' => false, 'message' => 'Upload not found'], 404);
        }

        if ($upload->school_id !== $schoolId) {
            return $this->forbiddenResponse();
        }

        return response()->json([
            'success' => true,
            'data'    => $this->formatUpload($upload),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // List
    // ─────────────────────────────────────────────────────────────────────────

    public function list(Request $request): JsonResponse
    {
        $schoolId = $this->getSchoolIdFromTenant($request);
        if (!$schoolId) {
            return response()->json(['success' => false, 'message' => 'School context not found'], 400);
        }

        $uploads = BulkUpload::where('school_id', $schoolId)
            ->when($request->type,   fn($q) => $q->where('type', $request->type))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 15));

        $uploads->getCollection()->transform(fn($u) => $this->formatUpload($u));

        return response()->json(['success' => true, 'data' => $uploads]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cancel
    // ─────────────────────────────────────────────────────────────────────────

    public function cancel(Request $request, int $uploadId): JsonResponse
    {
        $upload   = BulkUpload::find($uploadId);
        $schoolId = $this->getSchoolIdFromTenant($request);

        if (!$upload) {
            return response()->json(['success' => false, 'message' => 'Upload not found'], 404);
        }

        if ($upload->school_id !== $schoolId) {
            return $this->forbiddenResponse();
        }

        if (!in_array($upload->status, ['pending', 'processing'], true)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot cancel an upload with status '{$upload->status}'.",
            ], 400);
        }

        $upload->update(['status' => 'cancelled', 'completed_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Upload cancelled.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CSV template download (opens in Excel, Numbers, Google Sheets)
    // ─────────────────────────────────────────────────────────────────────────

    public function downloadTemplate(string $type): \Illuminate\Http\Response|JsonResponse
    {
        if (!isset(self::TEMPLATES[$type])) {
            return response()->json(['success' => false, 'message' => 'Invalid type. Use: ' . implode(', ', self::TYPES)], 422);
        }

        $tpl     = self::TEMPLATES[$type];
        $content = $this->buildCsv($tpl['headers'], $tpl['example']);

        return response($content, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="bulk-upload-template-' . $type . '.csv"',
            'Content-Length'      => (string) strlen($content),
            'Cache-Control'       => 'no-store, no-cache, must-revalidate',
            'Pragma'              => 'no-cache',
        ]);
    }

    /**
     * Build a UTF-8 CSV with BOM. Row 1 = headers, Row 2 = example (delete before upload).
     */
    private function buildCsv(array $headers, array $example): string
    {
        $row = function (array $cells): string {
            return implode(',', array_map(function ($cell) {
                $cell = (string) $cell;
                if ($cell === '' || ! preg_match('/[",\r\n]/', $cell)) {
                    return $cell;
                }

                return '"' . str_replace('"', '""', $cell) . '"';
            }, $cells));
        };

        return "\xEF\xBB\xBF" . $row($headers) . "\r\n" . $row($example) . "\r\n";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Template info (JSON)
    // ─────────────────────────────────────────────────────────────────────────

    public function templateInfo(string $type): JsonResponse
    {
        if (!isset(self::TEMPLATES[$type])) {
            return response()->json(['success' => false, 'message' => 'Invalid type'], 422);
        }

        $tpl = self::TEMPLATES[$type];

        return response()->json([
            'success' => true,
            'data'    => [
                'type'    => $type,
                'headers' => $tpl['headers'],
                'example' => array_combine($tpl['headers'], $tpl['example']),
                'notes'   => $tpl['notes'],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function formatUpload(BulkUpload $upload): array
    {
        return [
            'id'             => $upload->id,
            'type'           => $upload->type,
            'status'         => $upload->status,
            'file_name'      => $upload->file_name,
            'progress'       => $upload->progress,
            'total_rows'     => $upload->total_rows,
            'processed_rows' => $upload->processed_rows,
            'success_rows'   => $upload->success_rows,
            'failed_rows'    => $upload->failed_rows,
            'errors'         => $upload->errors ?? [],
            'meta'           => $upload->meta ?? [],
            'started_at'     => $upload->started_at?->toIso8601String(),
            'completed_at'   => $upload->completed_at?->toIso8601String(),
            'created_at'     => $upload->created_at->toIso8601String(),
        ];
    }
}
