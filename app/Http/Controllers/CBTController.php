<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\Answer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class CBTController extends Controller
{
    /**
     * Start CBT exam
     */
    public function start(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'exam_id' => 'required|exists:exams,id',
            'student_id' => 'required|exists:students,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $exam = Exam::findOrFail($request->exam_id);

            // Check if exam is CBT
            if (!$exam->isCBT()) {
                return response()->json([
                    'error' => 'This exam is not a CBT exam'
                ], 400);
            }

            // Check if exam is active
            if (!$exam->isActive()) {
                return response()->json([
                    'error' => 'This exam is not currently active'
                ], 400);
            }

            // Check if student is enrolled in this exam
            if (!$exam->students()->where('student_id', $request->student_id)->exists()) {
                return response()->json([
                    'error' => 'Student is not enrolled in this exam'
                ], 403);
            }

            // Check if student already has an attempt
            $existingAttempt = ExamAttempt::where('exam_id', $exam->id)
                                        ->where('student_id', $request->student_id)
                                        ->where('status', 'in_progress')
                                        ->first();

            if ($existingAttempt) {
                return response()->json([
                    'message' => 'Exam already started',
                    'attempt' => $existingAttempt,
                    'questions' => $this->getExamQuestions($exam)
                ]);
            }

            // Create new attempt
            $attempt = ExamAttempt::create([
                'exam_id' => $exam->id,
                'student_id' => $request->student_id,
                'started_at' => now(),
                'status' => 'in_progress',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'message' => 'CBT exam started successfully',
                'attempt' => $attempt,
                'exam' => $exam,
                'questions' => $this->getExamQuestions($exam),
                'time_remaining' => $exam->duration_minutes
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to start CBT exam',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Submit answer for a question
     */
    public function submitAnswer(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'attempt_id' => 'required|exists:exam_attempts,id',
            'question_id' => 'required|exists:questions,id',
            'answer_data' => 'required|array',
            'time_taken' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $attempt = ExamAttempt::findOrFail($request->attempt_id);
            $question = Question::findOrFail($request->question_id);

            // Check if attempt is still in progress
            if (!$attempt->isInProgress()) {
                return response()->json([
                    'error' => 'Exam attempt is not in progress'
                ], 400);
            }

            // Check if time has expired
            if ($attempt->hasTimeExpired()) {
                $attempt->update([
                    'status' => 'time_expired',
                    'completed_at' => now(),
                ]);

                return response()->json([
                    'error' => 'Time has expired'
                ], 400);
            }

            // Create or update answer
            $answer = Answer::updateOrCreate(
                [
                    'exam_attempt_id' => $attempt->id,
                    'question_id' => $question->id,
                    'student_id' => $attempt->student_id,
                ],
                [
                    'answer_text' => $request->answer_text ?? null,
                    'answer_data' => $request->answer_data,
                    'time_taken_seconds' => $request->time_taken ?? 0,
                ]
            );

            // Auto-grade the answer
            $answer->autoGrade();

            return response()->json([
                'message' => 'Answer submitted successfully',
                'answer' => $answer,
                'is_correct' => $answer->is_correct,
                'time_remaining' => $attempt->getTimeRemaining()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to submit answer',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Submit complete exam
     */
    public function submit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'attempt_id' => 'required|exists:exam_attempts,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $attempt = ExamAttempt::findOrFail($request->attempt_id);

            // Check if attempt is in progress
            if (!$attempt->isInProgress()) {
                return response()->json([
                    'error' => 'Exam attempt is not in progress'
                ], 400);
            }

            DB::beginTransaction();

            // Calculate total score
            $totalScore = $attempt->answers()->sum('marks_obtained');
            $totalMarks = $attempt->exam->total_marks;
            $percentage = $totalMarks > 0 ? round(($totalScore / $totalMarks) * 100, 2) : 0;

            // Update attempt
            $attempt->update([
                'status' => 'completed',
                'completed_at' => now(),
                'total_score' => $totalScore,
                'percentage' => $percentage,
                'time_taken_minutes' => $attempt->getDurationInMinutes(),
            ]);

            // Create result record
            $result = $attempt->exam->results()->create([
                'student_id' => $attempt->student_id,
                'total_score' => $totalScore,
                'percentage' => $percentage,
                'grade' => $this->calculateGrade($percentage),
                'status' => $percentage >= $attempt->exam->passing_marks ? 'passed' : 'failed',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Exam submitted successfully',
                'attempt' => $attempt,
                'result' => $result,
                'score_breakdown' => $attempt->getScoreBreakdown()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to submit exam',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get exam questions
     */
    public function getQuestions(Request $request, Exam $exam): JsonResponse
    {
        if (!$exam->isCBT()) {
            return response()->json([
                'error' => 'This exam is not a CBT exam'
            ], 400);
        }

        $questions = $this->getExamQuestions($exam);

        return response()->json([
            'questions' => $questions
        ]);
    }

    /**
     * Get exam attempt status
     */
    public function getAttemptStatus(Request $request, ExamAttempt $attempt): JsonResponse
    {
        return response()->json([
            'attempt' => $attempt,
            'time_remaining' => $attempt->getTimeRemaining(),
            'score_breakdown' => $attempt->getScoreBreakdown(),
            'answers' => $attempt->answers()->with('question')->get()
        ]);
    }

    /**
     * Get exam questions for CBT
     */
    protected function getExamQuestions(Exam $exam)
    {
        return $exam->questions()
                   ->where('status', 'active')
                   ->select(['id', 'question_text', 'question_type', 'marks', 'time_limit_seconds', 'options', 'difficulty_level'])
                   ->orderBy('id')
                   ->get();
    }

    /**
     * Calculate grade based on percentage
     */
    protected function calculateGrade(float $percentage): string
    {
        if ($percentage >= 90) return 'A+';
        if ($percentage >= 80) return 'A';
        if ($percentage >= 70) return 'B+';
        if ($percentage >= 60) return 'B';
        if ($percentage >= 50) return 'C';
        if ($percentage >= 40) return 'D';
        return 'F';
    }
}
