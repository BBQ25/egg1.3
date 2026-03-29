<?php

namespace Tests\Feature;

use App\Enums\UserRegistrationStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\Geofence;
use App\Support\UserPremises;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserOnboardingAndGeofenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_access_registration_page(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
        $response->assertSee('Create an account');
    }

    public function test_owner_registration_requires_complete_farm_details_with_geotag(): void
    {
        $response = $this->from(route('register'))->post(route('register.store'), [
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'address' => 'Poblacion, Sample City',
            'username' => 'juan_owner',
            'role' => UserRole::OWNER->value,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('register'));
        $response->assertSessionHasErrors([
            'farm_name',
            'farm_location',
            'farm_sitio',
            'farm_barangay',
            'farm_municipality',
            'farm_province',
            'farm_latitude',
            'farm_longitude',
        ]);
    }

    public function test_guest_registration_creates_pending_owner_and_farm_record(): void
    {
        $response = $this->post(route('register.store'), [
            'first_name' => 'Maria',
            'middle_name' => 'Lopez',
            'last_name' => 'Garcia',
            'address' => 'Sitio Uno, Barangay Dos, Sample Municipality',
            'username' => 'maria_owner',
            'role' => UserRole::OWNER->value,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'farm_name' => 'Garcia Poultry Farm',
            'farm_location' => 'Purok 4',
            'farm_sitio' => 'Sitio Uno',
            'farm_barangay' => 'Barangay Dos',
            'farm_municipality' => 'Sample Municipality',
            'farm_province' => 'Sample Province',
            'farm_latitude' => '10.1234567',
            'farm_longitude' => '123.7654321',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('users', [
            'username' => 'maria_owner',
            'registration_status' => UserRegistrationStatus::PENDING->value,
            'role' => UserRole::OWNER->value,
        ]);

        $user = User::query()->where('username', 'maria_owner')->firstOrFail();

        $this->assertDatabaseHas('farms', [
            'farm_name' => 'Garcia Poultry Farm',
            'owner_user_id' => $user->id,
            'barangay' => 'Barangay Dos',
            'municipality' => 'Sample Municipality',
            'province' => 'Sample Province',
        ]);
    }

    public function test_owner_registration_rejects_farm_location_outside_general_geofence(): void
    {
        Geofence::set(true, 10.3547270, 124.9659800, 1000);

        $response = $this->from(route('register'))->post(route('register.store'), [
            'first_name' => 'Outside',
            'middle_name' => 'Geo',
            'last_name' => 'Owner',
            'address' => 'Sample Address',
            'username' => 'outside_owner_geo',
            'role' => UserRole::OWNER->value,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'farm_name' => 'Outside Farm',
            'farm_location' => 'Purok 9',
            'farm_sitio' => 'Sitio Tres',
            'farm_barangay' => 'Barangay Tres',
            'farm_municipality' => 'Municipality Tres',
            'farm_province' => 'Province Tres',
            'farm_latitude' => '11.0000000',
            'farm_longitude' => '125.0000000',
        ]);

        $response->assertRedirect(route('register'));
        $response->assertSessionHasErrors([
            'farm_latitude',
            'farm_longitude',
        ]);

        $this->assertDatabaseMissing('users', [
            'username' => 'outside_owner_geo',
        ]);
    }

    public function test_pending_user_cannot_login(): void
    {
        User::factory()->create([
            'username' => 'pending_user',
            'password_hash' => 'password123',
            'role' => UserRole::CUSTOMER,
            'registration_status' => UserRegistrationStatus::PENDING,
            'approved_at' => null,
        ]);

        $response = $this->from(route('login'))->post(route('login.store'), [
            'username' => 'pending_user',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_admin_can_approve_pending_user_and_user_can_login(): void
    {
        $admin = User::factory()->admin()->create();
        $pendingUser = User::factory()->create([
            'username' => 'for_approval',
            'password_hash' => 'password123',
            'registration_status' => UserRegistrationStatus::PENDING,
            'approved_at' => null,
            'approved_by_user_id' => null,
        ]);

        $approveResponse = $this->actingAs($admin)->patch(route('admin.users.approve', $pendingUser));
        $approveResponse->assertRedirect(route('admin.users.index'));

        $pendingUser->refresh();
        $this->assertTrue($pendingUser->isApproved());
        $this->assertNotNull($pendingUser->approved_at);
        $this->assertSame($admin->id, $pendingUser->approved_by_user_id);

        $this->post(route('logout'));

        $loginResponse = $this->post(route('login.store'), [
            'username' => 'for_approval',
            'password' => 'password123',
        ]);

        $loginResponse->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($pendingUser);
    }

    public function test_admin_can_deny_pending_user_with_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $pendingUser = User::factory()->create([
            'username' => 'for_denial',
            'password_hash' => 'password123',
            'registration_status' => UserRegistrationStatus::PENDING,
            'approved_at' => null,
            'approved_by_user_id' => null,
        ]);

        $denyResponse = $this->actingAs($admin)->patch(route('admin.users.deny', $pendingUser), [
            'denial_reason' => 'Farm geotag and address are incomplete.',
        ]);
        $denyResponse->assertRedirect(route('admin.users.index'));

        $pendingUser->refresh();
        $this->assertTrue($pendingUser->isDenied());
        $this->assertSame('Farm geotag and address are incomplete.', $pendingUser->denial_reason);

        $this->post(route('logout'));

        $loginResponse = $this->from(route('login'))->post(route('login.store'), [
            'username' => 'for_denial',
            'password' => 'password123',
        ]);

        $loginResponse->assertRedirect(route('login'));
        $loginResponse->assertSessionHasErrors('username');
        $this->assertSame(
            __('auth.failed'),
            (string) session('errors')?->first('username')
        );
    }

    public function test_geofence_blocks_non_admin_login_outside_perimeter(): void
    {
        Geofence::set(true, 10.3156992, 123.8854366, 1000);

        User::factory()->create([
            'username' => 'outside_user',
            'password_hash' => 'password123',
            'role' => UserRole::CUSTOMER,
            'registration_status' => UserRegistrationStatus::APPROVED,
        ]);

        $response = $this->from(route('login'))->post(route('login.store'), [
            'username' => 'outside_user',
            'password' => 'password123',
            'geofence_latitude' => '10.5000000',
            'geofence_longitude' => '124.5000000',
        ]);

        $response->assertRedirect(route('geofence.restricted'));
        $response->assertSessionHas('geofence_attempted_latitude', 10.5);
        $response->assertSessionHas('geofence_attempted_longitude', 124.5);
        $this->assertGuest();

        $restrictedResponse = $this->get(route('geofence.restricted'));
        $restrictedResponse->assertOk();
        $restrictedResponse->assertSee('System Access Unavailable Outside Geofence');
    }

    public function test_geofence_allows_non_admin_login_inside_perimeter(): void
    {
        Geofence::set(true, 10.3156992, 123.8854366, 2000);

        $user = User::factory()->create([
            'username' => 'inside_user',
            'password_hash' => 'password123',
            'role' => UserRole::CUSTOMER,
            'registration_status' => UserRegistrationStatus::APPROVED,
        ]);

        $response = $this->post(route('login.store'), [
            'username' => 'inside_user',
            'password' => 'password123',
            'geofence_latitude' => '10.3161000',
            'geofence_longitude' => '123.8858000',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_polygon_geofence_allows_login_when_location_is_on_polygon_edge(): void
    {
        Geofence::saveZone(true, Geofence::SHAPE_POLYGON, [
            'vertices' => [
                [10.3546000, 124.9657000],
                [10.3549500, 124.9657000],
                [10.3549500, 124.9662000],
                [10.3546000, 124.9662000],
            ],
        ], null);

        $user = User::factory()->create([
            'username' => 'polygon_edge_user',
            'password_hash' => 'password123',
            'role' => UserRole::CUSTOMER,
            'registration_status' => UserRegistrationStatus::APPROVED,
        ]);

        $response = $this->post(route('login.store'), [
            'username' => 'polygon_edge_user',
            'password' => 'password123',
            'geofence_latitude' => '10.3549500',
            'geofence_longitude' => '124.9659800',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_geofence_middleware_logs_out_non_admin_when_session_has_no_location(): void
    {
        Geofence::set(true, 10.3156992, 123.8854366, 2000);

        $user = User::factory()->create([
            'role' => UserRole::CUSTOMER,
            'registration_status' => UserRegistrationStatus::APPROVED,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_geofence_middleware_redirects_user_to_restricted_page_when_session_location_is_outside(): void
    {
        Geofence::set(true, 10.3156992, 123.8854366, 1200);

        $user = User::factory()->create([
            'role' => UserRole::CUSTOMER,
            'registration_status' => UserRegistrationStatus::APPROVED,
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'geofence.latitude' => 10.5000000,
                'geofence.longitude' => 124.5000000,
            ])
            ->get(route('dashboard'));

        $response->assertRedirect(route('geofence.restricted'));
        $this->assertGuest();
    }

    public function test_admin_can_login_without_location_when_geofence_is_enabled(): void
    {
        Geofence::set(true, 10.3156992, 123.8854366, 2000);

        $admin = User::factory()->admin()->create([
            'username' => 'geo_admin',
            'password_hash' => 'password123',
            'registration_status' => UserRegistrationStatus::APPROVED,
        ]);

        $response = $this->post(route('login.store'), [
            'username' => 'geo_admin',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_admin_can_login_with_location_when_geofence_is_enabled(): void
    {
        Geofence::set(true, 10.3156992, 123.8854366, 2000);

        $admin = User::factory()->admin()->create([
            'username' => 'geo_admin_inside',
            'password_hash' => 'password123',
            'registration_status' => UserRegistrationStatus::APPROVED,
        ]);

        $response = $this->post(route('login.store'), [
            'username' => 'geo_admin_inside',
            'password' => 'password123',
            'geofence_latitude' => '10.3161000',
            'geofence_longitude' => '123.8858000',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_admin_can_access_dashboard_without_session_location_when_geofence_is_enabled(): void
    {
        Geofence::set(true, 10.3156992, 123.8854366, 2000);

        $admin = User::factory()->admin()->create([
            'registration_status' => UserRegistrationStatus::APPROVED,
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_user_premises_blocks_login_outside_user_zone_even_inside_general_perimeter(): void
    {
        Geofence::set(true, 10.3547270, 124.9659800, 8000);

        $user = User::factory()->create([
            'username' => 'premises_outside',
            'password_hash' => 'password123',
            'role' => UserRole::CUSTOMER,
            'registration_status' => UserRegistrationStatus::APPROVED,
        ]);

        UserPremises::saveZoneForUser($user, Geofence::SHAPE_CIRCLE, [
            'center_latitude' => 10.3547270,
            'center_longitude' => 124.9659800,
            'radius_meters' => 150,
        ], null);

        $response = $this->post(route('login.store'), [
            'username' => 'premises_outside',
            'password' => 'password123',
            'geofence_latitude' => '10.3600000',
            'geofence_longitude' => '124.9700000',
        ]);

        $response->assertRedirect(route('geofence.restricted'));
        $this->assertGuest();
    }

    public function test_user_premises_allows_login_inside_user_zone(): void
    {
        Geofence::set(true, 10.3547270, 124.9659800, 8000);

        $user = User::factory()->create([
            'username' => 'premises_inside',
            'password_hash' => 'password123',
            'role' => UserRole::CUSTOMER,
            'registration_status' => UserRegistrationStatus::APPROVED,
        ]);

        UserPremises::saveZoneForUser($user, Geofence::SHAPE_CIRCLE, [
            'center_latitude' => 10.3547270,
            'center_longitude' => 124.9659800,
            'radius_meters' => 500,
        ], null);

        $response = $this->post(route('login.store'), [
            'username' => 'premises_inside',
            'password' => 'password123',
            'geofence_latitude' => '10.3550000',
            'geofence_longitude' => '124.9662000',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_premises_polygon_allows_login_when_location_is_on_polygon_edge(): void
    {
        Geofence::set(true, 10.3547270, 124.9659800, 8000);

        $user = User::factory()->create([
            'username' => 'premises_polygon_edge',
            'password_hash' => 'password123',
            'role' => UserRole::CUSTOMER,
            'registration_status' => UserRegistrationStatus::APPROVED,
        ]);

        UserPremises::saveZoneForUser($user, Geofence::SHAPE_POLYGON, [
            'vertices' => [
                [10.3546000, 124.9657000],
                [10.3549500, 124.9657000],
                [10.3549500, 124.9662000],
                [10.3546000, 124.9662000],
            ],
        ], null);

        $response = $this->post(route('login.store'), [
            'username' => 'premises_polygon_edge',
            'password' => 'password123',
            'geofence_latitude' => '10.3549500',
            'geofence_longitude' => '124.9659800',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }
}
