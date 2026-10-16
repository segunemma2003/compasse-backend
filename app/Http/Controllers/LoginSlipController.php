<?php

namespace App\Http\Controllers;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Support\InitialPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Login slips for students, parents and staff — a whole class (or all staff) or chosen people.
 *
 * Nobody's password is changed: a slip shows the password the school issued (kept encrypted
 * until the person picks their own). Only people with no issued password on file — they've
 * changed it, or were created before this — get a new one, and only when the admin asks.
 */
class LoginSlipController extends Controller
{
    /** Easy to read aloud and type: no 0/O, 1/l/I. */
    private function password(): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
        $p = '';
        for ($i = 0; $i < 8; $i++) {
            $p .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $p;
    }

    /** POST /students/login-slips {type?: students|parents|staff, class_id?, student_ids?, ids?, reset_missing?} */
    public function generate(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'user.manage')) {
            return $denied;
        }
        $data = $request->validate([
            'type' => 'nullable|in:students,parents,staff',
            'class_id' => 'nullable|integer|exists:classes,id',
            'student_ids' => 'nullable|array|max:1000',
            'student_ids.*' => 'integer',
            'ids' => 'nullable|array|max:1000',
            'ids.*' => 'integer',
            'reset_missing' => 'nullable|boolean',
        ]);
        $type = $data['type'] ?? 'students';
        $ids = $data['ids'] ?? $data['student_ids'] ?? [];
        if ($type !== 'staff' && empty($data['class_id']) && ! $ids) {
            return response()->json(['message' => 'Choose a class or people.'], 422);
        }

        // [user, name, detail (class / children / role), username]
        $people = match ($type) {
            'students' => $this->students($data['class_id'] ?? null, $ids),
            'parents' => $this->parents($data['class_id'] ?? null, $ids),
            'staff' => $this->staff($ids),
        };

        $reset = (bool) ($data['reset_missing'] ?? false);
        $slips = [];
        $missing = 0;
        DB::transaction(function () use ($people, $reset, &$slips, &$missing) {
            foreach ($people as [$user, $name, $detail, $username]) {
                $password = InitialPassword::get($user);
                if ($password === null && $reset) {
                    $password = $this->password();
                    $user->forceFill(['password' => Hash::make($password)])->save();
                    $user->tokens()->delete();
                    InitialPassword::remember($user, $password);
                }
                if ($password === null) {
                    $missing++;
                }
                $slips[] = [
                    'user_id' => $user->id, 'name' => $name, 'class' => $detail,
                    'username' => $username, 'email' => $user->email, 'password' => $password,
                ];
            }
        });

        $sub = null;
        try {
            $sub = tenant()?->subdomain;
        } catch (\Throwable) {
        }

        return response()->json([
            'school' => \App\Models\School::first()?->name,
            'login_url' => $sub ? 'https://' . $sub . '.' . config('app.root_domain', 'compasse.net') . '/login' : null,
            'slips' => $slips,
            'missing' => $missing,
            'message' => count($slips) . ' slip(s).' . ($missing ? " {$missing} have changed their password (not shown) — tick \"give them a new password\" to issue one." : ''),
        ]);
    }

    private function students(?int $classId, array $ids): array
    {
        return Student::with('class:id,name')
            ->when($classId, fn ($q) => $q->where('class_id', $classId))
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->whereNotNull('user_id')->orderBy('first_name')->orderBy('last_name')->get()
            ->map(fn ($s) => ($u = User::find($s->user_id)) ? [$u, trim("{$s->first_name} {$s->last_name}"), $s->class?->name, $u->username ?: ($s->admission_number ?: $u->email)] : null)
            ->filter()->values()->all();
    }

    /** Parents of a class's students (or chosen parents); the slip names their children. */
    private function parents(?int $classId, array $ids): array
    {
        $q = Guardian::with(['students:id,first_name,class_id'])->whereNotNull('user_id');
        if ($ids) {
            $q->whereIn('id', $ids);
        } elseif ($classId) {
            $q->whereHas('students', fn ($s) => $s->where('students.class_id', $classId));
        }

        return $q->orderBy('first_name')->get()
            ->map(fn ($g) => ($u = User::find($g->user_id)) ? [$u, trim("{$g->first_name} {$g->last_name}"),
                'Parent of ' . $g->students->pluck('first_name')->implode(', '), $u->username ?: $u->email] : null)
            ->filter()->values()->all();
    }

    /** Staff and teachers (and leadership); chosen user ids, or everyone. */
    private function staff(array $userIds): array
    {
        $roles = ['principal', 'vice_principal', 'admin', 'teacher', 'class_teacher', 'subject_teacher', 'year_tutor', 'hod', 'staff', 'accountant',
            'librarian', 'driver', 'security', 'cleaner', 'caterer', 'nurse', 'housemaster', 'care_assistant', 'sen_facilitator', 'sen_therapist', 'counsellor'];
        $employeeIds = DB::table('staff')->whereNotNull('user_id')->pluck('employee_id', 'user_id')
            ->union(DB::table('teachers')->whereNotNull('user_id')->pluck('employee_id', 'user_id'));

        return User::whereIn('role', $roles)->where('status', 'active')
            ->when($userIds, fn ($q) => $q->whereIn('id', $userIds))
            ->orderBy('name')->get()
            ->map(fn ($u) => [$u, $u->name, \Illuminate\Support\Str::headline($u->role), $u->username ?: ($employeeIds[$u->id] ?? null) ?: $u->email])
            ->all();
    }
}
