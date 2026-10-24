<?php

namespace Tests\Unit;

use App\Services\FileUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileUploadServiceTest extends TestCase
{
    public function test_upload_stores_the_file_on_s3_without_an_acl(): void
    {
        config(['filesystems.disks.s3.key' => 'k', 'filesystems.disks.s3.secret' => 's', 'filesystems.disks.s3.bucket' => 'b']);
        Storage::fake('s3');

        $r = (new FileUploadService())->uploadFile(UploadedFile::fake()->image('pupil.jpg'), 'uploads');

        Storage::disk('s3')->assertExists($r['key']);
        $this->assertStringStartsWith('uploads/', $r['key']);
    }

    public function test_a_rejected_write_is_reported_instead_of_returning_a_dead_url(): void
    {
        config(['filesystems.disks.s3.key' => 'k', 'filesystems.disks.s3.secret' => 's', 'filesystems.disks.s3.bucket' => 'b']);
        $disk = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->shouldReceive('putFileAs')->andReturn(false);
        Storage::set('s3', $disk);

        $this->expectException(\RuntimeException::class);
        (new FileUploadService())->uploadFile(UploadedFile::fake()->image('pupil.jpg'), 'uploads');
    }
}
