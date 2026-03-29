<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuideCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_guides_and_see_all_tracks(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('guides.index'));

        $response->assertOk();
        $response->assertSee('Guide Center');
        $response->assertSee('Admin Setup');
        $response->assertSee('Owner Operations');
        $response->assertSee('Staff Operations');
        $response->assertSee('Customer Operations');
    }

    public function test_owner_only_sees_owner_track(): void
    {
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)->get(route('guides.index'));

        $response->assertOk();
        $response->assertSee('Owner Operations');
        $response->assertDontSee('Admin Setup');
        $response->assertDontSee('Staff Operations');
        $response->assertDontSee('Customer Operations');
    }

    public function test_staff_only_sees_staff_track(): void
    {
        $staff = User::factory()->create([
            'role' => UserRole::WORKER,
        ]);

        $response = $this->actingAs($staff)->get(route('guides.index'));

        $response->assertOk();
        $response->assertSee('Staff Operations');
        $response->assertDontSee('Admin Setup');
        $response->assertDontSee('Owner Operations');
        $response->assertDontSee('Customer Operations');
    }

    public function test_customer_only_sees_customer_track(): void
    {
        $customer = User::factory()->customer()->create();

        $response = $this->actingAs($customer)->get(route('guides.index'));

        $response->assertOk();
        $response->assertSee('Customer Operations');
        $response->assertDontSee('Admin Setup');
        $response->assertDontSee('Owner Operations');
        $response->assertDontSee('Staff Operations');
    }

    public function test_guest_is_redirected_from_guides(): void
    {
        $response = $this->get(route('guides.index'));

        $response->assertRedirect(route('login'));
    }
}
