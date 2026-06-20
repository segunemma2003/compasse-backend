<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserDeletionService
{
    /**
     * Archive a user and linked profile records (soft delete).
     * Historical content remains; login and mutations are blocked.
     */
    public function archiveUser(User $user): void
    {
        if ($user->role === 'super_admin') {
            throw new \RuntimeException('Cannot delete super admin user');
        }

        DB::transaction(function () use ($user) {
            $this->revokeTokens($user);

            Student::where('user_id', $user->id)->each(fn (Student $s) => $this->archiveStudentRecord($s, false));
            Teacher::where('user_id', $user->id)->each(fn (Teacher $t) => $this->archiveTeacherRecord($t, false));
            Staff::where('user_id', $user->id)->each(fn (Staff $s) => $this->archiveStaffRecord($s, false));

            $user->update(['status' => 'inactive']);
            $user->delete();
        });
    }

    public function archiveStudent(Student $student): void
    {
        DB::transaction(function () use ($student) {
            $this->archiveStudentRecord($student);

            $user = $student->user;
            if ($user) {
                $this->archiveUserRecord($user);
            }
        });
    }

    public function archiveTeacher(Teacher $teacher): void
    {
        DB::transaction(function () use ($teacher) {
            $this->archiveTeacherRecord($teacher);

            $user = $teacher->user;
            if ($user) {
                $this->archiveUserRecord($user);
            }
        });
    }

    public function archiveStaff(Staff $staff): void
    {
        DB::transaction(function () use ($staff) {
            $this->archiveStaffRecord($staff);

            $user = $staff->user;
            if ($user) {
                $this->archiveUserRecord($user);
            }
        });
    }

    /**
     * Permanently delete a user and linked profile records.
     */
    public function forceDeleteUser(User $user): void
    {
        if ($user->role === 'super_admin') {
            throw new \RuntimeException('Cannot permanently delete super admin user');
        }

        DB::transaction(function () use ($user) {
            $this->revokeTokens($user);

            Student::withTrashed()->where('user_id', $user->id)->each(
                fn (Student $s) => $this->forceDeleteStudentRecord($s)
            );
            Teacher::withTrashed()->where('user_id', $user->id)->each(
                fn (Teacher $t) => $this->forceDeleteTeacherRecord($t)
            );
            Staff::withTrashed()->where('user_id', $user->id)->each(
                fn (Staff $s) => $this->forceDeleteStaffRecord($s)
            );

            $user->forceDelete();
        });
    }

    public function forceDeleteStudent(Student $student): void
    {
        DB::transaction(function () use ($student) {
            $user = $student->user()->withTrashed()->first();
            $this->forceDeleteStudentRecord($student);

            if ($user) {
                $this->forceDeleteUserRecord($user);
            }
        });
    }

    public function forceDeleteTeacher(Teacher $teacher): void
    {
        DB::transaction(function () use ($teacher) {
            $user = $teacher->user()->withTrashed()->first();
            $this->forceDeleteTeacherRecord($teacher);

            if ($user) {
                $this->forceDeleteUserRecord($user);
            }
        });
    }

    public function forceDeleteStaff(Staff $staff): void
    {
        DB::transaction(function () use ($staff) {
            $user = $staff->user()->withTrashed()->first();
            $this->forceDeleteStaffRecord($staff);

            if ($user) {
                $this->forceDeleteUserRecord($user);
            }
        });
    }

    /**
     * Permanently remove users archived 30+ days ago.
     */
    public function purgeExpiredArchivedUsers(): int
    {
        $cutoff = now()->subDays(30);
        $count  = 0;

        User::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->where('role', '!=', 'super_admin')
            ->orderBy('id')
            ->chunkById(50, function ($users) use (&$count) {
                foreach ($users as $user) {
                    try {
                        $this->forceDeleteUser($user);
                        $count++;
                    } catch (\Throwable $e) {
                        Log::warning('purgeExpiredArchivedUsers: failed to purge user', [
                            'user_id' => $user->id,
                            'error'   => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $count;
    }

    public function restoreUser(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->restore();
            $user->update(['status' => 'active']);

            Student::onlyTrashed()->where('user_id', $user->id)->each(function (Student $s) {
                $s->restore();
                $s->update(['status' => 'active']);
            });
            Teacher::onlyTrashed()->where('user_id', $user->id)->each(function (Teacher $t) {
                $t->restore();
                $t->update(['status' => 'active']);
            });
            Staff::onlyTrashed()->where('user_id', $user->id)->each(function (Staff $s) {
                $s->restore();
                $s->update(['status' => 'active']);
            });
        });
    }

    protected function archiveUserRecord(User $user): void
    {
        if ($user->trashed()) {
            return;
        }

        $this->revokeTokens($user);
        $user->update(['status' => 'inactive']);
        $user->delete();
    }

    protected function archiveStudentRecord(Student $student, bool $updateStatus = true): void
    {
        if ($student->trashed()) {
            return;
        }

        if ($updateStatus) {
            $student->update(['status' => 'inactive']);
        }
        $student->delete();
    }

    protected function archiveTeacherRecord(Teacher $teacher, bool $updateStatus = true): void
    {
        if ($teacher->trashed()) {
            return;
        }

        if ($updateStatus) {
            $teacher->update(['status' => 'inactive']);
        }
        $teacher->delete();
    }

    protected function archiveStaffRecord(Staff $staff, bool $updateStatus = true): void
    {
        if ($staff->trashed()) {
            return;
        }

        if ($updateStatus) {
            $staff->update(['status' => 'inactive']);
        }
        $staff->delete();
    }

    protected function forceDeleteUserRecord(User $user): void
    {
        if ($user->role === 'super_admin') {
            return;
        }

        $this->revokeTokens($user);
        $user->forceDelete();
    }

    protected function forceDeleteStudentRecord(Student $student): void
    {
        $student->forceDelete();
    }

    protected function forceDeleteTeacherRecord(Teacher $teacher): void
    {
        $teacher->forceDelete();
    }

    protected function forceDeleteStaffRecord(Staff $staff): void
    {
        $staff->forceDelete();
    }

    protected function revokeTokens(User $user): void
    {
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }
    }
}
