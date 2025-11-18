<?php

namespace App\Http\Controllers;

use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class FileUploadController extends Controller
{
    protected $fileUploadService;

    public function __construct(FileUploadService $fileUploadService)
    {
        $this->fileUploadService = $fileUploadService;
    }

    /**
     * Get presigned URLs for file upload
     */
    public function getPresignedUrls(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|string|in:profile_picture,document,image,video,audio',
            'entity_type' => 'required|string|in:school,student,teacher,guardian,exam,question,assignment,result',
            'entity_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            // Check if S3 is configured
            if (!config('filesystems.disks.s3.key') || !config('filesystems.disks.s3.secret')) {
                return response()->json([
                    'error' => 'S3 not configured',
                    'message' => 'File upload service is not configured. Please configure AWS S3 credentials.',
                    'upload_urls' => []
                ], 500);
            }

            $urls = $this->fileUploadService->generateUploadUrls(
                $request->entity_type,
                $request->entity_id
            );

            return response()->json([
                'upload_urls' => $urls
            ]);

        } catch (\Exception $e) {
            // Return a more user-friendly error message
            return response()->json([
                'error' => 'File upload service unavailable',
                'message' => 'File upload service is not configured or unavailable. Please configure AWS S3 credentials in your environment.',
                'upload_urls' => []
            ], 503);
        }
    }

    /**
     * Upload file directly
     */
    public function uploadFile(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:10240', // 10MB max
            'path' => 'nullable|string',
            'entity_type' => 'required|string',
            'entity_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('file');
            $path = $request->path;

            $result = $this->fileUploadService->uploadFile($file, $path);

            return response()->json([
                'message' => 'File uploaded successfully',
                'file' => $result
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to upload file',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete file
     */
    public function deleteFile(Request $request, string $key): JsonResponse
    {
        try {
            $deleted = $this->fileUploadService->deleteFile($key);

            if ($deleted) {
                return response()->json([
                    'message' => 'File deleted successfully'
                ]);
            } else {
                return response()->json([
                    'error' => 'File not found or could not be deleted'
                ], 404);
            }

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete file',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get file info
     */
    public function getFileInfo(Request $request, string $key): JsonResponse
    {
        try {
            $info = $this->fileUploadService->getFileInfo($key);

            if (empty($info)) {
                return response()->json([
                    'error' => 'File not found'
                ], 404);
            }

            return response()->json([
                'file_info' => $info
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to get file info',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get file URL
     */
    public function getFileUrl(Request $request, string $key): JsonResponse
    {
        try {
            $url = $this->fileUploadService->getFileUrl($key);

            return response()->json([
                'file_url' => $url
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to get file URL',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Batch upload files
     */
    public function batchUpload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'files' => 'required|array|max:10',
            'files.*' => 'required|file|max:10240',
            'path' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $files = $request->file('files');
            $path = $request->path;

            $results = $this->fileUploadService->batchUpload($files, $path);

            return response()->json([
                'message' => 'Files uploaded successfully',
                'files' => $results
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to upload files',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload multiple files
     */
    public function uploadMultipleFiles(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'files' => 'required|array|max:10',
            'files.*' => 'required|file|max:10240', // 10MB max per file
            'path' => 'nullable|string',
            'entity_type' => 'nullable|string',
            'entity_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $files = $request->file('files');
            $path = $request->path ?? 'uploads';

            $results = [];
            foreach ($files as $file) {
                $result = $this->fileUploadService->uploadFile($file, $path);
                $results[] = $result;
            }

            return response()->json([
                'message' => 'Files uploaded successfully',
                'files' => $results,
                'count' => count($results)
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to upload files',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get upload progress
     */
    public function getUploadProgress(Request $request, string $key): JsonResponse
    {
        try {
            $progress = $this->fileUploadService->getUploadProgress($key);

            return response()->json([
                'progress' => $progress
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to get upload progress',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
