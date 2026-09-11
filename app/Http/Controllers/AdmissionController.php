<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesGuardianAccounts;
use App\Jobs\SendEmailJob;
use App\Models\AdmissionCycle;
use App\Models\AdmissionExam;
use App\Models\AdmissionExamQuestion;
use App\Models\Applicant;
use App\Models\School;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * School-side admissions management: open/close admission cycles, build an
 * optional entrance exam, and review/decide on applicants. The public-facing
 * counterpart (registration form + exam-taking) is PublicAdmissionController.
 */
class AdmissionController extends Controller
{
    use ManagesGuardianAccounts;


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
            'welcome_email_subject' => 'nullable|string|max:200',
            'welcome_email_body' => 'nullable|string|max:5000',
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
            'welcome_email_subject' => $request->welcome_email_subject,
            'welcome_email_body' => $request->welcome_email_body,
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
            'welcome_email_subject' => 'nullable|string|max:200',
            'welcome_email_body' => 'nullable|string|max:5000',
            'requires_entrance_exam' => 'sometimes|boolean',
            'opens_at' => 'nullable|date',
            'closes_at' => 'nullable|date',
            'status' => 'sometimes|in:draft,open,closed',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $cycle->update($request->only([
            'name', 'description', 'welcome_email_subject', 'welcome_email_body',
            'requires_entrance_exam', 'opens_at', 'closes_at', 'status',
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
        $query = Applicant::with(['cycle', 'class', 'student:id,first_name,last_name,admission_number'])
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

    /**
     * Full submitted application: personal/guardian details plus, if the
     * cycle required one, the entrance exam attempt and every answer —
     * everything an admin needs to actually decide, rather than approving
     * or rejecting blind off the bare list.
     */
    public function showApplicant($id): JsonResponse
    {
        $applicant = Applicant::with([
            'cycle', 'class', 'student:id,first_name,last_name,admission_number',
            'examAttempts.exam', 'examAttempts.answers.question',
        ])->find($id);
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
        $applicant = Applicant::with('cycle')->find($id);
        if (! $applicant) {
            return response()->json(['error' => 'Applicant not found'], 404);
        }

        $applicant->update([
            'status' => $status,
            'decision_notes' => $request->input('notes'),
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
        ]);

        // Approving is what actually answers "does this become a student?" —
        // it does, once, here. Re-approving an already-converted applicant
        // (e.g. correcting a decision_notes typo) is a safe no-op: enrollApplicant()
        // returns null without touching the database when student_id is already set.
        $credentials = $status === 'approved' ? $this->enrollApplicant($applicant) : null;

        $applicant->refresh();
        $this->sendDecisionEmail($applicant, $status, $credentials);

        return response()->json([
            'message' => "Applicant marked {$status}",
            'applicant' => $applicant->load(['cycle', 'class', 'student']),
            'login_credentials' => $credentials,
        ]);
    }

    /**
     * Create the Student (and, if a parent email was given, a Guardian)
     * record behind an approved applicant, reusing the exact same
     * auto-generation and credentialing logic as manual enrollment
     * (People -> Students -> Enroll Student) so an approved applicant and a
     * hand-enrolled student end up identical. Returns the new login so the
     * caller can both email it and show it in the UI, the same way
     * StudentController::store's response does.
     *
     * @return array{email: ?string, password: string, student: array}|null null if already converted
     */
    private function enrollApplicant(Applicant $applicant): ?array
    {
        if ($applicant->student_id) {
            return null;
        }

        $school = School::first();
        $schoolId = $school?->id ?? $applicant->school_id;

        return DB::transaction(function () use ($applicant, $school, $schoolId) {
            $studentData = [
                'school_id' => $schoolId,
                'first_name' => $applicant->first_name,
                'last_name' => $applicant->last_name,
                'date_of_birth' => $applicant->date_of_birth,
                'gender' => $applicant->gender,
                'class_id' => $applicant->class_id,
                'admission_date' => now(),
            ];
            // The applicant's own email (if they gave one) becomes the
            // student's login; otherwise createWithAutoGeneration mints one,
            // exactly as it does for a manually enrolled student left blank.
            if ($applicant->email) {
                $studentData['email'] = $applicant->email;
            }

            $student = Student::createWithAutoGeneration($studentData);
            $applicant->update(['student_id' => $student->id]);

            $defaultPassword = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $student->last_name)) ?: 'student123';

            if ($applicant->parent_email) {
                [$guardianFirst, $guardianLast] = $this->splitGuardianName($applicant->parent_name, $applicant->last_name);

                $result = $this->createOrFindGuardian([
                    'first_name' => $guardianFirst,
                    'last_name' => $guardianLast,
                    'email' => $applicant->parent_email,
                    'phone' => $applicant->parent_phone,
                    'relationship' => 'Guardian',
                ], $schoolId, $school);

                $student->guardians()->attach($result['guardian']->id, [
                    'relationship' => 'Guardian',
                    'is_primary' => true,
                    'emergency_contact' => true,
                ]);

                if ($result['credential_email']) {
                    SendEmailJob::dispatch(
                        to: $result['credential_email']['to'],
                        subject: $result['credential_email']['subject'],
                        body: $result['credential_email']['body'],
                        schoolId: (string) $schoolId,
                        type: 'credentials',
                    );
                }
            }

            return [
                'email' => $student->email,
                'password' => $defaultPassword,
                'note' => 'Password is the student\'s surname in lowercase. Change it on first login.',
                'student' => ['id' => $student->id, 'admission_number' => $student->admission_number],
            ];
        });
    }

    /** Best-effort split of a single free-text "parent name" field into first/last. */
    private function splitGuardianName(?string $parentName, string $studentLastName): array
    {
        $parentName = trim((string) $parentName);
        if ($parentName === '') {
            return ['Parent/Guardian', $studentLastName];
        }
        $parts = preg_split('/\s+/', $parentName);
        if (count($parts) === 1) {
            return [$parts[0], $studentLastName];
        }
        $last = array_pop($parts);
        return [implode(' ', $parts), $last];
    }

    private function sendDecisionEmail(Applicant $applicant, string $status, ?array $credentials): void
    {
        $recipient = $applicant->parent_email ?: $applicant->email;
        if (! $recipient) {
            return;
        }

        $school = School::first();
        $schoolName = $school?->name ?? 'the school';

        if ($status === 'approved') {
            $cycle = $applicant->cycle;
            $subject = $cycle?->welcome_email_subject ?: AdmissionCycle::DEFAULT_WELCOME_SUBJECT;
            $template = $cycle?->welcome_email_body ?: AdmissionCycle::DEFAULT_WELCOME_BODY;

            $replacements = [
                '{applicant_name}' => $applicant->fullName(),
                '{school_name}'    => $schoolName,
                '{class_name}'     => $applicant->class?->name ?? 'the applied class',
                '{login_email}'    => $credentials['email'] ?? '(none — contact the school)',
                '{password}'       => $credentials['password'] ?? '(none — contact the school)',
                '{portal_url}'     => $this->portalUrl(),
            ];
            $subject = strtr($subject, $replacements);
            $body = '<p>' . nl2br(e(strtr($template, $replacements))) . '</p>';

            // A school that customises the body might not think to include the
            // placeholders at all — the family must still receive real
            // credentials, so append them whenever the template didn't ask for
            // {login_email}/{password} itself.
            if ($credentials && !str_contains($template, '{login_email}') && !str_contains($template, '{password}')) {
                $body .= '<p><strong>Login email:</strong> ' . e($credentials['email'] ?? '—')
                    . '<br><strong>Password:</strong> ' . e($credentials['password'] ?? '—')
                    . '<br><strong>Portal:</strong> ' . e($this->portalUrl()) . '</p>';
            }
        } else {
            $messages = [
                'rejected' => "Thank you for applying to {$schoolName}. After careful review, we are unable to offer {$applicant->fullName()} admission at this time.",
                'waitlisted' => "{$applicant->fullName()}'s application to {$schoolName} has been placed on the waitlist. We will notify you if a place becomes available.",
            ];
            $subject = $status === 'waitlisted' ? "Admission update: {$schoolName}" : "Admission decision: {$schoolName}";
            $body = '<p>' . e($messages[$status] ?? '') . '</p>';
        }

        if ($applicant->decision_notes) {
            $body .= '<p>' . nl2br(e($applicant->decision_notes)) . '</p>';
        }

        SendEmailJob::dispatch(
            to: $recipient,
            subject: $subject,
            body: $body,
            schoolId: (string) ($school?->id ?? ''),
            isHtml: true,
            type: 'admission_decision',
        );
    }
}
