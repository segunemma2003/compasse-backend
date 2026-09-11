<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Guardian;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Shared by StudentController (manual enrollment) and AdmissionController
 * (approve -> auto-enroll) — creating/reusing a guardian's portal login is
 * identical in both flows, so it lives here once instead of twice.
 */
trait ManagesGuardianAccounts
{
    /**
     * Build the school's portal login URL from the current tenant subdomain.
     */
    protected function portalUrl(): string
    {
        $subdomain = config('tenant.subdomain');
        $rootDomain = parse_url(env('FRONTEND_URL', 'https://compasse.net'), PHP_URL_HOST) ?: 'compasse.net';

        return $subdomain ? "https://{$subdomain}.{$rootDomain}" : "https://{$rootDomain}";
    }

    /**
     * Create or find guardian by email
     *
     * @return array{guardian: Guardian, credential_email: ?array{to: string, subject: string, body: string}}
     */
    protected function createOrFindGuardian(array $guardianData, int $schoolId, ?School $school = null): array
    {
        $schoolName = $school->name ?? 'the school';
        $portalUrl  = $this->portalUrl();

        // Check if guardian exists by email
        $guardian = Guardian::where('email', $guardianData['email'])->first();

        if ($guardian) {
            // Already has an account — don't resend a password, but still let them
            // know a new child was linked to their existing login instead of staying silent.
            return [
                'guardian' => $guardian,
                'credential_email' => [
                    'to'      => $guardianData['email'],
                    'subject' => "New student linked to your {$schoolName} account",
                    'body'    => "Hello {$guardianData['first_name']},\n\n"
                        . "A student has been added to your existing parent/guardian account at {$schoolName}.\n\n"
                        . "Log in to view their profile: {$portalUrl}\n\n"
                        . "Regards,\n{$schoolName}",
                ],
            ];
        }

        // Generate a random password for guardian and send via email
        $guardianPassword = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $guardianData['last_name'])) ?: substr(md5(uniqid()), 0, 10);

        // Create new guardian with auto-generated user account
        $user = User::create([
            'name' => trim($guardianData['first_name'] . ' ' . $guardianData['last_name']),
            'email' => $guardianData['email'],
            'password' => Hash::make($guardianPassword),
            'role' => 'guardian',
            'status' => 'active',
        ]);

        $body = "Hello {$guardianData['first_name']},\n\n"
            . "A parent/guardian account has been created for you at {$schoolName}.\n\n"
            . "Login Email: {$guardianData['email']}\n"
            . "Password: {$guardianPassword}\n"
            . "Portal: {$portalUrl}\n\n"
            . "Please log in and change your password.\n\n"
            . "Regards,\n{$schoolName}";

        $guardian = Guardian::create([
            'school_id' => $schoolId,
            'user_id' => $user->id,
            'first_name' => $guardianData['first_name'],
            'last_name' => $guardianData['last_name'],
            'middle_name' => $guardianData['middle_name'] ?? null,
            'email' => $guardianData['email'],
            'phone' => $guardianData['phone'] ?? null,
            'address' => $guardianData['address'] ?? null,
            'occupation' => $guardianData['occupation'] ?? null,
            'employer' => $guardianData['employer'] ?? null,
            'relationship_to_student' => $guardianData['relationship'],
            'emergency_contact' => $guardianData['emergency_contact'] ?? $guardianData['phone'],
            'status' => 'active',
        ]);

        return [
            'guardian' => $guardian,
            'credential_email' => [
                'to'      => $guardianData['email'],
                'subject' => 'Your Guardian Login Credentials',
                'body'    => $body,
            ],
        ];
    }
}
