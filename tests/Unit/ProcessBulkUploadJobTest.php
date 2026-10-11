<?php

namespace Tests\Unit;

use App\Jobs\ProcessBulkUploadJob;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression test for the production bug fixed 2026-09-11: bulk student
 * and teacher uploads failed with League\Flysystem\UnableToCreateDirectory.
 * Two workers share the `bulk-uploads` queue (supervisor:
 * compasse-bulk-worker_00/_01), so the first write for a tenant could race
 * both processes into creating that tenant's storage directory at once —
 * confirmed live via the failed_jobs table. The fix: retry (so a transient
 * race self-heals once the other worker wins) and pre-create the storage
 * skeleton with a race-tolerant mkdir before any Storage::disk() call does
 * it lazily and unsafely.
 */
class ProcessBulkUploadJobTest extends TestCase
{
    public function test_the_job_retries_instead_of_failing_on_first_attempt(): void
    {
        $job = new ProcessBulkUploadJob(1);

        $this->assertSame(3, $job->tries, 'tries was reverted to 1 — a transient directory-creation race can no longer self-heal via retry');
        $this->assertSame([5, 15], $job->backoff());
    }

    public function test_ensure_tenant_storage_ready_creates_missing_directories_without_throwing(): void
    {
        $job = new ProcessBulkUploadJob(1);
        $method = new ReflectionMethod(ProcessBulkUploadJob::class, 'ensureTenantStorageReady');
        $method->setAccessible(true);

        // Calling it twice in a row is the same shape as two workers racing
        // to bootstrap the same tenant's storage — must be a no-op the
        // second time, not an exception.
        $method->invoke($job);
        $method->invoke($job);

        foreach (['app', 'app/public', 'app/private', 'framework/cache', 'framework/sessions', 'framework/views'] as $dir) {
            $this->assertDirectoryExists(storage_path($dir));
        }
    }

    /*
     * The rest covers a school's real teacher upload (2026-10-11) where every
     * row failed: their spreadsheet used "FIRST NAME"/"DEPRTMENT"-style
     * headers, "N/A" employee ids, Excel date serials and DD/MM/YYYY dates —
     * and Excel-saved .xlsx files read back with every text cell blank.
     */

    private function invokeJob(string $method, ...$args)
    {
        $m = new ReflectionMethod(ProcessBulkUploadJob::class, $method);
        $m->setAccessible(true);

        return $m->invoke(new ProcessBulkUploadJob(1), ...$args);
    }

    public function test_spreadsheet_style_headers_map_to_template_columns(): void
    {
        $this->assertSame(
            ['s_n', 'first_name', 'last_name', 'phone', 'department', 'experience_years', 'employment_date', 'date_of_birth', 'address', 'department_id'],
            $this->invokeJob('normalizeHeaders', ["\xEF\xBB\xBFS/N", 'FIRST NAME', 'Surname', 'PHONE NUMBER', 'DEPRTMENT', 'YEARS OF EXPERIENCE', 'DATE OF EMPLOYMENT', 'Date of Birth', 'HOME ADDRESS', 'department_id'])
        );
    }

    public function test_placeholder_values_are_blank(): void
    {
        $this->assertSame(
            ['employee_id' => '', 'email' => '', 'middle_name' => '', 'title' => 'MRS'],
            $this->invokeJob('cleanRow', ['employee_id' => 'N/A', 'email' => ' nil ', 'middle_name' => '-', 'title' => 'MRS'])
        );
    }

    public function test_dates_in_the_formats_schools_actually_use(): void
    {
        $cases = [
            '2025-9-8'   => '2025-09-08',
            '23/12/1990' => '1990-12-23',
            '23-12-1990' => '1990-12-23',
            '26/11/80'   => '1980-11-26',
            '12/25/1990' => '1990-12-25', // unambiguously month-first
            '45878'      => '2025-08-09', // Excel serial
            ''           => null,
        ];
        foreach ($cases as $raw => $expected) {
            $this->assertSame($expected, $this->invokeJob('parseDate', (string) $raw, 'date_of_birth'), "parsing \"{$raw}\"");
        }
    }

    public function test_excel_saved_xlsx_text_cells_are_read(): void
    {
        $ns   = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $path = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        $zip  = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('xl/sharedStrings.xml', "<sst xmlns=\"{$ns}\"><si><t>FIRST NAME</t></si><si><r><t>Ada</t></r><r><t>eze</t></r></si></sst>");
        $zip->addFromString('xl/worksheets/sheet1.xml', "<worksheet xmlns=\"{$ns}\"><sheetData>"
            . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="s"><v>0</v></c></row>'
            . '<row r="2"><c r="A2" t="s"><v>1</v></c><c r="C2"><v>45878</v></c></row>'
            . '</sheetData></worksheet>');
        $zip->close();

        try {
            $this->assertSame([['FIRST NAME', '', 'FIRST NAME'], ['Adaeze', '', '45878']], $this->invokeJob('readXlsxRows', $path));
        } finally {
            @unlink($path);
        }
    }
}
