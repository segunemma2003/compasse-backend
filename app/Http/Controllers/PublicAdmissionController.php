<?php

namespace App\Http\Controllers;

use App\Jobs\SendEmailJob;
use App\Models\AdmissionCycle;
use App\Models\Applicant;
use App\Models\ApplicantExamAnswer;
use App\Models\ApplicantExamAttempt;
use App\Models\School;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Public (unauthenticated) admissions endpoints, reachable from the school's
 * public website by subdomain — mirrors LandingPageController::publicLandingPage's
 * pattern for resolving tenant context outside the normal auth/tenant middleware.
 */
class PublicAdmissionController extends Controller
{
    /**
     * List currently open admission cycles for the registration form to show.
     */
    public function openCycles(string $subdomain): JsonResponse
    {
        $tenant = $this->resolveTenant($subdomain);
        if ($tenant instanceof JsonResponse) {
            return $tenant;
        }

        tenancy()->initialize($tenant);
        try {
            $cycles = AdmissionCycle::with('class')
                ->where('status', 'open')
                ->get()
                ->filter(fn (AdmissionCycle $c) => $c->isOpen())
                ->map(fn (AdmissionCycle $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'class' => $c->class?->name,
                    'description' => $c->description,
                    'requires_entrance_exam' => $c->requires_entrance_exam,
                    'closes_at' => $c->closes_at,
                ])
                ->values();
        } finally {
            tenancy()->end();
        }

        return response()->json(['admission_cycles' => $cycles]);
    }

    /**
     * Submit an application. Creates the applicant with a unique access token
     * (their only means of checking status / taking the entrance exam, since
     * applicants don't have a login) and emails a confirmation.
     */
    public function apply(Request $request, string $subdomain): JsonResponse
    {
        $tenant = $this->resolveTenant($subdomain);
        if ($tenant instanceof JsonResponse) {
            return $tenant;
        }

        $validator = Validator::make($request->all(), [
            'admission_cycle_id' => 'required|integer',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female,other',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'parent_name' => 'nullable|string|max:255',
            'parent_phone' => 'nullable|string|max:20',
            'parent_email' => 'nullable|email|max:255',
            'previous_school' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }
        if (! $request->email && ! $request->parent_email) {
            return response()->json(['error' => 'Provide an email (applicant or parent) so we can send updates.'], 422);
        }

        tenancy()->initialize($tenant);
        try {
            $cycle = AdmissionCycle::with('exam')->find($request->admission_cycle_id);
            if (! $cycle || ! $cycle->isOpen()) {
                return response()->json(['error' => 'This admission cycle is not currently open.'], 422);
            }

            $school = School::first();

            $applicant = Applicant::create([
                'school_id' => $school?->id ?? 1,
                'admission_cycle_id' => $cycle->id,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'date_of_birth' => $request->date_of_birth,
                'gender' => $request->gender,
                'email' => $request->email,
                'phone' => $request->phone,
                'parent_name' => $request->parent_name,
                'parent_phone' => $request->parent_phone,
                'parent_email' => $request->parent_email,
                'previous_school' => $request->previous_school,
                'class_id' => $cycle->class_id,
                'status' => $cycle->requires_entrance_exam ? 'exam_invited' : 'submitted',
            ]);

            $this->sendApplicationEmail($applicant, $cycle, $subdomain, $school);

            $response = [
                'message' => 'Application submitted successfully. Check your email for confirmation.',
                'applicant_id' => $applicant->id,
                'access_token' => $applicant->access_token,
                'requires_entrance_exam' => $cycle->requires_entrance_exam,
            ];
        } finally {
            tenancy()->end();
        }

        return response()->json($response, 201);
    }

    /**
     * Check application/exam status by access token (the applicant's only
     * credential — no login exists for them).
     */
    public function status(string $subdomain, string $token): JsonResponse
    {
        $tenant = $this->resolveTenant($subdomain);
        if ($tenant instanceof JsonResponse) {
            return $tenant;
        }

        tenancy()->initialize($tenant);
        try {
            $applicant = Applicant::with(['cycle.exam', 'examAttempts'])->where('access_token', $token)->first();
            if (! $applicant) {
                return response()->json(['error' => 'Application not found'], 404);
            }

            $exam = $applicant->cycle->exam;
            $attempt = $exam ? $applicant->examAttempts->firstWhere('admission_exam_id', $exam->id) : null;

            $result = [
                'applicant' => [
                    'full_name' => $applicant->fullName(),
                    'status' => $applicant->status,
                    'class' => $applicant->class?->name,
                ],
                'exam' => $exam ? [
                    'title' => $exam->title,
                    'duration_minutes' => $exam->duration_minutes,
                    'is_open' => $exam->isCurrentlyOpen(),
                    'scheduled_start' => $exam->scheduled_start,
                    'scheduled_end' => $exam->scheduled_end,
                    'attempt_status' => $attempt?->status,
                ] : null,
            ];
        } finally {
            tenancy()->end();
        }

        return response()->json($result);
    }

    /**
     * Start (or resume) the entrance exam attempt. Returns questions with
     * answer keys stripped.
     */
    public function startExam(string $subdomain, string $token): JsonResponse
    {
        $tenant = $this->resolveTenant($subdomain);
        if ($tenant instanceof JsonResponse) {
            return $tenant;
        }

        tenancy()->initialize($tenant);
        try {
            $applicant = Applicant::with('cycle.exam.questions')->where('access_token', $token)->first();
            if (! $applicant) {
                return response()->json(['error' => 'Application not found'], 404);
            }

            $exam = $applicant->cycle->exam;
            if (! $exam || ! $exam->isCurrentlyOpen()) {
                return response()->json(['error' => 'This exam is not currently open.'], 422);
            }

            $attempt = ApplicantExamAttempt::firstOrCreate(
                ['applicant_id' => $applicant->id, 'admission_exam_id' => $exam->id],
                ['status' => 'not_started']
            );

            if ($attempt->status === 'submitted' || $attempt->status === 'graded') {
                return response()->json(['error' => 'You have already submitted this exam.'], 422);
            }

            if ($attempt->status === 'not_started') {
                $attempt->update(['status' => 'in_progress', 'started_at' => now()]);
            }

            $result = [
                'attempt_id' => $attempt->id,
                'started_at' => $attempt->started_at,
                'duration_minutes' => $exam->duration_minutes,
                'instructions' => $exam->instructions,
                'questions' => $exam->questions->map(fn ($q) => $q->toApplicantArray())->values(),
            ];
        } finally {
            tenancy()->end();
        }

        return response()->json($result);
    }

    /**
     * Submit answers. MCQs auto-grade against correct_option; short answers
     * are stored ungraded for an admin to score manually afterward.
     */
    public function submitExam(Request $request, string $subdomain, string $token): JsonResponse
    {
        $tenant = $this->resolveTenant($subdomain);
        if ($tenant instanceof JsonResponse) {
            return $tenant;
        }

        $validator = Validator::make($request->all(), [
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|integer',
            'answers.*.answer' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        tenancy()->initialize($tenant);
        try {
            $applicant = Applicant::with('cycle.exam.questions')->where('access_token', $token)->first();
            if (! $applicant) {
                return response()->json(['error' => 'Application not found'], 404);
            }

            $exam = $applicant->cycle->exam;
            $attempt = $exam ? ApplicantExamAttempt::where('applicant_id', $applicant->id)
                ->where('admission_exam_id', $exam->id)->first() : null;

            if (! $exam || ! $attempt || $attempt->status === 'submitted' || $attempt->status === 'graded') {
                return response()->json(['error' => 'No active exam attempt to submit.'], 422);
            }

            $questionsById = $exam->questions->keyBy('id');
            $score = 0.0;
            $hasUngraded = false;

            foreach ($request->answers as $ans) {
                $question = $questionsById->get($ans['question_id']);
                if (! $question) {
                    continue;
                }

                $isCorrect = null;
                $marksAwarded = null;
                if ($question->type === 'mcq') {
                    $isCorrect = strcasecmp((string) ($ans['answer'] ?? ''), (string) $question->correct_option) === 0;
                    $marksAwarded = $isCorrect ? $question->marks : 0;
                    $score += $marksAwarded;
                } else {
                    $hasUngraded = true;
                }

                ApplicantExamAnswer::updateOrCreate(
                    ['attempt_id' => $attempt->id, 'question_id' => $question->id],
                    ['answer_text' => $ans['answer'] ?? null, 'is_correct' => $isCorrect, 'marks_awarded' => $marksAwarded]
                );
            }

            $attempt->update([
                'submitted_at' => now(),
                'status' => $hasUngraded ? 'submitted' : 'graded',
                'score' => $score,
            ]);

            $applicant->update([
                'status' => 'exam_completed',
                'exam_score' => $hasUngraded ? $applicant->exam_score : $score,
            ]);

            $school = School::first();
            if ($applicant->email) {
                SendEmailJob::dispatch(
                    to: $applicant->email,
                    subject: 'Entrance exam submitted — ' . ($school?->name ?? ''),
                    body: '<p>Thank you, ' . e($applicant->fullName()) . '. Your entrance exam has been submitted successfully. The school will review your application and be in touch.</p>',
                    schoolId: (string) ($school?->id ?? ''),
                    isHtml: true,
                    type: 'admission_exam_submitted',
                );
            }

            $result = ['message' => 'Exam submitted successfully', 'status' => $attempt->status];
        } finally {
            tenancy()->end();
        }

        return response()->json($result);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function resolveTenant(string $subdomain): Tenant|JsonResponse
    {
        $subdomain = strtolower(trim($subdomain));
        $tenant = Tenant::where('subdomain', $subdomain)->first();

        if (! $tenant) {
            return response()->json(['error' => 'School not found'], 404);
        }
        if ($tenant->status !== 'active') {
            return response()->json(['error' => 'This school is not currently active.'], 403);
        }

        return $tenant;
    }

    private function sendApplicationEmail(Applicant $applicant, AdmissionCycle $cycle, string $subdomain, ?School $school): void
    {
        $to = $applicant->email ?: $applicant->parent_email;
        if (! $to) {
            return;
        }

        $schoolName = $school?->name ?? 'the school';
        $rootDomain = config('app.root_domain', 'compasse.net');
        $portalUrl = "https://{$subdomain}.{$rootDomain}/apply/status/{$applicant->access_token}";

        $body = "<p>Thank you for applying to <strong>" . e($schoolName) . "</strong> for the <strong>" . e($cycle->name) . "</strong> admission cycle.</p>";
        if ($cycle->requires_entrance_exam) {
            $body .= "<p>This admission requires an entrance exam. Use the link below to check your status and take the exam once it opens:</p>";
        } else {
            $body .= "<p>Use the link below to check your application status:</p>";
        }
        $body .= "<p><a href=\"{$portalUrl}\">{$portalUrl}</a></p>";
        $body .= "<p>Keep this link safe — it's the only way to check your application.</p>";

        SendEmailJob::dispatch(
            to: $to,
            subject: "Application received — {$schoolName}",
            body: $body,
            schoolId: (string) ($school?->id ?? ''),
            isHtml: true,
            type: 'admission_received',
        );
    }
}
