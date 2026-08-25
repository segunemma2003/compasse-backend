<?php

namespace App\Http\Controllers;

use App\Jobs\SendEmailJob;
use App\Models\JobApplicant;
use App\Models\JobOpening;
use App\Models\School;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Public (unauthenticated) careers page endpoints, reachable by subdomain —
 * same tenant-resolution pattern as PublicAdmissionController.
 */
class PublicRecruitmentController extends Controller
{
    public function openJobs(string $subdomain): JsonResponse
    {
        $tenant = $this->resolveTenant($subdomain);
        if ($tenant instanceof JsonResponse) {
            return $tenant;
        }

        tenancy()->initialize($tenant);
        try {
            $jobs = JobOpening::where('status', 'open')
                ->get()
                ->filter(fn (JobOpening $j) => $j->isOpen())
                ->map(fn (JobOpening $j) => [
                    'id' => $j->id,
                    'title' => $j->title,
                    'role' => $j->role,
                    'department' => $j->department,
                    'description' => $j->description,
                    'requirements' => $j->requirements,
                    'closes_at' => $j->closes_at,
                ])
                ->values();
        } finally {
            tenancy()->end();
        }

        return response()->json(['job_openings' => $jobs]);
    }

    public function apply(Request $request, string $subdomain): JsonResponse
    {
        $tenant = $this->resolveTenant($subdomain);
        if ($tenant instanceof JsonResponse) {
            return $tenant;
        }

        $validator = Validator::make($request->all(), [
            'job_opening_id' => 'required|integer',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:20',
            'cover_letter' => 'nullable|string|max:5000',
            'qualifications' => 'nullable|string|max:2000',
            'years_of_experience' => 'nullable|integer|min:0|max:60',
            'resume' => 'nullable|file|mimes:pdf,doc,docx|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        tenancy()->initialize($tenant);
        try {
            $opening = JobOpening::find($request->job_opening_id);
            if (! $opening || ! $opening->isOpen()) {
                return response()->json(['error' => 'This job opening is not currently accepting applications.'], 422);
            }

            $school = School::first();

            $resumePath = null;
            if ($request->hasFile('resume')) {
                $resumePath = $request->file('resume')->store(
                    "schools/{$school?->id}/recruitment/resumes",
                    's3'
                );
            }

            $applicant = JobApplicant::create([
                'school_id' => $school?->id ?? 1,
                'job_opening_id' => $opening->id,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'cover_letter' => $request->cover_letter,
                'qualifications' => $request->qualifications,
                'years_of_experience' => $request->years_of_experience,
                'resume_path' => $resumePath,
                'status' => 'submitted',
            ]);

            $this->sendApplicationEmail($applicant, $opening, $subdomain, $school);

            $response = [
                'message' => 'Application submitted successfully. Check your email for confirmation.',
                'applicant_id' => $applicant->id,
                'access_token' => $applicant->access_token,
            ];
        } finally {
            tenancy()->end();
        }

        return response()->json($response, 201);
    }

    public function status(string $subdomain, string $token): JsonResponse
    {
        $tenant = $this->resolveTenant($subdomain);
        if ($tenant instanceof JsonResponse) {
            return $tenant;
        }

        tenancy()->initialize($tenant);
        try {
            $applicant = JobApplicant::with('jobOpening')->where('access_token', $token)->first();
            if (! $applicant) {
                return response()->json(['error' => 'Application not found'], 404);
            }

            $result = [
                'full_name' => $applicant->fullName(),
                'job_title' => $applicant->jobOpening->title,
                'status' => $applicant->status,
                'applied_at' => $applicant->created_at,
            ];
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

    private function sendApplicationEmail(JobApplicant $applicant, JobOpening $opening, string $subdomain, ?School $school): void
    {
        $schoolName = $school?->name ?? 'the school';
        $rootDomain = config('app.root_domain', 'compasse.net');
        $statusUrl = "https://{$subdomain}.{$rootDomain}/careers/status/{$applicant->access_token}";

        $body = "<p>Thank you for applying to <strong>" . e($schoolName) . "</strong> for the <strong>" . e($opening->title) . "</strong> position.</p>"
            . "<p>Use the link below to check your application status:</p>"
            . "<p><a href=\"{$statusUrl}\">{$statusUrl}</a></p>";

        SendEmailJob::dispatch(
            to: $applicant->email,
            subject: "Application received — {$schoolName}",
            body: $body,
            schoolId: (string) ($school?->id ?? ''),
            isHtml: true,
            type: 'recruitment_received',
        );
    }
}
