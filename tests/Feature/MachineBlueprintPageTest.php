<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MachineBlueprintPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_machine_blueprint_page_is_accessible_to_admin_owner_and_staff(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->create([
            'role' => UserRole::WORKER,
        ]);

        $adminResponse = $this->actingAs($admin)->get(route('machine-blueprint.index'));
        $ownerResponse = $this->actingAs($owner)->get(route('machine-blueprint.index'));
        $staffResponse = $this->actingAs($staff)->get(route('machine-blueprint.index'));

        $adminResponse->assertOk();
        $adminResponse->assertSee('Automated Egg Weighing and Sorting Device Blueprint');
        $adminResponse->assertSee('Machine Blueprint');

        $ownerResponse->assertOk();
        $ownerResponse->assertSee('Automated Egg Weighing and Sorting Device Blueprint');
        $ownerResponse->assertSee('Machine Blueprint');

        $staffResponse->assertOk();
        $staffResponse->assertSee('Automated Egg Weighing and Sorting Device Blueprint');
        $staffResponse->assertSee('Machine Blueprint');
    }

    public function test_machine_blueprint_page_is_forbidden_for_customer(): void
    {
        $customer = User::factory()->customer()->create();

        $response = $this->actingAs($customer)->get(route('machine-blueprint.index'));

        $response->assertForbidden();
    }

    public function test_machine_blueprint_sidebar_menu_is_visible_to_owner_and_staff_only(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->create([
            'role' => UserRole::WORKER,
        ]);
        $customer = User::factory()->customer()->create();

        $ownerResponse = $this->actingAs($owner)->get(route('machine-blueprint.index'));
        $staffResponse = $this->actingAs($staff)->get(route('machine-blueprint.index'));
        $customerDashboardResponse = $this->actingAs($customer)->get(route('dashboard'));

        $ownerResponse->assertSee(route('machine-blueprint.index'));
        $ownerResponse->assertSee('Machine Blueprint');

        $staffResponse->assertSee(route('machine-blueprint.index'));
        $staffResponse->assertSee('Machine Blueprint');

        $customerDashboardResponse->assertDontSee(route('machine-blueprint.index'));
        $customerDashboardResponse->assertDontSee('Machine Blueprint');
    }
}
