<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class QuizController extends Controller
{
    /**
     * List quizzes
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = DB::table('quizzes');

            if ($request->has('class_id')) {
                $query->where('class_id', $request->class_id);
            }

            if ($request->has('subject_id')) {
                $query->where('subject_id', $request->subject_id);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $quizzes = $query->paginate($request->get('per_page', 15));

            return response()->json($quizzes);
        } catch (\Exception $e) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'per_page' => 15,
                'total' => 0
            ]);
        }
    }

    /**
     * Get quiz details
     */
    public function show($id): JsonResponse
    {
        $quiz = DB::table('quizzes')->find($id);

        if (!$quiz) {
            return response()->json(['error' => 'Quiz not found'], 404);
        }

        $questions = DB::table('quiz_questions')
            ->where('quiz_id', $id)
            ->get();

        return response()->json([
            'quiz' => $quiz,
            'questions' => $questions
        ]);
    }

    /**
     * Create quiz
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Check if quizzes table exists
            $tableExists = false;
            try {
                $tableExists = \Illuminate\Support\Facades\Schema::hasTable('quizzes');
            } catch (\Exception $e) {
                $tableExists = false;
            }

            if (!$tableExists) {
                return response()->json([
                    'error' => 'Quizzes table not found',
                    'message' => 'The quizzes table does not exist. Please run tenant migrations.',
                    'quiz' => null
                ], 500);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'class_id' => 'nullable|integer',
                'subject_id' => 'nullable|integer',
                'duration_minutes' => 'required|integer|min:1',
                'total_marks' => 'required|numeric',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
                'status' => 'nullable|in:draft,active,completed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed',
                    'messages' => $validator->errors()
                ], 422);
            }

            // Get school from request
            $school = $this->getSchoolFromRequest($request);
            $schoolId = $school ? $school->id : null;

            $quizId = DB::table('quizzes')->insertGetId([
                'school_id' => $schoolId,
                'name' => $request->name,
                'description' => $request->description,
                'class_id' => $request->class_id,
                'subject_id' => $request->subject_id,
                'duration_minutes' => $request->duration_minutes,
                'total_marks' => $request->total_marks,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'status' => $request->status ?? 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $quiz = DB::table('quizzes')->find($quizId);

            return response()->json([
                'message' => 'Quiz created successfully',
                'quiz' => $quiz
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create quiz',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update quiz
     */
    public function update(Request $request, $id): JsonResponse
    {
        $quiz = DB::table('quizzes')->find($id);

        if (!$quiz) {
            return response()->json(['error' => 'Quiz not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'duration_minutes' => 'sometimes|integer|min:1',
            'total_marks' => 'sometimes|numeric',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'status' => 'nullable|in:draft,active,completed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        DB::table('quizzes')
            ->where('id', $id)
            ->update(array_merge(
                $request->only([
                    'name', 'description', 'duration_minutes', 'total_marks',
                    'start_date', 'end_date', 'status'
                ]),
                ['updated_at' => now()]
            ));

        $quiz = DB::table('quizzes')->find($id);

        return response()->json([
            'message' => 'Quiz updated successfully',
            'quiz' => $quiz
        ]);
    }

    /**
     * Delete quiz
     */
    public function destroy($id): JsonResponse
    {
        $quiz = DB::table('quizzes')->find($id);

        if (!$quiz) {
            return response()->json(['error' => 'Quiz not found'], 404);
        }

        DB::table('quizzes')->where('id', $id)->delete();

        return response()->json([
            'message' => 'Quiz deleted successfully'
        ]);
    }

    /**
     * Get quiz questions
     */
    public function getQuestions($id): JsonResponse
    {
        $quiz = DB::table('quizzes')->find($id);

        if (!$quiz) {
            return response()->json(['error' => 'Quiz not found'], 404);
        }

        $questions = DB::table('quiz_questions')
            ->where('quiz_id', $id)
            ->get();

        return response()->json([
            'quiz' => $quiz,
            'questions' => $questions
        ]);
    }

    /**
     * Add question to quiz
     */
    public function addQuestion(Request $request, $id): JsonResponse
    {
        $quiz = DB::table('quizzes')->find($id);

        if (!$quiz) {
            return response()->json(['error' => 'Quiz not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'question' => 'required|string',
            'type' => 'required|in:multiple_choice,true_false,short_answer,essay',
            'options' => 'required_if:type,multiple_choice|array',
            'correct_answer' => 'required|string',
            'marks' => 'required|numeric|min:0',
            'order' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $questionId = DB::table('quiz_questions')->insertGetId([
            'quiz_id' => $id,
            'question' => $request->question,
            'type' => $request->type,
            'options' => json_encode($request->options ?? []),
            'correct_answer' => $request->correct_answer,
            'marks' => $request->marks,
            'order' => $request->order ?? 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $question = DB::table('quiz_questions')->find($questionId);

        return response()->json([
            'message' => 'Question added successfully',
            'question' => $question
        ], 201);
    }

    /**
     * Get quiz attempts
     */
    public function getAttempts($id): JsonResponse
    {
        $quiz = DB::table('quizzes')->find($id);

        if (!$quiz) {
            return response()->json(['error' => 'Quiz not found'], 404);
        }

        $attempts = DB::table('quiz_attempts')
            ->where('quiz_id', $id)
            ->get();

        return response()->json([
            'quiz' => $quiz,
            'attempts' => $attempts
        ]);
    }

    /**
     * Start quiz attempt
     */
    public function startAttempt(Request $request, $id): JsonResponse
    {
        $quiz = DB::table('quizzes')->find($id);

        if (!$quiz) {
            return response()->json(['error' => 'Quiz not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'student_id' => 'required|exists:students,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $attemptId = DB::table('quiz_attempts')->insertGetId([
            'quiz_id' => $id,
            'student_id' => $request->student_id,
            'started_at' => now(),
            'status' => 'in_progress',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $attempt = DB::table('quiz_attempts')->find($attemptId);

        return response()->json([
            'message' => 'Quiz attempt started',
            'attempt' => $attempt
        ], 201);
    }

    /**
     * Submit quiz answers
     */
    public function submit(Request $request, $id): JsonResponse
    {
        $quiz = DB::table('quizzes')->find($id);

        if (!$quiz) {
            return response()->json(['error' => 'Quiz not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'attempt_id' => 'required|exists:quiz_attempts,id',
            'answers' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        // Calculate score
        $score = 0;
        $totalMarks = 0;

        foreach ($request->answers as $answer) {
            $question = DB::table('quiz_questions')
                ->where('id', $answer['question_id'])
                ->where('quiz_id', $id)
                ->first();

            if ($question) {
                $totalMarks += $question->marks;
                if ($answer['answer'] === $question->correct_answer) {
                    $score += $question->marks;
                }

                // Save answer
                DB::table('quiz_answers')->insert([
                    'attempt_id' => $request->attempt_id,
                    'question_id' => $answer['question_id'],
                    'answer' => $answer['answer'],
                    'is_correct' => $answer['answer'] === $question->correct_answer,
                    'marks_obtained' => $answer['answer'] === $question->correct_answer ? $question->marks : 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Update attempt
        DB::table('quiz_attempts')
            ->where('id', $request->attempt_id)
            ->update([
                'score' => $score,
                'total_marks' => $totalMarks,
                'completed_at' => now(),
                'status' => 'completed',
                'updated_at' => now(),
            ]);

        $attempt = DB::table('quiz_attempts')->find($request->attempt_id);

        return response()->json([
            'message' => 'Quiz submitted successfully',
            'attempt' => $attempt,
            'score' => $score,
            'total_marks' => $totalMarks,
            'percentage' => $totalMarks > 0 ? round(($score / $totalMarks) * 100, 2) : 0
        ]);
    }

    /**
     * Get quiz results
     */
    public function getResults($id): JsonResponse
    {
        $quiz = DB::table('quizzes')->find($id);

        if (!$quiz) {
            return response()->json(['error' => 'Quiz not found'], 404);
        }

        $attempts = DB::table('quiz_attempts')
            ->where('quiz_id', $id)
            ->where('status', 'completed')
            ->orderBy('score', 'desc')
            ->get();

        return response()->json([
            'quiz' => $quiz,
            'results' => $attempts
        ]);
    }
}
