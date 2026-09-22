<?php

namespace Tests\Unit;

use App\Support\AccessProfile;
use PHPUnit\Framework\TestCase;

class AccessProfileTest extends TestCase
{
    public function test_agent_has_an_operational_read_only_workspace(): void
    {
        $permissions = AccessProfile::permissions(AccessProfile::AGENT);

        $this->assertSame('/clients', AccessProfile::defaultPath(AccessProfile::AGENT));
        $this->assertContains('clients', $permissions);
        $this->assertContains('accounts', $permissions);
        $this->assertContains('transactions', $permissions);
        $this->assertNotContains('alerts', $permissions);
        $this->assertNotContains('assist', $permissions);
        $this->assertNotContains('audit', $permissions);
    }

    public function test_supervisor_cannot_access_ml_administration_or_audit(): void
    {
        $permissions = AccessProfile::permissions(AccessProfile::SUPERVISOR);

        $this->assertContains('dashboard', $permissions);
        $this->assertContains('alerts', $permissions);
        $this->assertContains('assist', $permissions);
        $this->assertNotContains('ml', $permissions);
        $this->assertNotContains('audit', $permissions);
    }

    public function test_compliance_officer_has_the_complete_compliance_workspace(): void
    {
        $permissions = AccessProfile::permissions(AccessProfile::COMPLIANCE_OFFICER);

        $this->assertContains('screening', $permissions);
        $this->assertContains('ml', $permissions);
        $this->assertContains('audit', $permissions);
        $this->assertSame('/dashboard', AccessProfile::defaultPath(AccessProfile::COMPLIANCE_OFFICER));
    }
}
