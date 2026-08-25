<?php

namespace App\Http\Controllers;

use App\Jobs\SendEmailJob;
use App\Models\JobApplicant;
use App\Models\JobOpening;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * School-side staff recruitment: open/close job postings and review
 * candidates. The public-facing counterpart (careers page + application
 * form) is PublicRecruitmentController.
 */
class RecruitmentController extends Controller
{
    // ── Job openings ────────────────────────────────────────────────────

    public function indexOpenings(): JsonResponse
    {
        $school = School::first();
        $openings = JobOpening::where('school_id', $school?->id ?? 1)
            ->withCount('applicants')
            ->orderByDesc('id')
            ->get();

        return response()->json(['job_openings' => $openings]);
    }

    public function storeOpening(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:150',
            'role' => 'required|in:teacher,admin,staff,accountant,librarian,driver,security,cleaner,caterer,nurse',
            'department' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'requirements' => 'nullable|string',
            'opens_at' => 'nullable|date',
            'closes_at' => 'nullable|date|after_or_equal:opens_at',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $school = School::first();

        $opening = JobOpening::create([
            'school_id' => $school?->id ?? 1,
            'title' => $request->title,
            'role' => $request->role,
            'department' => $request->department,
            'description' => $request->description,
            'requirements' => $request->requirements,
            'opens_at' => $request->opens_at,
            'closes_at' => $request->closes_at,
            'status' => 'draft',
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['message' => 'Job opening created', 'job_opening' => $opening], 201);
    }

    public function showOpening($id): JsonResponse
    {
        $opening = JobOpening::withCount('applicants')->find($id);
        if (! $opening) {
            return response()->json(['error' => 'Job opening not found'], 404);
        }

        return response()->json(['job_opening' => $opening]);
    }

    /**
     * Update details, or flip status open/closed — opening it is what makes
     * the public application form live on the school's careers page.
     */
    public function updateOpening(Request $request, $id): JsonResponse
    {
        $opening = JobOpening::find($id);
        if (! $opening) {
            return response()->json(['error' => 'Job opening not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:150',
            'department' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'requirements' => 'nullable|string',
            'opens_at' => 'nullable|date',
            'closes_at' => 'nullable|date',
            'status' => 'sometimes|in:draft,open,closed',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $opening->update($request->only([
            'title', 'department', 'description', 'requirements', 'opens_at', 'closes_at', 'status',
        ]));

        return response()->json(['message' => 'Job opening updated', 'job_opening' => $opening->fresh()]);
    }

    public function destroyOpening($id): JsonResponse
    {
        $opening = JobOpening::withCount('applicants')->find($id);
        if (! $opening) {
            return response()->json(['error' => 'Job opening not found'], 404);
        }
        if ($opening->applicants_count > 0) {
            return response()->json(['error' => 'Cannot delete a job opening with applicants. Close it instead.'], 422);
        }

        $opening->delete();

        return response()->json(['message' => 'Job opening deleted']);
    }

    // ── Applicants ───────────────────────────────────────────────────────

    public function indexApplicants(Request $request): JsonResponse
    {
        $school = School::first();
        $query = JobApplicant::with('jobOpening')->where('school_id', $school?->id ?? 1);

        if ($request->filled('job_opening_id')) {
            $query->where('job_opening_id', $request->job_opening_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $applicants = $query->orderByDesc('id')->paginate($request->get('per_page', 20));

        return response()->json($applicants);
    }

    public function showApplicant($id): JsonResponse
    {
        $applicant = JobApplicant::with('jobOpening')->find($id);
        if (! $applicant) {
            return response()->json(['error' => 'Applicant not found'], 404);
        }

        return response()->json(['applicant' => $applicant]);
    }

    public function shortlistApplicant(Request $request, $id): JsonResponse
    {
        return $this->decideApplicant($request, $id, 'shortlisted');
    }

    public function offerApplicant(Request $request, $id): JsonResponse
    {
        return $this->decideApplicant($request, $id, 'offered');
    }

    public function hireApplicant(Request $request, $id): JsonResponse
    {
        return $this->decideApplicant($request, $id, 'hired');
    }

    public function rejectApplicant(Request $request, $id): JsonResponse
    {
        return $this->decideApplicant($request, $id, 'rejected');
    }

    /**
     * Convert a hired candidate into a real staff account — reuses
     * StaffController::store() rather than re-implementing employee-ID/email
     * generation and welcome-email dispatch a second time.
     */
    public function onboard(Request $request, $id): JsonResponse
    {
        $applicant = JobApplicant::with('jobOpening')->find($id);
        if (! $applicant) {
            return response()->json(['error' => 'Applicant not found'], 404);
        }
        if ($applicant->onboarded_user_id) {
            return response()->json(['error' => 'This applicant has already been onboarded'], 422);
        }
        if ($applicant->status !== 'hired') {
            return response()->json(['error' => 'Mark the applicant as hired before onboarding them'], 422);
        }

        $staffRequest = Request::create('/staff', 'POST', [
            'first_name' => $applicant->first_name,
            'last_name' => $applicant->last_name,
            'email' => $applicant->email,
            'phone' => $applicant->phone,
            'role' => $applicant->jobOpening->role,
            'department' => $applicant->jobOpening->department,
            'employment_date' => now()->toDateString(),
        ]);

        $response = app(StaffController::class)->store($staffRequest);
        $payload = json_decode($response->getContent(), true);

        if ($response->getStatusCode() !== 201) {
            return response()->json(['error' => 'Onboarding failed', 'details' => $payload], 422);
        }

        $applicant->update([
            'onboarded_user_id' => $payload['staff']['user_id'] ?? null,
            'onboarded_at' => now(),
        ]);

        return response()->json([
            'message' => 'Applicant onboarded',
            'staff' => $payload['staff'],
            'login_credentials' => $payload['login_credentials'],
        ]);
    }

    private function decideApplicant(Request $request, $id, string $status): JsonResponse
    {
        $applicant = JobApplicant::with('jobOpening')->find($id);
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

    private function sendDecisionEmail(JobApplicant $applicant, string $status): void
    {
        $school = School::first();
        $schoolName = $school?->name ?? 'the school';

        $messages = [
            'shortlisted' => "Thank you for applying to {$schoolName}. Your application for {$applicant->jobOpening->title} has been shortlisted. We will be in touch about next steps.",
            'offered' => "Congratulations! {$schoolName} would like to offer you the {$applicant->jobOpening->title} position. Please reply to this email to confirm.",
            'hired' => "Welcome to {$schoolName}! You have been confirmed for the {$applicant->jobOpening->title} position. Your account details will follow shortly.",
            'rejected' => "Thank you for applying to {$schoolName} for {$applicant->jobOpening->title}. We have decided not to move forward with your application at this time.",
        ];

        $subject = match ($status) {
            'shortlisted' => "Application update: {$schoolName}",
            'offered' => "Job offer: {$schoolName}",
            'hired' => "Welcome to {$schoolName}",
            'rejected' => "Application update: {$schoolName}",
            default => "Application update: {$schoolName}",
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
            type: 'recruitment_decision',
        );
    }
}
