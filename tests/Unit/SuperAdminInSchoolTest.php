<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\RoleCapabilityService;
use App\Support\UserEffectiveRoles;
use Tests\TestCase;

/** "Open school": the Compasse super admin inside a school can do everything its administrator can. */
class SuperAdminInSchoolTest extends TestCase
{
    public function test_super_admin_has_every_capability_and_counts_as_school_admin(): void
    {
        $u = new User(['name' => 'Compasse Super Admin', 'email' => 'compasse-support@compasse.net', 'role' => 'super_admin']);
        $u->id = 987654;

        $all = array_keys(RoleCapabilityService::allCapabilities(null));
        foreach ($all as $cap) {
            $this->assertTrue(RoleCapabilityService::userCan($u, $cap, null), $cap);
        }
        $this->assertEqualsCanonicalizing($all, RoleCapabilityService::capabilitiesFor($u, null));
        $this->assertFalse(RoleCapabilityService::userCan($u, 'no.such.capability', null), 'unknown capabilities stay refused');

        $roles = UserEffectiveRoles::forUser($u);
        $this->assertContains('school_admin', $roles);
        $this->assertContains('admin', $roles);
    }
}
