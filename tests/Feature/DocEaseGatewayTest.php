<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocEaseGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_for_doc_ease_gateway(): void
    {
        config()->set('doc_ease.enabled', true);
        config()->set('doc_ease.allowed_roles', [UserRole::ADMIN->value]);

        $response = $this->get(route('legacy.doc-ease.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_admin_can_access_doc_ease_gateway_when_enabled(): void
    {
        config()->set('doc_ease.enabled', true);
        config()->set('doc_ease.allowed_roles', [UserRole::ADMIN->value]);
        config()->set('doc_ease.entrypoint', '/doc-ease/index.php');

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('legacy.doc-ease.index'));

        $response->assertOk();
        $response->assertSee('Doc-Ease Legacy Gateway');
        $response->assertSee('/doc-ease/index.php');
    }

    public function test_non_allowed_role_is_forbidden_for_doc_ease_gateway(): void
    {
        config()->set('doc_ease.enabled', true);
        config()->set('doc_ease.allowed_roles', [UserRole::ADMIN->value]);

        $customer = User::factory()->customer()->create();

        $response = $this->actingAs($customer)->get(route('legacy.doc-ease.index'));

        $response->assertForbidden();
    }

    public function test_doc_ease_gateway_returns_404_when_disabled(): void
    {
        config()->set('doc_ease.enabled', false);
        config()->set('doc_ease.allowed_roles', [UserRole::ADMIN->value]);

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('legacy.doc-ease.index'));

        $response->assertNotFound();
    }

    public function test_doc_ease_gateway_launch_redirects_to_entrypoint_when_present(): void
    {
        config()->set('doc_ease.enabled', true);
        config()->set('doc_ease.allowed_roles', [UserRole::ADMIN->value]);
        config()->set('doc_ease.entrypoint', '/doc-ease/index.php');
        config()->set('doc_ease.bridge.enabled', false);

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('legacy.doc-ease.launch'));

        $response->assertRedirect('/doc-ease/index.php');
    }

    public function test_doc_ease_gateway_launch_redirects_to_bridge_when_enabled(): void
    {
        config()->set('doc_ease.enabled', true);
        config()->set('doc_ease.allowed_roles', [UserRole::ADMIN->value]);
        config()->set('doc_ease.entrypoint', '/doc-ease/index.php');
        config()->set('doc_ease.bridge.enabled', true);
        config()->set('doc_ease.bridge.path', '/doc-ease/bridge-login.php');
        config()->set('doc_ease.bridge.secret', 'test-bridge-secret');
        config()->set('doc_ease.bridge.ttl_seconds', 90);

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('legacy.doc-ease.launch'));

        $location = (string) $response->headers->get('Location');
        $response->assertStatus(302);
        $this->assertSame('/doc-ease/bridge-login.php', (string) parse_url($location, PHP_URL_PATH));

        $query = [];
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('token', $query);
        $this->assertNotSame('', trim((string) $query['token']));
        $this->assertSame('/doc-ease/index.php', (string) ($query['next'] ?? ''));
    }

    public function test_doc_ease_gateway_launch_returns_404_when_bridge_endpoint_is_missing(): void
    {
        config()->set('doc_ease.enabled', true);
        config()->set('doc_ease.allowed_roles', [UserRole::ADMIN->value]);
        config()->set('doc_ease.entrypoint', '/doc-ease/index.php');
        config()->set('doc_ease.bridge.enabled', true);
        config()->set('doc_ease.bridge.path', '/doc-ease/missing-bridge.php');
        config()->set('doc_ease.bridge.secret', 'test-bridge-secret');

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('legacy.doc-ease.launch'));

        $response->assertNotFound();
    }

    public function test_doc_ease_gateway_launch_returns_503_when_bridge_secret_is_missing(): void
    {
        config()->set('doc_ease.enabled', true);
        config()->set('doc_ease.allowed_roles', [UserRole::ADMIN->value]);
        config()->set('doc_ease.entrypoint', '/doc-ease/index.php');
        config()->set('doc_ease.bridge.enabled', true);
        config()->set('doc_ease.bridge.path', '/doc-ease/bridge-login.php');
        config()->set('doc_ease.bridge.secret', '');

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('legacy.doc-ease.launch'));

        $response->assertStatus(503);
    }

    public function test_doc_ease_gateway_launch_returns_404_when_entrypoint_is_missing(): void
    {
        config()->set('doc_ease.enabled', true);
        config()->set('doc_ease.allowed_roles', [UserRole::ADMIN->value]);
        config()->set('doc_ease.entrypoint', '/doc-ease/missing-entrypoint.php');

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('legacy.doc-ease.launch'));

        $response->assertNotFound();
    }
}
