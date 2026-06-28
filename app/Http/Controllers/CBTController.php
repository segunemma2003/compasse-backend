<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\Answer;
use App\Models\Student;
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

            // Check if student is in the class this exam was set for
            // (the exam_students pivot table referenced by Exam::students() doesn't exist in the schema,
            // so class_id is the actual source of truth used everywhere else in this app)
            $student = Student::find($request->student_id);
            if (!$student || $student->class_id !== $exam->class_id) {
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
            'attempt_id'  => 'required|exists:exam_attempts,id',
            'question_id' => 'required|exists:questions,id',
            // answer_text  → for essay / open-ended questions (plain string)
            // answer_data  → for MCQ / T-F / fill_blank (array of selected option texts)
            // At least one must be present
            'answer_text' => 'nullable|string',
            'answer_data' => 'nullable|array',
            'time_taken'  => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        if (empty($request->answer_text) && empty($request->answer_data)) {
            return response()->json(['error' => 'Provide answer_text (essay) or answer_data (MCQ/T-F)'], 422);
        }

        try {
            $attempt  = ExamAttempt::findOrFail($request->attempt_id);
            $question = Question::findOrFail($request->question_id);

            if (!$attempt->isInProgress()) {
                return response()->json(['error' => 'Exam attempt is not in progress'], 400);
            }

            if ($attempt->hasTimeExpired()) {
                $attempt->update(['status' => 'time_expired', 'completed_at' => now()]);
                return response()->json(['error' => 'Time has expired'], 400);
            }

            $isEssay      = in_array($question->question_type, ['essay', 'fill_blank']);
            $answerText   = $request->answer_text ?? null;
            // For essay, store the text as a single-element array in answer_data too
            // so the data shape stays consistent with MCQ answers
            $answerData   = $request->answer_data ?? ($answerText ? [$answerText] : []);

            $answer = Answer::updateOrCreate(
                [
                    'exam_attempt_id' => $attempt->id,
                    'question_id'     => $question->id,
                    'student_id'      => $attempt->student_id,
                ],
                [
                    'answer_text'        => $answerText,
                    'answer_data'        => $answerData,
                    'time_taken_seconds' => $request->time_taken ?? 0,
                    // Essay questions: marks_obtained stays 0 until teacher grades
                    'is_correct'         => $isEssay ? null : null,
                    'marks_obtained'     => $isEssay ? null : null,
                ]
            );

            // Auto-grade MCQ and T-F; essay is left for teacher review
            if (!$isEssay) {
                $answer->autoGrade();
            }

            return response()->json([
                'message'        => 'Answer submitted',
                'question_type'  => $question->question_type,
                'needs_grading'  => $isEssay,
                'is_correct'     => $isEssay ? null : $answer->is_correct,
                'time_remaining' => $attempt->getTimeRemaining(),
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to submit answer', 'message' => $e->getMessage()], 500);
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

            // Create or update result record (field names must match the results table: score/total_marks, not total_score/percentage)
            $result = $attempt->exam->results()->updateOrCreate(
                [
                    'student_id' => $attempt->student_id,
                    'subject_id' => $attempt->exam->subject_id,
                ],
                [
                    'score' => $totalScore,
                    'total_marks' => $totalMarks,
                    'grade' => $this->calculateGrade($percentage),
                ]
            );

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
     * List CBT exams available to the authenticated student (their own class only)
     */
    public function availableExams(Request $request): JsonResponse
    {
        $studentId = $this->ownStudentId($request->user());
        $student = $studentId ? Student::find($studentId) : null;

        if (!$student) {
            return response()->json(['error' => 'Only students can view available exams'], 403);
        }

        $exams = Exam::where('class_id', $student->class_id)
            ->where('is_cbt', true)
            ->with('subject')
            ->withCount('questions')
            ->orderBy('start_date', 'desc')
            ->get()
            ->filter(fn (Exam $exam) => $exam->isActive());

        $attemptsByExam = ExamAttempt::where('student_id', $student->id)
            ->whereIn('exam_id', $exams->pluck('id'))
            ->orderByDesc('started_at')
            ->get()
            ->groupBy('exam_id');

        $data = $exams->map(function (Exam $exam) use ($attemptsByExam) {
            $latestAttempt = $attemptsByExam->get($exam->id, collect())->first();

            return [
                'id' => $exam->id,
                'name' => $exam->name,
                'description' => $exam->description,
                'subject_name' => $exam->subject?->name,
                'duration_minutes' => $exam->duration_minutes,
                'total_marks' => $exam->total_marks,
                'passing_marks' => $exam->passing_marks,
                'questions_count' => $exam->questions_count,
                'start_date' => $exam->start_date,
                'end_date' => $exam->end_date,
                'attempt_id' => $latestAttempt?->id,
                'attempt_status' => $latestAttempt?->status,
            ];
        })->values();

        return response()->json(['exams' => $data]);
    }

    /**
     * Get exam questions (only when exam is published/active)
     */
    public function getQuestions(Request $request, Exam $exam): JsonResponse
    {
        if (!$exam->isCBT()) {
            return response()->json(['error' => 'This exam is not a CBT exam'], 400);
        }

        if ($exam->status !== 'active') {
            return response()->json(['error' => 'This exam has not been published yet'], 403);
        }

        $questions = $this->getExamQuestions($exam);

        return response()->json(['questions' => $questions]);
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
