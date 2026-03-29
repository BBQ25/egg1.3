<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\MenuVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class SneatPageRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_sneat_route_serves_page_content(): void
    {
        $user = User::factory()->owner()->create();

        $response = $this->actingAs($user)->get('/app-email');

        $response->assertOk();
        $response->assertSee('<base href="/sneat/html/vertical-menu-template/" />', false);
        $response->assertSee('href="/app-chat"', false);
    }

    public function test_forms_page_is_served_after_move_to_forms_directory(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->get('/forms-basic-inputs');

        $response->assertOk();
        $response->assertSee('Demo: Basic Inputs - Forms', false);
        $response->assertSee('href="/forms-input-groups"', false);
    }

    public function test_legacy_html_route_redirects_to_clean_url(): void
    {
        $user = User::factory()->owner()->create();

        $response = $this->actingAs($user)->get('/app-email.html');

        $response->assertRedirect('/app-email');
    }

    public function test_legacy_php_route_redirects_to_clean_url(): void
    {
        $user = User::factory()->owner()->create();

        $response = $this->actingAs($user)->get('/app-email.php');

        $response->assertRedirect('/app-email');
    }

    public function test_legacy_routes_preserve_configured_app_base_path_in_redirects(): void
    {
        config([
            'app.url' => 'http://localhost',
            'app.base_path' => 'sumacot/egg1.3',
        ]);

        $user = User::factory()->owner()->create();

        $response = $this->actingAs($user)->get('/app-email.html');

        $response->assertRedirect('/sumacot/egg1.3/app-email');
    }

    public function test_login_page_uses_configured_app_base_path_for_pwa_and_route_links(): void
    {
        config([
            'app.url' => 'http://localhost',
            'app.base_path' => 'sumacot/egg1.3',
        ]);
        URL::forceRootUrl('http://localhost/sumacot/egg1.3');

        $html = (string) view('auth.login-cover', [
            'errors' => new ViewErrorBag(),
            'geofenceEnabled' => false,
        ])->render();

        $this->assertStringContainsString('href="/sumacot/egg1.3/manifest.webmanifest"', $html);
        $this->assertStringContainsString('href="http://localhost/sumacot/egg1.3/dashboard"', $html);
        $this->assertStringContainsString('href="/sumacot/egg1.3/sneat/assets/vendor/css/core.css"', $html);
    }

    public function test_clean_sneat_page_injects_logout_form_hook(): void
    {
        $user = User::factory()->owner()->create();

        $response = $this->actingAs($user)->get('/app-email');

        $response->assertOk();
        $response->assertSee('global-sneat-logout-form');
        $response->assertSee('auth-login-cover.html');
    }

    public function test_disabled_clean_page_redirects_to_dashboard(): void
    {
        $user = User::factory()->owner()->create();
        MenuVisibility::setDisabled(['app-email']);

        $response = $this->actingAs($user)->get('/app-email');

        $response->assertRedirect(route('dashboard'));
    }

    public function test_disabled_legacy_html_page_redirects_to_dashboard(): void
    {
        $user = User::factory()->owner()->create();
        MenuVisibility::setDisabled(['app-email']);

        $response = $this->actingAs($user)->get('/app-email.html');

        $response->assertRedirect(route('dashboard'));
    }

    public function test_disabled_legacy_php_page_redirects_to_dashboard(): void
    {
        $user = User::factory()->owner()->create();
        MenuVisibility::setDisabled(['app-email']);

        $response = $this->actingAs($user)->get('/app-email.php');

        $response->assertRedirect(route('dashboard'));
    }

    public function test_dashboard_injects_menu_visibility_script_when_pages_are_disabled(): void
    {
        $user = User::factory()->owner()->create();

        MenuVisibility::setDisabled(['app-email']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('var disabledPages = ["app-email"];', false);
        $response->assertSee('visibility-hidden-node', false);
    }

    public function test_clean_sneat_page_uses_shared_admin_sidebar_for_admin_user(): void
    {
        $admin = User::factory()->admin()->create();
        MenuVisibility::setDisabled([]);

        $response = $this->actingAs($admin)->get('/app-email');

        $response->assertOk();
        $response->assertSee('Administration');
        $response->assertSee('User List');
        $response->assertSee('Settings');
        $response->assertSee(route('admin.settings.edit'), false);
    }

    public function test_virtual_front_pages_key_blocks_matching_clean_slug(): void
    {
        $user = User::factory()->owner()->create();
        MenuVisibility::setDisabled(['front-pages-landing-page']);

        $response = $this->actingAs($user)->get('/landing-page');

        $response->assertRedirect(route('dashboard'));
    }

    public function test_customer_is_redirected_from_restricted_sneat_page(): void
    {
        $customer = User::factory()->customer()->create();
        MenuVisibility::setDisabled([]);

        $response = $this->actingAs($customer)->get('/app-email');

        $response->assertRedirect(route('dashboard'));
    }

    public function test_customer_can_access_customer_allowed_page(): void
    {
        $customer = User::factory()->customer()->create();
        MenuVisibility::setDisabled([]);

        $response = $this->actingAs($customer)->get('/app-ecommerce-product-list');

        $response->assertOk();
    }

    public function test_staff_can_access_operational_page(): void
    {
        $staff = User::factory()->staff()->create();
        MenuVisibility::setDisabled([]);

        $response = $this->actingAs($staff)->get('/app-logistics-dashboard');

        $response->assertOk();
    }

    public function test_staff_is_redirected_from_restricted_page(): void
    {
        $staff = User::factory()->staff()->create();
        MenuVisibility::setDisabled([]);

        $response = $this->actingAs($staff)->get('/app-access-roles');

        $response->assertRedirect(route('dashboard'));
    }

    public function test_owner_can_access_owner_operational_page(): void
    {
        $owner = User::factory()->owner()->create();
        MenuVisibility::setDisabled([]);

        $response = $this->actingAs($owner)->get('/app-ecommerce-order-list');

        $response->assertOk();
    }

    public function test_owner_is_redirected_from_restricted_admin_security_page(): void
    {
        $owner = User::factory()->owner()->create();
        MenuVisibility::setDisabled([]);

        $response = $this->actingAs($owner)->get('/app-access-roles');

        $response->assertRedirect(route('dashboard'));
    }
}
