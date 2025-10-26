<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Modules\Assessment\Models\Question;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Assessment\Models\QuestionAttempt;
use App\Modules\Assessment\Models\Result;
use App\Models\Student;
use App\Models\ClassModel;
use App\Models\Subject;
use App\Services\TenantService;
use App\Services\GradingService;

class QuestionController extends Controller
{
    protected TenantService $tenantService;
    protected GradingService $gradingService;

    public function __construct(TenantService $tenantService, GradingService $gradingService)
    {
        $this->tenantService = $tenantService;
        $this->gradingService = $gradingService;
    }

    /**
     * Get all questions for CBT exam
     */
    public function getCBTQuestions(Request $request, int $examId): JsonResponse
    {
        try {
            $exam = Exam::findOrFail($examId);

            // Check if exam is CBT
            if (!$exam->is_cbt) {
                return response()->json([
                    'success' => false,
                    'message' => 'This exam is not a CBT exam'
                ], 400);
            }

            // Get questions for the exam
            $questions = Question::where('exam_id', $examId)
                ->where('status', 'active')
                ->orderBy('id')
                ->get()
                ->map(function ($question) {
                    return $question->getForCBT();
                });

            // Generate unique session ID
            $sessionId = Str::uuid();

            // Create exam attempt record
            $attempt = ExamAttempt::create([
                'exam_id' => $examId,
                'student_id' => auth()->user()->student->id,
                'session_id' => $sessionId,
                'start_time' => now(),
                'status' => 'started',
                'is_graded' => false,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'session_id' => $sessionId,
                    'exam' => [
                        'id' => $exam->id,
                        'name' => $exam->name,
                        'description' => $exam->description,
                        'duration_minutes' => $exam->duration_minutes,
                        'total_marks' => $exam->total_marks,
                        'passing_marks' => $exam->passing_marks,
                        'cbt_settings' => $exam->cbt_settings,
                    ],
                    'questions' => $questions,
                    'attempt_id' => $attempt->id,
                    'start_time' => $attempt->start_time,
                    'time_remaining' => $exam->duration_minutes * 60, // in seconds
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('CBT questions retrieval failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve CBT questions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Submit CBT answers and get results
     */
    public function submitCBTAnswers(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'session_id' => 'required|string',
            'attempt_id' => 'required|integer|exists:exam_attempts,id',
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|integer|exists:questions,id',
            'answers.*.answer' => 'required',
            'answers.*.time_taken' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $attempt = ExamAttempt::findOrFail($request->attempt_id);

            // Verify session ID matches
            if ($attempt->session_id !== $request->session_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid session ID'
                ], 400);
            }

            $totalMarks = 0;
            $obtainedMarks = 0;
            $correctAnswers = 0;
            $totalQuestions = count($request->answers);
            $questionResults = [];

            // Process each answer
            foreach ($request->answers as $answerData) {
                $question = Question::findOrFail($answerData['question_id']);

                // Create question attempt record
                $questionAttempt = QuestionAttempt::create([
                    'exam_attempt_id' => $attempt->id,
                    'question_id' => $question->id,
                    'student_answer' => $answerData['answer'],
                    'time_taken' => $answerData['time_taken'] ?? 0,
                    'is_correct' => false, // Will be updated after grading
                    'marks_obtained' => 0,
                ]);

                // Grade the answer
                $isCorrect = $question->isCorrectAnswer($answerData['answer']);
                $marksObtained = $isCorrect ? $question->marks : 0;

                // Update question attempt
                $questionAttempt->update([
                    'is_correct' => $isCorrect,
                    'marks_obtained' => $marksObtained,
                ]);

                // Update totals
                $totalMarks += $question->marks;
                $obtainedMarks += $marksObtained;
                if ($isCorrect) {
                    $correctAnswers++;
                }

                // Prepare question result for response
                $questionResults[] = [
                    'question_id' => $question->id,
                    'question_text' => $question->question_text,
                    'question_type' => $question->question_type,
                    'student_answer' => $answerData['answer'],
                    'correct_answer' => $question->getCorrectAnswer(),
                    'is_correct' => $isCorrect,
                    'marks_obtained' => $marksObtained,
                    'total_marks' => $question->marks,
                    'explanation' => $question->explanation,
                    'time_taken' => $answerData['time_taken'] ?? 0,
                ];
            }

            // Calculate final score
            $percentage = $totalMarks > 0 ? round(($obtainedMarks / $totalMarks) * 100, 2) : 0;
            $grade = $this->gradingService->calculateGrade($percentage, $attempt->exam->grading_settings);
            $isPassing = $percentage >= $attempt->exam->passing_marks;

            // Update exam attempt
            $attempt->update([
                'end_time' => now(),
                'submitted_time' => now(),
                'score' => $obtainedMarks,
                'status' => 'submitted',
                'is_graded' => true,
            ]);

            // Create or update result
            $result = Result::updateOrCreate(
                [
                    'student_id' => $attempt->student_id,
                    'exam_id' => $attempt->exam_id,
                    'subject_id' => $attempt->exam->subject_id,
                ],
                [
                    'school_id' => $this->tenantService->getTenant()->schools()->first()->id,
                    'class_id' => $attempt->exam->class_id,
                    'term_id' => $attempt->exam->term_id,
                    'academic_year_id' => $attempt->exam->academic_year_id,
                    'marks_obtained' => $obtainedMarks,
                    'total_marks' => $totalMarks,
                    'percentage' => $percentage,
                    'grade' => $grade,
                    'position' => $this->calculatePosition($attempt->exam_id, $obtainedMarks),
                    'remarks' => $this->generateRemarks($percentage, $grade),
                    'is_published' => true,
                    'created_by' => auth()->id(),
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'CBT answers submitted successfully',
                'data' => [
                    'session_id' => $request->session_id,
                    'attempt_id' => $attempt->id,
                    'summary' => [
                        'total_questions' => $totalQuestions,
                        'correct_answers' => $correctAnswers,
                        'incorrect_answers' => $totalQuestions - $correctAnswers,
                        'total_marks' => $totalMarks,
                        'obtained_marks' => $obtainedMarks,
                        'percentage' => $percentage,
                        'grade' => $grade,
                        'is_passing' => $isPassing,
                        'position' => $result->position,
                        'remarks' => $result->remarks,
                        'time_taken' => $attempt->start_time->diffInMinutes($attempt->end_time),
                    ],
                    'question_results' => $questionResults,
                    'revision_info' => [
                        'correct_answers' => $this->getCorrectAnswersForRevision($questionResults),
                        'explanations' => $this->getExplanationsForRevision($questionResults),
                        'performance_analysis' => $this->getPerformanceAnalysis($questionResults),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('CBT answer submission failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit CBT answers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get CBT session status
     */
    public function getCBTSessionStatus(Request $request, string $sessionId): JsonResponse
    {
        try {
            $attempt = ExamAttempt::where('session_id', $sessionId)
                ->where('student_id', auth()->user()->student->id)
                ->first();

            if (!$attempt) {
                return response()->json([
                    'success' => false,
                    'message' => 'CBT session not found'
                ], 404);
            }

            $exam = $attempt->exam;
            $timeRemaining = 0;

            if ($attempt->status === 'started') {
                $elapsedTime = $attempt->start_time->diffInSeconds(now());
                $timeRemaining = max(0, ($exam->duration_minutes * 60) - $elapsedTime);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'session_id' => $sessionId,
                    'status' => $attempt->status,
                    'time_remaining' => $timeRemaining,
                    'start_time' => $attempt->start_time,
                    'end_time' => $attempt->end_time,
                    'is_graded' => $attempt->is_graded,
                    'score' => $attempt->score,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('CBT session status retrieval failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve CBT session status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get CBT results for revision
     */
    public function getCBTResults(Request $request, string $sessionId): JsonResponse
    {
        try {
            $attempt = ExamAttempt::where('session_id', $sessionId)
                ->where('student_id', auth()->user()->student->id)
                ->with(['questionAttempts.question'])
                ->first();

            if (!$attempt) {
                return response()->json([
                    'success' => false,
                    'message' => 'CBT session not found'
                ], 404);
            }

            if (!$attempt->is_graded) {
                return response()->json([
                    'success' => false,
                    'message' => 'CBT session not yet graded'
                ], 400);
            }

            $questionResults = [];
            foreach ($attempt->questionAttempts as $questionAttempt) {
                $question = $questionAttempt->question;
                $questionResults[] = [
                    'question_id' => $question->id,
                    'question_text' => $question->question_text,
                    'question_type' => $question->question_type,
                    'options' => $question->getOptions(),
                    'student_answer' => $questionAttempt->student_answer,
                    'correct_answer' => $question->getCorrectAnswer(),
                    'is_correct' => $questionAttempt->is_correct,
                    'marks_obtained' => $questionAttempt->marks_obtained,
                    'total_marks' => $question->marks,
                    'explanation' => $question->explanation,
                    'time_taken' => $questionAttempt->time_taken,
                ];
            }

            $result = Result::where('student_id', $attempt->student_id)
                ->where('exam_id', $attempt->exam_id)
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'session_id' => $sessionId,
                    'exam' => [
                        'id' => $attempt->exam->id,
                        'name' => $attempt->exam->name,
                        'total_marks' => $attempt->exam->total_marks,
                    ],
                    'summary' => [
                        'total_questions' => count($questionResults),
                        'correct_answers' => $questionResults->where('is_correct', true)->count(),
                        'total_marks' => $attempt->exam->total_marks,
                        'obtained_marks' => $attempt->score,
                        'percentage' => $result ? $result->percentage : 0,
                        'grade' => $result ? $result->grade : null,
                        'position' => $result ? $result->position : null,
                        'remarks' => $result ? $result->remarks : null,
                    ],
                    'question_results' => $questionResults,
                    'revision_info' => [
                        'correct_answers' => $this->getCorrectAnswersForRevision($questionResults),
                        'explanations' => $this->getExplanationsForRevision($questionResults),
                        'performance_analysis' => $this->getPerformanceAnalysis($questionResults),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('CBT results retrieval failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve CBT results',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create questions for CBT exam
     */
    public function createCBTQuestions(Request $request, int $examId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'questions' => 'required|array|min:1',
            'questions.*.question_text' => 'required|string',
            'questions.*.question_type' => 'required|in:multiple_choice,true_false,essay,fill_blank,matching,short_answer,numerical',
            'questions.*.marks' => 'required|numeric|min:0.1',
            'questions.*.difficulty_level' => 'required|in:easy,medium,hard',
            'questions.*.options' => 'required_if:question_type,multiple_choice|array',
            'questions.*.correct_answer' => 'required|array',
            'questions.*.explanation' => 'nullable|string',
            'questions.*.time_limit_seconds' => 'nullable|integer|min:30',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $exam = Exam::findOrFail($examId);
            $createdQuestions = [];

            foreach ($request->questions as $questionData) {
                $question = Question::create([
                    'exam_id' => $examId,
                    'subject_id' => $exam->subject_id,
                    'question_text' => $questionData['question_text'],
                    'question_type' => $questionData['question_type'],
                    'marks' => $questionData['marks'],
                    'difficulty_level' => $questionData['difficulty_level'],
                    'options' => $questionData['options'] ?? null,
                    'correct_answer' => $questionData['correct_answer'],
                    'explanation' => $questionData['explanation'] ?? null,
                    'time_limit_seconds' => $questionData['time_limit_seconds'] ?? 60,
                    'status' => 'active',
                ]);

                $createdQuestions[] = [
                    'id' => $question->id,
                    'question_text' => $question->question_text,
                    'question_type' => $question->question_type,
                    'marks' => $question->marks,
                    'difficulty_level' => $question->difficulty_level,
                ];
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'CBT questions created successfully',
                'data' => [
                    'exam_id' => $examId,
                    'questions_created' => count($createdQuestions),
                    'questions' => $createdQuestions,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('CBT question creation failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to create CBT questions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate student position in exam
     */
    private function calculatePosition(int $examId, float $score): int
    {
        $results = Result::where('exam_id', $examId)
            ->orderBy('marks_obtained', 'desc')
            ->pluck('marks_obtained')
            ->toArray();

        $position = 1;
        foreach ($results as $resultScore) {
            if ($resultScore > $score) {
                $position++;
            } else {
                break;
            }
        }

        return $position;
    }

    /**
     * Generate remarks based on performance
     */
    private function generateRemarks(float $percentage, string $grade): string
    {
        if ($percentage >= 90) {
            return 'Outstanding performance! Keep up the excellent work.';
        } elseif ($percentage >= 80) {
            return 'Excellent performance! You have a strong understanding of the subject.';
        } elseif ($percentage >= 70) {
            return 'Very good performance! Continue to work hard.';
        } elseif ($percentage >= 60) {
            return 'Good performance! There is room for improvement.';
        } elseif ($percentage >= 50) {
            return 'Satisfactory performance. Focus on areas that need improvement.';
        } else {
            return 'Performance needs improvement. Please review the material and seek help if needed.';
        }
    }

    /**
     * Get correct answers for revision
     */
    private function getCorrectAnswersForRevision(array $questionResults): array
    {
        return collect($questionResults)->map(function ($result) {
            return [
                'question_id' => $result['question_id'],
                'question_text' => $result['question_text'],
                'correct_answer' => $result['correct_answer'],
                'explanation' => $result['explanation'],
            ];
        })->toArray();
    }

    /**
     * Get explanations for revision
     */
    private function getExplanationsForRevision(array $questionResults): array
    {
        return collect($questionResults)
            ->filter(function ($result) {
                return !empty($result['explanation']);
            })
            ->map(function ($result) {
                return [
                    'question_id' => $result['question_id'],
                    'question_text' => $result['question_text'],
                    'explanation' => $result['explanation'],
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Get performance analysis
     */
    private function getPerformanceAnalysis(array $questionResults): array
    {
        $totalQuestions = count($questionResults);
        $correctAnswers = collect($questionResults)->where('is_correct', true)->count();
        $incorrectAnswers = $totalQuestions - $correctAnswers;

        $difficultyAnalysis = collect($questionResults)->groupBy('question_type')->map(function ($questions, $type) {
            $correct = $questions->where('is_correct', true)->count();
            $total = $questions->count();
            return [
                'type' => $type,
                'total' => $total,
                'correct' => $correct,
                'accuracy' => $total > 0 ? round(($correct / $total) * 100, 2) : 0,
            ];
        })->values()->toArray();

        return [
            'overall_performance' => [
                'total_questions' => $totalQuestions,
                'correct_answers' => $correctAnswers,
                'incorrect_answers' => $incorrectAnswers,
                'accuracy_percentage' => $totalQuestions > 0 ? round(($correctAnswers / $totalQuestions) * 100, 2) : 0,
            ],
            'difficulty_analysis' => $difficultyAnalysis,
            'recommendations' => $this->generateRecommendations($questionResults),
        ];
    }

    /**
     * Generate study recommendations
     */
    private function generateRecommendations(array $questionResults): array
    {
        $recommendations = [];
        $incorrectQuestions = collect($questionResults)->where('is_correct', false);

        if ($incorrectQuestions->count() > 0) {
            $recommendations[] = 'Review the topics covered in the questions you answered incorrectly.';
        }

        $lowScoringTypes = collect($questionResults)
            ->groupBy('question_type')
            ->map(function ($questions, $type) {
                $correct = $questions->where('is_correct', true)->count();
                $total = $questions->count();
                return [
                    'type' => $type,
                    'accuracy' => $total > 0 ? round(($correct / $total) * 100, 2) : 0,
                ];
            })
            ->where('accuracy', '<', 70)
            ->keys()
            ->toArray();

        if (!empty($lowScoringTypes)) {
            $recommendations[] = 'Focus on improving your performance in: ' . implode(', ', $lowScoringTypes);
        }

        return $recommendations;
    }
}
