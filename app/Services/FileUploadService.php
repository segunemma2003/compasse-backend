<?php

namespace App\Services;

use Aws\S3\S3Client;
use Aws\S3\PostObjectV4;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class FileUploadService
{
    protected $s3Client;
    protected $bucket;

    public function __construct()
    {
        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region'),
            'credentials' => [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
        ]);
        
        $this->bucket = config('filesystems.disks.s3.bucket');
    }

    /**
     * Generate presigned URL for file upload
     */
    public function generatePresignedUrl(string $key, string $contentType, int $expiresIn = 3600): array
    {
        $command = $this->s3Client->getCommand('PutObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ContentType' => $contentType,
        ]);

        $request = $this->s3Client->createPresignedRequest($command, "+{$expiresIn} seconds");

        return [
            'url' => (string) $request->getUri(),
            'fields' => [],
            'key' => $key,
            'bucket' => $this->bucket,
        ];
    }

    /**
     * Generate presigned URL for file download
     */
    public function generateDownloadUrl(string $key, int $expiresIn = 3600): string
    {
        $command = $this->s3Client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);

        $request = $this->s3Client->createPresignedRequest($command, "+{$expiresIn} seconds");

        return (string) $request->getUri();
    }

    /**
     * Upload file directly to S3
     */
    public function uploadFile(UploadedFile $file, string $path = null): array
    {
        $filename = $this->generateUniqueFilename($file);
        $key = $path ? "{$path}/{$filename}" : $filename;
        
        $uploaded = Storage::disk('s3')->putFileAs(
            $path ?? '',
            $file,
            $filename,
            [
                'visibility' => 'public',
                'ContentType' => $file->getMimeType(),
            ]
        );

        return [
            'key' => $key,
            'url' => Storage::disk('s3')->url($key),
            'filename' => $filename,
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ];
    }

    /**
     * Delete file from S3
     */
    public function deleteFile(string $key): bool
    {
        return Storage::disk('s3')->delete($key);
    }

    /**
     * Get file info from S3
     */
    public function getFileInfo(string $key): array
    {
        try {
            $result = $this->s3Client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            return [
                'key' => $key,
                'size' => $result['ContentLength'],
                'mime_type' => $result['ContentType'],
                'last_modified' => $result['LastModified'],
                'url' => Storage::disk('s3')->url($key),
            ];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Generate unique filename
     */
    protected function generateUniqueFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $filename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $filename = Str::slug($filename);
        
        return $filename . '_' . time() . '_' . Str::random(8) . '.' . $extension;
    }

    /**
     * Generate upload URLs for different file types
     */
    public function generateUploadUrls(string $type, string $entityId = null): array
    {
        $basePath = $this->getBasePath($type, $entityId);
        
        return [
            'profile_picture' => $this->generatePresignedUrl(
                "{$basePath}/profile_pictures/{$this->generateUniqueKey()}.jpg",
                'image/jpeg'
            ),
            'document' => $this->generatePresignedUrl(
                "{$basePath}/documents/{$this->generateUniqueKey()}.pdf",
                'application/pdf'
            ),
            'image' => $this->generatePresignedUrl(
                "{$basePath}/images/{$this->generateUniqueKey()}.jpg",
                'image/jpeg'
            ),
            'video' => $this->generatePresignedUrl(
                "{$basePath}/videos/{$this->generateUniqueKey()}.mp4",
                'video/mp4'
            ),
            'audio' => $this->generatePresignedUrl(
                "{$basePath}/audio/{$this->generateUniqueKey()}.mp3",
                'audio/mpeg'
            ),
        ];
    }

    /**
     * Get base path for file uploads
     */
    protected function getBasePath(string $type, string $entityId = null): string
    {
        $tenantId = config('tenant.id', 'default');
        
        switch ($type) {
            case 'school':
                return "tenants/{$tenantId}/schools/{$entityId}";
            case 'student':
                return "tenants/{$tenantId}/students/{$entityId}";
            case 'teacher':
                return "tenants/{$tenantId}/teachers/{$entityId}";
            case 'guardian':
                return "tenants/{$tenantId}/guardians/{$entityId}";
            case 'exam':
                return "tenants/{$tenantId}/exams/{$entityId}";
            case 'question':
                return "tenants/{$tenantId}/questions/{$entityId}";
            case 'assignment':
                return "tenants/{$tenantId}/assignments/{$entityId}";
            case 'result':
                return "tenants/{$tenantId}/results/{$entityId}";
            default:
                return "tenants/{$tenantId}/general";
        }
    }

    /**
     * Generate unique key for file
     */
    protected function generateUniqueKey(): string
    {
        return time() . '_' . Str::random(16);
    }

    /**
     * Get file URL with presigned URL
     */
    public function getFileUrl(string $key, int $expiresIn = 3600): string
    {
        if (config('filesystems.default') === 's3') {
            return $this->generateDownloadUrl($key, $expiresIn);
        }
        
        return Storage::url($key);
    }

    /**
     * Batch upload multiple files
     */
    public function batchUpload(array $files, string $path = null): array
    {
        $results = [];
        
        foreach ($files as $file) {
            $results[] = $this->uploadFile($file, $path);
        }
        
        return $results;
    }

    /**
     * Get upload progress (for large files)
     */
    public function getUploadProgress(string $key): array
    {
        try {
            $result = $this->s3Client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            return [
                'exists' => true,
                'size' => $result['ContentLength'],
                'last_modified' => $result['LastModified'],
            ];
        } catch (\Exception $e) {
            return [
                'exists' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
