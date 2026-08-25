<?php

namespace App\Http\Controllers;

use App\Jobs\SendEmailJob;
use App\Models\AdmissionCycle;
use App\Models\AdmissionExam;
use App\Models\AdmissionExamQuestion;
use App\Models\Applicant;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * School-side admissions management: open/close admission cycles, build an
 * optional entrance exam, and review/decide on applicants. The public-facing
 * counterpart (registration form + exam-taking) is PublicAdmissionController.
 */
class AdmissionController extends Controller
{
    // ── Admission cycles ────────────────────────────────────────────────

    public function indexCycles(Request $request): JsonResponse
    {
        $school = School::first();
        $cycles = AdmissionCycle::with(['class', 'exam'])
            ->where('school_id', $school?->id ?? 1)
            ->withCount('applicants')
            ->orderByDesc('id')
            ->get();

        return response()->json(['admission_cycles' => $cycles]);
    }

    public function storeCycle(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:150',
            'class_id' => 'required|exists:classes,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'description' => 'nullable|string',
            'requires_entrance_exam' => 'nullable|boolean',
            'opens_at' => 'nullable|date',
            'closes_at' => 'nullable|date|after_or_equal:opens_at',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $school = School::first();

        $cycle = AdmissionCycle::create([
            'school_id' => $school?->id ?? 1,
            'name' => $request->name,
            'class_id' => $request->class_id,
            'academic_year_id' => $request->academic_year_id ?? $school?->getCurrentAcademicYear()?->id,
            'description' => $request->description,
            'requires_entrance_exam' => $request->boolean('requires_entrance_exam'),
            'opens_at' => $request->opens_at,
            'closes_at' => $request->closes_at,
            'status' => 'draft',
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['message' => 'Admission cycle created', 'admission_cycle' => $cycle], 201);
    }

    public function showCycle($id): JsonResponse
    {
        $cycle = AdmissionCycle::with(['class', 'exam.questions'])->withCount('applicants')->find($id);
        if (! $cycle) {
            return response()->json(['error' => 'Admission cycle not found'], 404);
        }

        return response()->json(['admission_cycle' => $cycle]);
    }

    /**
     * Update cycle details, or flip status open/closed — opening it is what
     * makes the public registration form live on the school's website.
     */
    public function updateCycle(Request $request, $id): JsonResponse
    {
        $cycle = AdmissionCycle::find($id);
        if (! $cycle) {
            return response()->json(['error' => 'Admission cycle not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:150',
            'description' => 'nullable|string',
            'requires_entrance_exam' => 'sometimes|boolean',
            'opens_at' => 'nullable|date',
            'closes_at' => 'nullable|date',
            'status' => 'sometimes|in:draft,open,closed',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $cycle->update($request->only([
            'name', 'description', 'requires_entrance_exam', 'opens_at', 'closes_at', 'status',
        ]));

        return response()->json(['message' => 'Admission cycle updated', 'admission_cycle' => $cycle->fresh()]);
    }

    public function destroyCycle($id): JsonResponse
    {
        $cycle = AdmissionCycle::withCount('applicants')->find($id);
        if (! $cycle) {
            return response()->json(['error' => 'Admission cycle not found'], 404);
        }
        if ($cycle->applicants_count > 0) {
            return response()->json(['error' => 'Cannot delete a cycle with applicants. Close it instead.'], 422);
        }

        $cycle->delete();

        return response()->json(['message' => 'Admission cycle deleted']);
    }

    // ── Entrance exam ───────────────────────────────────────────────────

    public function storeExam(Request $request, $cycleId): JsonResponse
    {
        $cycle = AdmissionCycle::find($cycleId);
        if (! $cycle) {
            return response()->json(['error' => 'Admission cycle not found'], 404);
        }
        if ($cycle->exam) {
            return response()->json(['error' => 'This cycle already has an entrance exam'], 422);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:150',
            'instructions' => 'nullable|string',
            'duration_minutes' => 'nullable|integer|min:1',
            'scheduled_start' => 'nullable|date',
            'scheduled_end' => 'nullable|date|after:scheduled_start',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $exam = AdmissionExam::create([
            'admission_cycle_id' => $cycle->id,
            'title' => $request->title,
            'instructions' => $request->instructions,
            'duration_minutes' => $request->duration_minutes ?? 60,
            'scheduled_start' => $request->scheduled_start,
            'scheduled_end' => $request->scheduled_end,
            'status' => 'draft',
        ]);

        $cycle->update(['requires_entrance_exam' => true]);

        return response()->json(['message' => 'Entrance exam created', 'admission_exam' => $exam], 201);
    }

    public function updateExam(Request $request, $examId): JsonResponse
    {
        $exam = AdmissionExam::find($examId);
        if (! $exam) {
            return response()->json(['error' => 'Entrance exam not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:150',
            'instructions' => 'nullable|string',
            'duration_minutes' => 'sometimes|integer|min:1',
            'scheduled_start' => 'nullable|date',
            'scheduled_end' => 'nullable|date',
            // 'active' is the explicit admin trigger — deliberately not implied
            // by reaching scheduled_start automatically.
            'status' => 'sometimes|in:draft,scheduled,active,closed',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $exam->update($request->only([
            'title', 'instructions', 'duration_minutes', 'scheduled_start', 'scheduled_end', 'status',
        ]));

        return response()->json(['message' => 'Entrance exam updated', 'admission_exam' => $exam->fresh()]);
    }

    public function storeQuestion(Request $request, $examId): JsonResponse
    {
        $exam = AdmissionExam::find($examId);
        if (! $exam) {
            return response()->json(['error' => 'Entrance exam not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'question_text' => 'required|string',
            'type' => 'required|in:mcq,short_answer',
            'options' => 'required_if:type,mcq|array|min:2',
            'options.*.key' => 'required_with:options|string|max:5',
            'options.*.text' => 'required_with:options|string',
            'correct_option' => 'required_if:type,mcq|nullable|string|max:5',
            'marks' => 'nullable|numeric|min:0.5',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $question = AdmissionExamQuestion::create([
            'admission_exam_id' => $exam->id,
            'question_text' => $request->question_text,
            'type' => $request->type,
            'options' => $request->type === 'mcq' ? $request->options : null,
            'correct_option' => $request->type === 'mcq' ? $request->correct_option : null,
            'marks' => $request->marks ?? 1,
            'sort_order' => $exam->questions()->count(),
        ]);

        return response()->json(['message' => 'Question added', 'question' => $question], 201);
    }

    public function updateQuestion(Request $request, $questionId): JsonResponse
    {
        $question = AdmissionExamQuestion::find($questionId);
        if (! $question) {
            return response()->json(['error' => 'Question not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'question_text' => 'sometimes|string',
            'options' => 'nullable|array',
            'correct_option' => 'nullable|string|max:5',
            'marks' => 'sometimes|numeric|min:0.5',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $question->update($request->only(['question_text', 'options', 'correct_option', 'marks']));

        return response()->json(['message' => 'Question updated', 'question' => $question->fresh()]);
    }

    public function destroyQuestion($questionId): JsonResponse
    {
        $question = AdmissionExamQuestion::find($questionId);
        if (! $question) {
            return response()->json(['error' => 'Question not found'], 404);
        }
        $question->delete();

        return response()->json(['message' => 'Question deleted']);
    }

    // ── Applicants ───────────────────────────────────────────────────────

    public function indexApplicants(Request $request): JsonResponse
    {
        $school = School::first();
        $query = Applicant::with(['cycle', 'class'])
            ->where('school_id', $school?->id ?? 1);

        if ($request->filled('admission_cycle_id')) {
            $query->where('admission_cycle_id', $request->admission_cycle_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $applicants = $query->orderByDesc('id')->paginate($request->get('per_page', 20));

        return response()->json($applicants);
    }

    public function showApplicant($id): JsonResponse
    {
        $applicant = Applicant::with(['cycle', 'class', 'examAttempts.exam', 'examAttempts.answers.question'])->find($id);
        if (! $applicant) {
            return response()->json(['error' => 'Applicant not found'], 404);
        }

        return response()->json(['applicant' => $applicant]);
    }

    public function approveApplicant(Request $request, $id): JsonResponse
    {
        return $this->decideApplicant($request, $id, 'approved');
    }

    public function rejectApplicant(Request $request, $id): JsonResponse
    {
        return $this->decideApplicant($request, $id, 'rejected');
    }

    public function waitlistApplicant(Request $request, $id): JsonResponse
    {
        return $this->decideApplicant($request, $id, 'waitlisted');
    }

    private function decideApplicant(Request $request, $id, string $status): JsonResponse
    {
        $applicant = Applicant::find($id);
        if (! $applicant) {
            return response()->json(['error' => 'Applicant not found'], 404);
        }

        $applicant->update([
            'status' => $status,
            'decision_notes' => $request->input('notes'),
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
        ]);

        $this->sendDecisionEmail($applicant, $status);

        return response()->json(['message' => "Applicant marked {$status}", 'applicant' => $applicant->fresh()]);
    }

    private function sendDecisionEmail(Applicant $applicant, string $status): void
    {
        if (! $applicant->email) {
            return;
        }

        $school = School::first();
        $schoolName = $school?->name ?? 'the school';

        $messages = [
            'approved' => "Congratulations! {$applicant->fullName()}'s application to {$schoolName} has been approved. The school will be in touch shortly with next steps for enrollment.",
            'rejected' => "Thank you for applying to {$schoolName}. After careful review, we are unable to offer {$applicant->fullName()} admission at this time.",
            'waitlisted' => "{$applicant->fullName()}'s application to {$schoolName} has been placed on the waitlist. We will notify you if a place becomes available.",
        ];

        $subject = match ($status) {
            'approved' => "Admission decision: {$schoolName}",
            'rejected' => "Admission decision: {$schoolName}",
            'waitlisted' => "Admission update: {$schoolName}",
            default => "Admission update: {$schoolName}",
        };

        $body = '<p>' . e($messages[$status] ?? '') . '</p>';
        if ($applicant->decision_notes) {
            $body .= '<p>' . nl2br(e($applicant->decision_notes)) . '</p>';
        }

        SendEmailJob::dispatch(
            to: $applicant->email,
            subject: $subject,
            body: $body,
            schoolId: (string) ($school?->id ?? ''),
            isHtml: true,
            type: 'admission_decision',
        );
    }
}
