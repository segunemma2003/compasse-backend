<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\UserEffectiveRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Super admin → "Open school": signs the Compasse super admin into a school's own
 * dashboard with full rights (to fix or delete anything there).
 *
 * Inside the school they act as one "Compasse Super Admin" account (role
 * super_admin, treated as the school administrator everywhere). Its password is
 * random and never shown, so the only way in is this endpoint; tokens last
 * 4 hours, and every opening is written to the school's audit log.
 */
class SuperAdminSchoolAccessController extends Controller
{
    public const SUPPORT_EMAIL = 'compasse-support@compasse.net';

    /** POST /tenants/{tenant}/open */
    public function open(Request $request, string $tenant): JsonResponse
    {
        $admin = $request->user();
        $t = Tenant::findOrFail($tenant);
        if (! $t->subdomain) {
            return response()->json(['error' => 'This school has no subdomain yet.'], 422);
        }

        tenancy()->initialize($t);
        try {
            $user = User::where('email', self::SUPPORT_EMAIL)->first();
            if (! $user) {
                $attrs = [
                    'name' => 'Compasse Super Admin',
                    'email' => self::SUPPORT_EMAIL,
                    'password' => Hash::make(Str::random(48)),
                    'role' => 'super_admin',
                    'status' => 'active',
                    'email_verified_at' => now(),
                ];
                if (Schema::hasColumn('users', 'email_generated')) {
                    $attrs['email_generated'] = true; // nothing is emailed to this account
                }
                if (Schema::hasColumn('users', 'tenant_id')) {
                    $attrs['tenant_id'] = $t->id;
                }
                $user = User::create($attrs);
            } elseif ($user->role !== 'super_admin' || $user->status !== 'active') {
                $user->forceFill(['role' => 'super_admin', 'status' => 'active'])->save();
            }

            $token = $user->createToken('super-admin-open', ['*'], now()->addHours(4))->plainTextToken;
            $user->forceFill(['last_login_at' => now()])->save();

            auth()->setUser($user);
            AuditLog::record('user', $user->id, 'super_admin_opened',
                'Compasse super admin ' . ($admin?->email ?? 'unknown') . ' opened the school');

            UserEffectiveRoles::appendToUserJson($user);
            $school = Schema::hasTable('schools') ? School::first() : null;

            return response()->json([
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
                'school' => $school,
                'tenant' => ['id' => $t->id, 'name' => $t->name, 'subdomain' => $t->subdomain, 'status' => $t->status],
                'expires_in_hours' => 4,
            ]);
        } finally {
            tenancy()->end();
        }
    }
}
