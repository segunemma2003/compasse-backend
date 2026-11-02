<?php

namespace Tests\Unit;

use App\Services\FileUploadService;
use App\Support\MediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileUploadServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.disks.s3.key' => 'k', 'filesystems.disks.s3.secret' => 's', 'filesystems.disks.s3.bucket' => 'b']);
        Cache::flush();
    }

    public function test_upload_goes_to_cloud_storage_when_it_accepts_the_write(): void
    {
        Storage::fake('s3');
        Storage::fake('public');

        $r = (new FileUploadService())->uploadFile(UploadedFile::fake()->image('pupil.jpg'), 'uploads');

        Storage::disk('s3')->assertExists($r['key']);
        $this->assertStringStartsWith('uploads/', $r['key']);
    }

    public function test_a_refused_cloud_write_falls_back_to_this_server_with_a_working_link(): void
    {
        // e.g. a revoked AWS key: the write returns false (throw=false) instead of a path.
        $s3 = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $s3->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::set('s3', $s3);
        Storage::fake('public');

        $r = (new FileUploadService())->uploadFile(UploadedFile::fake()->image('pupil.jpg'), 'uploads');

        Storage::disk('public')->assertExists($r['key']);
        $this->assertStringContainsString($r['key'], $r['url']);

        // Remembered: the next upload goes straight to this server (putFileAs not called again).
        $again = MediaStorage::put(UploadedFile::fake()->image('b.jpg'), 'uploads');
        $this->assertSame('public', $again['disk']);
    }

    public function test_an_exception_from_cloud_storage_also_falls_back(): void
    {
        $s3 = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $s3->shouldReceive('putFileAs')->andThrow(new \RuntimeException('InvalidAccessKeyId'));
        Storage::set('s3', $s3);
        Storage::fake('public');

        $saved = MediaStorage::put(UploadedFile::fake()->create('form.pdf', 20, 'application/pdf'), 'schools/1/landing/content');

        $this->assertSame('public', $saved['disk']);
        Storage::disk('public')->assertExists($saved['path']);
        $this->assertStringEndsWith('.pdf', $saved['path']);
    }
}
