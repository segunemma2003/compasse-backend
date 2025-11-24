<?php

namespace App\Http\Controllers;

use App\Models\QuestionBank;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class QuestionBankController extends Controller
{
    /**
     * Display a listing of questions
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = QuestionBank::with(['subject', 'class', 'term', 'academicYear', 'creator']);

            // Filters
            if ($request->has('subject_id')) {
                $query->where('subject_id', $request->subject_id);
            }

            if ($request->has('class_id')) {
                $query->where('class_id', $request->class_id);
            }

            if ($request->has('term_id')) {
                $query->where('term_id', $request->term_id);
            }

            if ($request->has('academic_year_id')) {
                $query->where('academic_year_id', $request->academic_year_id);
            }

            if ($request->has('question_type')) {
                $query->where('question_type', $request->question_type);
            }

            if ($request->has('difficulty')) {
                $query->where('difficulty', $request->difficulty);
            }

            if ($request->has('topic')) {
                $query->where('topic', 'like', '%' . $request->topic . '%');
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            } else {
                $query->where('status', 'active');
            }

            // Search in question text
            if ($request->has('search')) {
                $query->where('question', 'like', '%' . $request->search . '%');
            }

            // Tags filter
            if ($request->has('tags')) {
                $tags = is_array($request->tags) ? $request->tags : [$request->tags];
                $query->where(function ($q) use ($tags) {
                    foreach ($tags as $tag) {
                        $q->orWhereJsonContains('tags', $tag);
                    }
                });
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 50);
            $questions = $query->paginate($perPage);

            return response()->json($questions);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch questions',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created question
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subject_id' => 'required|exists:subjects,id',
            'class_id' => 'required|exists:classes,id',
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'question_type' => 'required|in:multiple_choice,true_false,short_answer,essay,fill_in_blank,matching,ordering',
            'question' => 'required|string',
            'options' => 'nullable|array',
            'correct_answer' => 'required',
            'explanation' => 'nullable|string',
            'difficulty' => 'nullable|in:easy,medium,hard',
            'marks' => 'nullable|integer|min:1',
            'tags' => 'nullable|array',
            'topic' => 'nullable|string|max:255',
            'hints' => 'nullable|string',
            'attachments' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $schoolId = $this->getSchoolIdFromTenant($request);
            if (!$schoolId) {
                return response()->json([
                    'error' => 'School not found',
                    'message' => 'Unable to determine school from tenant context'
                ], 400);
            }

            $question = QuestionBank::create(array_merge($request->all(), [
                'school_id' => $schoolId,
                'created_by' => auth()->id(),
                'status' => 'active',
                'usage_count' => 0,
                'difficulty' => $request->difficulty ?? 'medium',
                'marks' => $request->marks ?? 1,
            ]));

            return response()->json([
                'message' => 'Question created successfully',
                'question' => $question->load(['subject', 'class', 'term', 'academicYear'])
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Question creation failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified question
     */
    public function show(QuestionBank $questionBank): JsonResponse
    {
        try {
            $questionBank->load(['subject', 'class', 'term', 'academicYear', 'creator']);
            return response()->json($questionBank);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Question not found',
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified question
     */
    public function update(Request $request, QuestionBank $questionBank): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subject_id' => 'sometimes|exists:subjects,id',
            'class_id' => 'sometimes|exists:classes,id',
            'term_id' => 'sometimes|exists:terms,id',
            'academic_year_id' => 'sometimes|exists:academic_years,id',
            'question_type' => 'sometimes|in:multiple_choice,true_false,short_answer,essay,fill_in_blank,matching,ordering',
            'question' => 'sometimes|string',
            'options' => 'nullable|array',
            'correct_answer' => 'sometimes',
            'explanation' => 'nullable|string',
            'difficulty' => 'nullable|in:easy,medium,hard',
            'marks' => 'nullable|integer|min:1',
            'tags' => 'nullable|array',
            'topic' => 'nullable|string|max:255',
            'hints' => 'nullable|string',
            'attachments' => 'nullable|array',
            'status' => 'sometimes|in:active,inactive,archived',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $questionBank->update($request->all());

            return response()->json([
                'message' => 'Question updated successfully',
                'question' => $questionBank->load(['subject', 'class', 'term', 'academicYear'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Question update failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified question
     */
    public function destroy(QuestionBank $questionBank): JsonResponse
    {
        try {
            $questionBank->delete();
            return response()->json([
                'message' => 'Question deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Question deletion failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get questions for exam creation
     */
    public function getQuestionsForExam(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subject_id' => 'required|exists:subjects,id',
            'class_id' => 'required|exists:classes,id',
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'question_types' => 'nullable|array',
            'difficulty' => 'nullable|string',
            'topics' => 'nullable|array',
            'count' => 'nullable|integer|min:1|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $query = QuestionBank::where('subject_id', $request->subject_id)
                ->where('class_id', $request->class_id)
                ->where('term_id', $request->term_id)
                ->where('academic_year_id', $request->academic_year_id)
                ->where('status', 'active');

            if ($request->has('question_types')) {
                $query->whereIn('question_type', $request->question_types);
            }

            if ($request->has('difficulty')) {
                $query->where('difficulty', $request->difficulty);
            }

            if ($request->has('topics')) {
                $query->whereIn('topic', $request->topics);
            }

            $count = $request->get('count', 50);
            $questions = $query->inRandomOrder()->take($count)->get();

            return response()->json([
                'total_available' => $query->count(),
                'returned' => $questions->count(),
                'questions' => $questions
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch questions',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Duplicate a question
     */
    public function duplicate(QuestionBank $questionBank): JsonResponse
    {
        try {
            $newQuestion = $questionBank->replicate();
            $newQuestion->created_by = auth()->id();
            $newQuestion->usage_count = 0;
            $newQuestion->last_used_at = null;
            $newQuestion->save();

            return response()->json([
                'message' => 'Question duplicated successfully',
                'question' => $newQuestion->load(['subject', 'class', 'term', 'academicYear'])
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Question duplication failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get question statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $schoolId = $this->getSchoolIdFromTenant($request);
            
            $stats = [
                'total_questions' => QuestionBank::where('school_id', $schoolId)->count(),
                'active_questions' => QuestionBank::where('school_id', $schoolId)->where('status', 'active')->count(),
                'by_type' => QuestionBank::where('school_id', $schoolId)
                    ->select('question_type', DB::raw('count(*) as count'))
                    ->groupBy('question_type')
                    ->get(),
                'by_difficulty' => QuestionBank::where('school_id', $schoolId)
                    ->select('difficulty', DB::raw('count(*) as count'))
                    ->groupBy('difficulty')
                    ->get(),
                'by_subject' => QuestionBank::where('school_id', $schoolId)
                    ->with('subject:id,name')
                    ->select('subject_id', DB::raw('count(*) as count'))
                    ->groupBy('subject_id')
                    ->get(),
                'most_used' => QuestionBank::where('school_id', $schoolId)
                    ->orderBy('usage_count', 'desc')
                    ->take(10)
                    ->get(['id', 'question', 'usage_count', 'last_used_at']),
            ];

            return response()->json($stats);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch statistics',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
