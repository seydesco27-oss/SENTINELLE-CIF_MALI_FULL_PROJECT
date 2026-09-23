<?php

namespace Tests\Unit;

use App\Support\AccessProfile;
use PHPUnit\Framework\TestCase;

class AccessProfileMatrixTest extends TestCase
{
    public function test_administrator_has_platform_and_governance_permissions(): void
    {
        $permissions = AccessProfile::permissions(AccessProfile::ADMIN);

        $this->assertContains('data.scope_platform', $permissions);
        $this->assertContains('user.manage', $permissions);
        $this->assertContains('engine.configure', $permissions);
        $this->assertContains('alert.decide', $permissions);
        $this->assertContains('demo.run', $permissions);
    }

    public function test_compliance_officer_has_caisse_compliance_permissions(): void
    {
        $permissions = AccessProfile::permissions(AccessProfile::COMPLIANCE_OFFICER);

        $this->assertContains('data.scope_caisse', $permissions);
        $this->assertContains('centif.manage', $permissions);
        $this->assertContains('ml.use', $permissions);
        $this->assertContains('audit.view', $permissions);
        $this->assertNotContains('user.manage', $permissions);
        $this->assertNotContains('demo.run', $permissions);
    }

    public function test_supervisor_is_limited_to_agency_operations(): void
    {
        $permissions = AccessProfile::permissions(AccessProfile::SUPERVISOR);

        $this->assertContains('data.scope_agency', $permissions);
        $this->assertContains('investigation.manage', $permissions);
        $this->assertContains('alert.escalate', $permissions);
        $this->assertNotContains('alert.decide', $permissions);
        $this->assertNotContains('centif.view', $permissions);
        $this->assertNotContains('ml.use', $permissions);
        $this->assertNotContains('audit.view', $permissions);
    }

    public function test_agent_can_operate_and_signal_inside_the_agency(): void
    {
        $permissions = AccessProfile::permissions(AccessProfile::AGENT);

        $this->assertSame('/dashboard', AccessProfile::defaultPath(AccessProfile::AGENT));
        $this->assertContains('nav.dashboard', $permissions);
        $this->assertContains('alert.view', $permissions);
        $this->assertContains('alert.signal', $permissions);
        $this->assertContains('tx.create', $permissions);
        $this->assertNotContains('client.create', $permissions);
        $this->assertNotContains('investigation.view', $permissions);
        $this->assertNotContains('ml.use', $permissions);
    }
}
