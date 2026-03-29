<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocEaseLaravelDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_for_doc_ease_laravel_dashboard(): void
    {
        config()->set('doc_ease.enabled', true);
        config()->set('doc_ease.allowed_roles', [UserRole::ADMIN->value]);

        $response = $this->get(route('doc-ease.dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_admin_can_access_doc_ease_laravel_dashboard(): void
    {
        config()->set('doc_ease.enabled', true);
        config()->set('doc_ease.allowed_roles', [UserRole::ADMIN->value]);

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('doc-ease.dashboard'));

        $response->assertOk();
        $response->assertSee('Laravelized Doc-Ease Dashboard');
    }

    public function test_non_allowed_role_is_forbidden_for_doc_ease_laravel_dashboard(): void
    {
        config()->set('doc_ease.enabled', true);
        config()->set('doc_ease.allowed_roles', [UserRole::ADMIN->value]);

        $customer = User::factory()->customer()->create();

        $response = $this->actingAs($customer)->get(route('doc-ease.dashboard'));

        $response->assertForbidden();
    }

    public function test_doc_ease_laravel_dashboard_returns_404_when_disabled(): void
    {
        config()->set('doc_ease.enabled', false);
        config()->set('doc_ease.allowed_roles', [UserRole::ADMIN->value]);

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('doc-ease.dashboard'));

        $response->assertNotFound();
    }
}

