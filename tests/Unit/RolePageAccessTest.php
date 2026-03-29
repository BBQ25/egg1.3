<?php

namespace Tests\Unit;

use App\Support\RolePageAccess;
use App\Models\User;
use Tests\TestCase;

class RolePageAccessTest extends TestCase
{
    public function test_owner_and_staff_can_access_machine_blueprint_page_key(): void
    {
        $owner = User::factory()->owner()->make();
        $staff = User::factory()->staff()->make();
        $customer = User::factory()->customer()->make();

        $this->assertTrue(RolePageAccess::canAccessPage($owner, 'machine-blueprint'));
        $this->assertTrue(RolePageAccess::canAccessPage($staff, 'machine-blueprint'));
        $this->assertFalse(RolePageAccess::canAccessPage($customer, 'machine-blueprint'));
    }

    public function test_owner_and_staff_can_access_inventory_page_key(): void
    {
        $owner = User::factory()->owner()->make();
        $staff = User::factory()->staff()->make();
        $customer = User::factory()->customer()->make();

        $this->assertTrue(RolePageAccess::canAccessPage($owner, 'inventory'));
        $this->assertTrue(RolePageAccess::canAccessPage($staff, 'inventory'));
        $this->assertFalse(RolePageAccess::canAccessPage($customer, 'inventory'));
    }

    public function test_owner_and_staff_can_access_egg_records_page_key(): void
    {
        $owner = User::factory()->owner()->make();
        $staff = User::factory()->staff()->make();
        $customer = User::factory()->customer()->make();

        $this->assertTrue(RolePageAccess::canAccessPage($owner, 'egg-records'));
        $this->assertTrue(RolePageAccess::canAccessPage($staff, 'egg-records'));
        $this->assertFalse(RolePageAccess::canAccessPage($customer, 'egg-records'));
    }

    public function test_owner_and_staff_can_access_production_reports_page_key(): void
    {
        $owner = User::factory()->owner()->make();
        $staff = User::factory()->staff()->make();
        $customer = User::factory()->customer()->make();

        $this->assertTrue(RolePageAccess::canAccessPage($owner, 'production-reports'));
        $this->assertTrue(RolePageAccess::canAccessPage($staff, 'production-reports'));
        $this->assertFalse(RolePageAccess::canAccessPage($customer, 'production-reports'));
    }

    public function test_owner_and_staff_can_access_notifications_page_key(): void
    {
        $owner = User::factory()->owner()->make();
        $staff = User::factory()->staff()->make();
        $customer = User::factory()->customer()->make();

        $this->assertTrue(RolePageAccess::canAccessPage($owner, 'notifications'));
        $this->assertTrue(RolePageAccess::canAccessPage($staff, 'notifications'));
        $this->assertFalse(RolePageAccess::canAccessPage($customer, 'notifications'));
    }

    public function test_owner_and_staff_can_access_validation_page_key(): void
    {
        $owner = User::factory()->owner()->make();
        $staff = User::factory()->staff()->make();
        $customer = User::factory()->customer()->make();

        $this->assertTrue(RolePageAccess::canAccessPage($owner, 'validation'));
        $this->assertTrue(RolePageAccess::canAccessPage($staff, 'validation'));
        $this->assertFalse(RolePageAccess::canAccessPage($customer, 'validation'));
    }

    public function test_customer_can_access_price_monitoring_page_key(): void
    {
        $owner = User::factory()->owner()->make();
        $customer = User::factory()->customer()->make();

        $this->assertFalse(RolePageAccess::canAccessPage($owner, 'price-monitoring'));
        $this->assertTrue(RolePageAccess::canAccessPage($customer, 'price-monitoring'));
    }
}
