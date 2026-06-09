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
                'gender', 'address', 'admission_date', 'parent_name', 'parent_phone',
            ],
            'example' => [
                'John', 'Doe', 'Michael', 'john.doe@example.com', '+2348012345678',
                'ADM2024001', '1', '1', '2010-01-15',
                'male', '123 Main St', '2024-09-01', 'Jane Doe', '+2348087654321',
            ],
            'notes' => 'email, admission_number, class_id, arm_id are optional — they will be auto-generated if blank.',
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
            'notes' => 'email and employee_id are optional — auto-generated if blank. employment_type: full_time|part_time|contract.',
        ],
        'staff' => [
            'headers' => [
                'first_name', 'last_name', 'middle_name', 'email', 'phone',
                'employee_id', 'role', 'department', 'employment_date',
                'gender', 'date_of_birth', 'address',
            ],
            'example' => [
                'Peter', 'Johnson', '', 'peter@school.com', '+2348012345678',
                'STF001', 'admin', 'Administration', '2024-01-01',
                'male', '1990-03-15', '789 Staff Ave',
            ],
            'notes' => 'role options: admin|staff|accountant|librarian|driver|security|cleaner|caterer|nurse.',
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
            'notes' => 'Provide either admission_number or student_id. For CA scores set continuous_assessment_id; for exam results set exam_id. Pass meta[continuous_assessment_id] or meta[exam_id] in the upload request to apply to all rows.',
        ],
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Upload
    // ─────────────────────────────────────────────────────────────────────────

    public function upload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file'                          => 'required|file|mimes:csv,txt|max:51200',
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
    // CSV template download
    // ─────────────────────────────────────────────────────────────────────────

    public function downloadTemplate(string $type): StreamedResponse|JsonResponse
    {
        if (!isset(self::TEMPLATES[$type])) {
            return response()->json(['success' => false, 'message' => 'Invalid type. Use: ' . implode(', ', self::TYPES)], 422);
        }

        $tpl = self::TEMPLATES[$type];

        return response()->streamDownload(function () use ($tpl) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $tpl['headers']);
            fputcsv($handle, $tpl['example']);
            fclose($handle);
        }, "bulk-upload-template-{$type}.csv", [
            'Content-Type' => 'text/csv',
        ]);
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
