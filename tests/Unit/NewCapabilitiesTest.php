<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\RoleCapabilityService;
use Tests\TestCase;

/** Capabilities the code checks exist (teacher.delete was refused for everyone), with defaults that keep today's access. */
class NewCapabilitiesTest extends TestCase
{
    private function user(string $role): User
    {
        $u = new User(['name' => $role, 'email' => "{$role}@x.test", 'role' => $role]);
        $u->id = crc32($role);

        return $u;
    }

    public function test_defaults(): void
    {
        $can = fn (string $role, string $cap) => RoleCapabilityService::userCan($this->user($role), $cap, null);
        foreach (['teacher.delete', 'teacher.update', 'student.update', 'payroll.manage', 'duty_roster.manage', 'lesson_notes.review', 'reports.view'] as $cap) {
            $this->assertTrue($can('school_admin', $cap), "school_admin: {$cap}");
        }
        foreach (['lesson_notes.write', 'ai.use', 'preschool_report.enter', 'elab.assign', 'livestream.host'] as $cap) {
            $this->assertTrue($can('teacher', $cap), "teacher: {$cap}");
            $this->assertTrue($can('class_teacher', $cap), "class_teacher: {$cap}");
        }
        $this->assertFalse($can('teacher', 'payroll.manage'));
        $this->assertFalse($can('teacher', 'lesson_notes.review'), 'teachers vet only notes sent to them');
        $this->assertFalse($can('hod', 'lesson_notes.review'), 'HODs vet the notes sent to them, not every note');
        $this->assertTrue($can('accountant', 'payroll.manage'));
        $this->assertTrue($can('accountant', 'requisitions.approve'));
        $this->assertFalse($can('accountant', 'lesson_notes.write'));
    }
}
