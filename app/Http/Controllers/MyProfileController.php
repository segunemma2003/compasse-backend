<?php

namespace App\Http\Controllers;

use App\Models\Guardian;
use App\Models\Staff;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * My Profile: staff (teaching and non-teaching) and parents keep their own personal details up to date.
 * Only personal fields: names, login email, role, job and pay details stay with the school admin.
 */
class MyProfileController extends Controller
{
    /** Fields each kind of person may change on their own record. */
    private const EDITABLE = [
        'teacher' => ['phone', 'address', 'date_of_birth', 'gender', 'qualification', 'specialization', 'bio',
            'emergency_contact_name', 'emergency_contact_phone', 'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship'],
        'staff' => ['phone', 'address', 'date_of_birth', 'gender',
            'emergency_contact_name', 'emergency_contact_phone', 'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship'],
        'guardian' => ['phone', 'address', 'occupation', 'employer', 'emergency_contact'],
    ];

    private const RULES = [
        'phone' => 'nullable|string|max:30', 'address' => 'nullable|string|max:500', 'date_of_birth' => 'nullable|date|before:today',
        'gender' => 'nullable|in:male,female,other', 'qualification' => 'nullable|string|max:255', 'specialization' => 'nullable|string|max:255',
        'bio' => 'nullable|string|max:2000', 'emergency_contact_name' => 'nullable|string|max:255', 'emergency_contact_phone' => 'nullable|string|max:30',
        'next_of_kin_name' => 'nullable|string|max:255', 'next_of_kin_phone' => 'nullable|string|max:30', 'next_of_kin_relationship' => 'nullable|string|max:100',
        'occupation' => 'nullable|string|max:255', 'employer' => 'nullable|string|max:255', 'emergency_contact' => 'nullable|string|max:255',
    ];

    /** GET /users/me/profile */
    public function show(Request $request): JsonResponse
    {
        [$kind, $record] = $this->recordFor($request);
        if (! $record) {
            return response()->json(['kind' => null, 'profile' => null, 'editable' => [],
                'message' => 'There is no staff or parent record linked to your account. Ask the school administrator to update your details.']);
        }
        $fields = $this->editableFields($kind, $record);

        return response()->json([
            'kind' => $kind,
            'profile' => array_merge(
                ['name' => trim("{$record->first_name} {$record->last_name}"), 'email' => $record->email ?? $request->user()->email],
                collect($fields)->mapWithKeys(fn ($f) => [$f => $f === 'date_of_birth' && $record->$f ? substr((string) $record->$f, 0, 10) : $record->$f])->all()
            ),
            'editable' => $fields,
        ]);
    }

    /** PUT /users/me/profile */
    public function update(Request $request): JsonResponse
    {
        [$kind, $record] = $this->recordFor($request);
        if (! $record) {
            return response()->json(['error' => 'There is no staff or parent record linked to your account.'], 404);
        }
        $fields = $this->editableFields($kind, $record);
        $data = $request->validate(array_intersect_key(self::RULES, array_flip($fields)));
        $record->fill(array_intersect_key($data, array_flip($fields)))->save();
        // Keep the login account's phone in step where it has one.
        $user = $request->user();
        if (array_key_exists('phone', $data) && Schema::hasColumn('users', 'phone')) {
            $user->forceFill(['phone' => $data['phone']])->save();
        }

        return $this->show($request)->setStatusCode(200);
    }

    /** @return array{0: ?string, 1: ?\Illuminate\Database\Eloquent\Model} */
    private function recordFor(Request $request): array
    {
        $uid = $request->user()?->id;
        if (! $uid) {
            return [null, null];
        }
        if ($t = Teacher::where('user_id', $uid)->first()) {
            return ['teacher', $t];
        }
        if (Schema::hasTable('staff') && ($s = Staff::where('user_id', $uid)->first())) {
            return ['staff', $s];
        }
        if ($g = Guardian::where('user_id', $uid)->first()) {
            return ['guardian', $g];
        }

        return [null, null];
    }

    /** The kind's fields that actually exist on this school's table. */
    private function editableFields(string $kind, $record): array
    {
        $table = $record->getTable();

        return array_values(array_filter(self::EDITABLE[$kind], fn ($f) => Schema::hasColumn($table, $f)));
    }
}
