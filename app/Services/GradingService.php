<?php

namespace App\Services;

use App\Models\School;
use App\Models\GradingScale;
use App\Models\Result;
use App\Models\Student;
use App\Models\ClassModel;
use App\Models\Subject;
use App\Models\Term;
use App\Models\AcademicYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GradingService
{
    /**
     * Calculate grade based on percentage and grading scale
     */
    public function calculateGrade(float $percentage, array $gradingSettings = null): string
    {
        if (!$gradingSettings) {
            $gradingSettings = $this->getDefaultGradingScale();
        }

        foreach ($gradingSettings['scales'] as $scale) {
            if ($percentage >= $scale['min_percentage'] && $percentage <= $scale['max_percentage']) {
                return $scale['grade'];
            }
        }

        return 'F'; // Default fail grade
    }

    /**
     * Generate comprehensive results for a class
     */
    public function generateClassResults(int $classId, int $termId, int $academicYearId): array
    {
        try {
            $students = Student::where('class_id', $classId)->get();
            $subjects = Subject::whereHas('classes', function ($query) use ($classId) {
                $query->where('class_id', $classId);
            })->get();

            $results = [];

            foreach ($students as $student) {
                $studentResults = [];
                $totalMarks = 0;
                $totalObtainedMarks = 0;
                $subjectCount = 0;

                foreach ($subjects as $subject) {
                    $result = Result::where('student_id', $student->id)
                        ->where('subject_id', $subject->id)
                        ->where('term_id', $termId)
                        ->where('academic_year_id', $academicYearId)
                        ->first();

                    if ($result) {
                        $studentResults[] = [
                            'subject_id' => $subject->id,
                            'subject_name' => $subject->name,
                            'marks_obtained' => $result->marks_obtained,
                            'total_marks' => $result->total_marks,
                            'percentage' => $result->percentage,
                            'grade' => $result->grade,
                            'position' => $result->position,
                        ];

                        $totalMarks += $result->total_marks;
                        $totalObtainedMarks += $result->marks_obtained;
                        $subjectCount++;
                    }
                }

                if ($subjectCount > 0) {
                    $overallPercentage = round(($totalObtainedMarks / $totalMarks) * 100, 2);
                    $overallGrade = $this->calculateGrade($overallPercentage);

                    $results[] = [
                        'student_id' => $student->id,
                        'student_name' => $student->first_name . ' ' . $student->last_name,
                        'admission_number' => $student->admission_number,
                        'class_name' => $student->class->name,
                        'arm_name' => $student->arm->name ?? null,
                        'subject_results' => $studentResults,
                        'overall_percentage' => $overallPercentage,
                        'overall_grade' => $overallGrade,
                        'total_marks' => $totalMarks,
                        'obtained_marks' => $totalObtainedMarks,
                        'subject_count' => $subjectCount,
                    ];
                }
            }

            // Sort by overall percentage
            usort($results, function ($a, $b) {
                return $b['overall_percentage'] <=> $a['overall_percentage'];
            });

            // Assign positions
            foreach ($results as $index => &$result) {
                $result['position'] = $index + 1;
            }

            return $results;

        } catch (\Exception $e) {
            Log::error('Class results generation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate mid-term results
     */
    public function generateMidTermResults(int $classId, int $termId, int $academicYearId): array
    {
        try {
            $term = Term::findOrFail($termId);
            $academicYear = AcademicYear::findOrFail($academicYearId);

            // Get all assessments for the term
            $assessments = $this->getTermAssessments($classId, $termId, $academicYearId);

            $students = Student::where('class_id', $classId)->get();
            $results = [];

            foreach ($students as $student) {
                $studentResults = [];
                $totalWeightedMarks = 0;
                $totalWeight = 0;

                foreach ($assessments as $assessment) {
                    $result = Result::where('student_id', $student->id)
                        ->where('subject_id', $assessment['subject_id'])
                        ->where('exam_id', $assessment['exam_id'])
                        ->first();

                    if ($result) {
                        $weightedMarks = $result->marks_obtained * $assessment['weight'];
                        $studentResults[] = [
                            'assessment_name' => $assessment['name'],
                            'subject_name' => $assessment['subject_name'],
                            'marks_obtained' => $result->marks_obtained,
                            'total_marks' => $result->total_marks,
                            'percentage' => $result->percentage,
                            'grade' => $result->grade,
                            'weight' => $assessment['weight'],
                            'weighted_marks' => $weightedMarks,
                        ];

                        $totalWeightedMarks += $weightedMarks;
                        $totalWeight += $assessment['weight'];
                    }
                }

                if ($totalWeight > 0) {
                    $overallPercentage = round(($totalWeightedMarks / $totalWeight), 2);
                    $overallGrade = $this->calculateGrade($overallPercentage);

                    $results[] = [
                        'student_id' => $student->id,
                        'student_name' => $student->first_name . ' ' . $student->last_name,
                        'admission_number' => $student->admission_number,
                        'class_name' => $student->class->name,
                        'arm_name' => $student->arm->name ?? null,
                        'assessment_results' => $studentResults,
                        'overall_percentage' => $overallPercentage,
                        'overall_grade' => $overallGrade,
                        'total_weight' => $totalWeight,
                        'weighted_marks' => $totalWeightedMarks,
                    ];
                }
            }

            // Sort by overall percentage
            usort($results, function ($a, $b) {
                return $b['overall_percentage'] <=> $a['overall_percentage'];
            });

            // Assign positions
            foreach ($results as $index => &$result) {
                $result['position'] = $index + 1;
            }

            return [
                'term' => $term->name,
                'academic_year' => $academicYear->name,
                'class_name' => ClassModel::find($classId)->name,
                'results' => $results,
                'generated_at' => now(),
            ];

        } catch (\Exception $e) {
            Log::error('Mid-term results generation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate end-of-term results
     */
    public function generateEndOfTermResults(int $classId, int $termId, int $academicYearId): array
    {
        try {
            $term = Term::findOrFail($termId);
            $academicYear = AcademicYear::findOrFail($academicYearId);

            // Get all results for the term
            $results = Result::whereHas('student', function ($query) use ($classId) {
                $query->where('class_id', $classId);
            })
            ->where('term_id', $termId)
            ->where('academic_year_id', $academicYearId)
            ->with(['student', 'subject', 'exam'])
            ->get()
            ->groupBy('student_id');

            $studentResults = [];

            foreach ($results as $studentId => $studentResultsData) {
                $student = $studentResultsData->first()->student;
                $subjectResults = [];

                foreach ($studentResultsData->groupBy('subject_id') as $subjectId => $subjectData) {
                    $subject = $subjectData->first()->subject;
                    $totalMarks = $subjectData->sum('total_marks');
                    $obtainedMarks = $subjectData->sum('marks_obtained');
                    $percentage = $totalMarks > 0 ? round(($obtainedMarks / $totalMarks) * 100, 2) : 0;
                    $grade = $this->calculateGrade($percentage);

                    $subjectResults[] = [
                        'subject_id' => $subjectId,
                        'subject_name' => $subject->name,
                        'subject_code' => $subject->code,
                        'total_marks' => $totalMarks,
                        'obtained_marks' => $obtainedMarks,
                        'percentage' => $percentage,
                        'grade' => $grade,
                        'assessments' => $subjectData->map(function ($result) {
                            return [
                                'exam_name' => $result->exam->name,
                                'marks_obtained' => $result->marks_obtained,
                                'total_marks' => $result->total_marks,
                                'percentage' => $result->percentage,
                                'grade' => $result->grade,
                            ];
                        })->toArray(),
                    ];
                }

                $overallMarks = collect($subjectResults)->sum('obtained_marks');
                $overallTotal = collect($subjectResults)->sum('total_marks');
                $overallPercentage = $overallTotal > 0 ? round(($overallMarks / $overallTotal) * 100, 2) : 0;
                $overallGrade = $this->calculateGrade($overallPercentage);

                $studentResults[] = [
                    'student_id' => $student->id,
                    'student_name' => $student->first_name . ' ' . $student->last_name,
                    'admission_number' => $student->admission_number,
                    'class_name' => $student->class->name,
                    'arm_name' => $student->arm->name ?? null,
                    'subject_results' => $subjectResults,
                    'overall_percentage' => $overallPercentage,
                    'overall_grade' => $overallGrade,
                    'total_marks' => $overallTotal,
                    'obtained_marks' => $overallMarks,
                    'subject_count' => count($subjectResults),
                ];
            }

            // Sort by overall percentage
            usort($studentResults, function ($a, $b) {
                return $b['overall_percentage'] <=> $a['overall_percentage'];
            });

            // Assign positions
            foreach ($studentResults as $index => &$result) {
                $result['position'] = $index + 1;
            }

            return [
                'term' => $term->name,
                'academic_year' => $academicYear->name,
                'class_name' => ClassModel::find($classId)->name,
                'results' => $studentResults,
                'generated_at' => now(),
            ];

        } catch (\Exception $e) {
            Log::error('End-of-term results generation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate annual results
     */
    public function generateAnnualResults(int $classId, int $academicYearId): array
    {
        try {
            $academicYear = AcademicYear::findOrFail($academicYearId);
            $terms = Term::where('academic_year_id', $academicYearId)->get();

            $students = Student::where('class_id', $classId)->get();
            $results = [];

            foreach ($students as $student) {
                $termResults = [];
                $totalMarks = 0;
                $totalObtainedMarks = 0;

                foreach ($terms as $term) {
                    $termResult = Result::where('student_id', $student->id)
                        ->where('term_id', $term->id)
                        ->where('academic_year_id', $academicYearId)
                        ->get();

                    if ($termResult->count() > 0) {
                        $termMarks = $termResult->sum('marks_obtained');
                        $termTotal = $termResult->sum('total_marks');
                        $termPercentage = $termTotal > 0 ? round(($termMarks / $termTotal) * 100, 2) : 0;
                        $termGrade = $this->calculateGrade($termPercentage);

                        $termResults[] = [
                            'term_name' => $term->name,
                            'total_marks' => $termTotal,
                            'obtained_marks' => $termMarks,
                            'percentage' => $termPercentage,
                            'grade' => $termGrade,
                        ];

                        $totalMarks += $termTotal;
                        $totalObtainedMarks += $termMarks;
                    }
                }

                if ($totalMarks > 0) {
                    $overallPercentage = round(($totalObtainedMarks / $totalMarks) * 100, 2);
                    $overallGrade = $this->calculateGrade($overallPercentage);

                    $results[] = [
                        'student_id' => $student->id,
                        'student_name' => $student->first_name . ' ' . $student->last_name,
                        'admission_number' => $student->admission_number,
                        'class_name' => $student->class->name,
                        'arm_name' => $student->arm->name ?? null,
                        'term_results' => $termResults,
                        'overall_percentage' => $overallPercentage,
                        'overall_grade' => $overallGrade,
                        'total_marks' => $totalMarks,
                        'obtained_marks' => $totalObtainedMarks,
                    ];
                }
            }

            // Sort by overall percentage
            usort($results, function ($a, $b) {
                return $b['overall_percentage'] <=> $a['overall_percentage'];
            });

            // Assign positions
            foreach ($results as $index => &$result) {
                $result['position'] = $index + 1;
            }

            return [
                'academic_year' => $academicYear->name,
                'class_name' => ClassModel::find($classId)->name,
                'results' => $results,
                'generated_at' => now(),
            ];

        } catch (\Exception $e) {
            Log::error('Annual results generation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get term assessments with weights
     */
    private function getTermAssessments(int $classId, int $termId, int $academicYearId): array
    {
        // This would typically come from a configuration or database
        // For now, we'll use a default structure
        return [
            [
                'name' => 'Continuous Assessment',
                'weight' => 0.3,
                'subject_id' => 1,
                'subject_name' => 'Mathematics',
                'exam_id' => 1,
            ],
            [
                'name' => 'Mid-term Exam',
                'weight' => 0.4,
                'subject_id' => 1,
                'subject_name' => 'Mathematics',
                'exam_id' => 2,
            ],
            [
                'name' => 'Final Exam',
                'weight' => 0.3,
                'subject_id' => 1,
                'subject_name' => 'Mathematics',
                'exam_id' => 3,
            ],
        ];
    }

    /**
     * Get default grading scale
     */
    private function getDefaultGradingScale(): array
    {
        return [
            'scales' => [
                ['min_percentage' => 90, 'max_percentage' => 100, 'grade' => 'A+'],
                ['min_percentage' => 80, 'max_percentage' => 89, 'grade' => 'A'],
                ['min_percentage' => 70, 'max_percentage' => 79, 'grade' => 'B+'],
                ['min_percentage' => 60, 'max_percentage' => 69, 'grade' => 'B'],
                ['min_percentage' => 50, 'max_percentage' => 59, 'grade' => 'C'],
                ['min_percentage' => 40, 'max_percentage' => 49, 'grade' => 'D'],
                ['min_percentage' => 0, 'max_percentage' => 39, 'grade' => 'F'],
            ]
        ];
    }

    /**
     * Calculate class statistics
     */
    public function calculateClassStatistics(array $results): array
    {
        if (empty($results)) {
            return [];
        }

        $percentages = collect($results)->pluck('overall_percentage')->toArray();
        $grades = collect($results)->pluck('overall_grade')->toArray();

        return [
            'total_students' => count($results),
            'average_percentage' => round(array_sum($percentages) / count($percentages), 2),
            'highest_percentage' => max($percentages),
            'lowest_percentage' => min($percentages),
            'grade_distribution' => array_count_values($grades),
            'pass_rate' => round((count(array_filter($percentages, function ($p) { return $p >= 50; })) / count($percentages)) * 100, 2),
        ];
    }
}
