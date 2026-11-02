<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Saves an uploaded picture or document and returns a URL that works.
 *
 * Cloud storage (S3) when it accepts the write; otherwise this server's own
 * disk, served through TenantFileController. Without this, a broken or revoked
 * S3 key made every upload fail (or, with the disk's throw=false, quietly
 * return a link to nothing). A failed S3 write is remembered for 10 minutes so
 * uploads don't each wait for it.
 */
class MediaStorage
{
    private const S3_DOWN = 'media_storage:s3_down';

    /** @return array{path: string, url: string, disk: string} */
    public static function put(UploadedFile $file, string $dir, array $options = []): array
    {
        $dir = trim($dir, '/');
        $ext = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'bin'));
        $name = Str::random(40) . '.' . preg_replace('/[^a-z0-9]/', '', $ext);

        if (self::s3Configured() && ! Cache::get(self::S3_DOWN)) {
            try {
                $path = Storage::disk('s3')->putFileAs($dir, $file, $name, $options + ['ContentType' => $file->getMimeType()]);
                if ($path) {
                    return ['path' => $path, 'url' => Storage::disk('s3')->url($path), 'disk' => 's3'];
                }
            } catch (\Throwable $e) {
                report($e);
            }
            Cache::put(self::S3_DOWN, true, 600);
        }

        $path = Storage::disk('public')->putFileAs($dir, $file, $name);
        if (! $path) {
            throw new \RuntimeException('The file could not be saved. Please try again.');
        }

        return ['path' => $path, 'url' => TenantUrl::forPublicDiskKey($path), 'disk' => 'public'];
    }

    public static function s3Configured(): bool
    {
        return filled(config('filesystems.disks.s3.key')) && filled(config('filesystems.disks.s3.secret')) && filled(config('filesystems.disks.s3.bucket'));
    }
}
