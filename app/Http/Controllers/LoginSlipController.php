<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Login slips: admins reset students' passwords (one student, a selection, or a whole
 * class) and get back the new details to print or hand out. Passwords are stored
 * hashed, so a slip is the only time the new password is shown.
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

    /** POST /students/login-slips {class_id? , student_ids?: []} */
    public function generate(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'user.manage')) {
            return $denied;
        }
        $data = $request->validate([
            'class_id' => 'nullable|integer|exists:classes,id',
            'student_ids' => 'nullable|array|max:1000',
            'student_ids.*' => 'integer',
        ]);
        if (empty($data['class_id']) && empty($data['student_ids'])) {
            return response()->json(['message' => 'Choose a class or students.'], 422);
        }

        $students = Student::with('class:id,name')
            ->when(! empty($data['class_id']), fn ($q) => $q->where('class_id', $data['class_id']))
            ->when(! empty($data['student_ids']), fn ($q) => $q->whereIn('id', $data['student_ids']))
            ->whereNotNull('user_id')
            ->orderBy('first_name')->orderBy('last_name')->get();

        $slips = [];
        DB::transaction(function () use ($students, &$slips) {
            foreach ($students as $s) {
                $user = User::find($s->user_id);
                if (! $user) {
                    continue;
                }
                $password = $this->password();
                $user->forceFill(['password' => Hash::make($password)])->save();
                $user->tokens()->delete();   // sign out old sessions
                $slips[] = [
                    'student_id' => $s->id,
                    'name' => trim("{$s->first_name} {$s->last_name}"),
                    'class' => $s->class?->name,
                    'admission_number' => $s->admission_number,
                    'username' => $user->username ?: $s->admission_number,
                    'email' => $user->email,
                    'password' => $password,
                ];
            }
        });

        $sub = null;
        try {
            $sub = tenant()?->subdomain;
        } catch (\Throwable) {
        }
        $school = \App\Models\School::first();

        return response()->json([
            'school' => $school?->name,
            'login_url' => $sub ? 'https://' . $sub . '.' . config('app.root_domain', 'compasse.net') . '/login' : null,
            'slips' => $slips,
            'message' => count($slips) . ' password(s) reset. Print or download the slips now — they are not shown again.',
        ]);
    }
}
