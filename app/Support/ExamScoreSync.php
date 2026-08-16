<?php

namespace App\Support;

use App\Models\ExamSubmission;
use App\Models\Result;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps exam scores in exam_submissions (source of truth for result generation)
 * and mirrors to the legacy results table when present.
 */
class ExamScoreSync
{
    public static function write(
        int $examId,
        int $studentId,
        int $subjectId,
        float $score,
        float $totalMarks = 100.0,
        ?string $grade = null,
        ?string $remarks = null,
        ?int $recordedBy = null,
        string $status = 'pending',
    ): void {
        ExamSubmission::query()->updateOrCreate(
            [
                'exam_id'    => $examId,
                'student_id' => $studentId,
            ],
            [
                'score'       => $score,
                'remarks'     => $remarks,
                'recorded_by' => $recordedBy,
            ]
        );

        if (! Schema::hasTable('results')) {
            return;
        }

        Result::query()->updateOrCreate(
            [
                'student_id' => $studentId,
                'exam_id'    => $examId,
                'subject_id' => $subjectId,
            ],
            [
                'score'       => $score,
                'total_marks' => $totalMarks,
                'grade'       => $grade,
                'remarks'     => $remarks,
                'status'      => $status,
            ]
        );
    }
}
