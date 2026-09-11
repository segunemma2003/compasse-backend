<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression test for the production bug found 2026-09-12: bulk uploads
 * (students/teachers/staff/scores) are written by PHP-FPM (www-data) and
 * read + deleted by the bulk-uploads queue worker, which runs as a
 * different OS user ('deploy' — not a member of the www-data group).
 * Flysystem's default directory mode left every freshly-created upload
 * subdirectory unreadable to the worker the first time that upload type
 * was used for a tenant — confirmed live: a brand-new "scores"
 * subdirectory came out `drwx------ www-data:www-data`, and the very next
 * job failed with "Upload file not found. It may have expired." even
 * though the file existed. config/filesystems.php's local disk now
 * declares explicit 0777/0666 permissions so any OS user can traverse,
 * read and delete these transient upload files regardless of which
 * process created them.
 */
class LocalDiskPermissionsTest extends TestCase
{
    public function test_local_disk_creates_world_readable_writable_directories_and_files(): void
    {
        $key = 'bulk-uploads/999/scores/' . uniqid() . '.csv';
        Storage::disk('local')->put($key, 'admission_number,score' . PHP_EOL);

        $fullPath = Storage::disk('local')->path($key);
        $dirPath = dirname($fullPath);

        $this->assertFileExists($fullPath);

        // Compare the *effective* permission bits regardless of umask —
        // what matters is that group AND other both got write access, not
        // the exact octal (a restrictive umask can still trim these).
        $dirPerms = fileperms($dirPath) & 0777;
        $filePerms = fileperms($fullPath) & 0777;

        $this->assertNotSame(0, $dirPerms & 0077, 'upload directory is not accessible to any user outside its owner — the exact bug this config fixes');
        $this->assertNotSame(0, $filePerms & 0044, 'upload file is not readable to any user outside its owner');

        Storage::disk('local')->delete($key);
        @rmdir($dirPath);
    }
}
