<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** The Preschool Progress Report page calls these; they once went missing ("route could not be found"). */
class CheckpointReportRoutesTest extends TestCase
{
    public function test_student_report_routes_exist_for_staff_and_families(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->all();
        foreach ([
            'api/v1/checkpoint-report/student/{studentId}',
            'api/v1/checkpoint-report/student/{studentId}/pdf',
            'api/v1/checkpoint-report/class/{classId}',
            'api/v1/assessments/checkpoint-report/student/{studentId}',
        ] as $uri) {
            $this->assertContains($uri, $uris, $uri);
        }
    }
}
