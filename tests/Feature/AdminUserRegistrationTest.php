<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Farm;
use App\Models\FarmPremisesZone;
use App\Models\UserPremisesZone;
use App\Models\User;
use App\Support\Geofence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_the_user_registration_page(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.users.create'));

        $response->assertRedirect(route('admin.users.index', ['open' => 'create']));
    }

    public function test_admin_can_access_the_user_list_page(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['username' => 'sample_user']);

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertOk();
        $response->assertSee('sample_user');
    }

    public function test_non_admin_user_cannot_access_the_user_registration_page(): void
    {
        $user = User::factory()->create(['role' => UserRole::CUSTOMER]);

        $response = $this->actingAs($user)->get(route('admin.users.create'));

        $response->assertForbidden();
    }

    public function test_admin_can_register_a_user_with_supported_roles(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'full_name' => 'Farmer One',
            'username' => 'farmer_one',
            'role' => UserRole::WORKER->value,
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('users', [
            'username' => 'farmer_one',
            'role' => UserRole::WORKER->value,
        ]);

        $createdUser = User::query()->where('username', 'farmer_one')->firstOrFail();
        $this->assertTrue(Hash::check('secret1234', $createdUser->password_hash));
    }

    public function test_user_index_can_render_add_user_modal_when_query_flag_is_set(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.users.index', ['open' => 'create']));

        $response->assertOk();
        $response->assertSee('Add User');
        $response->assertSee('addUserModal');
        $response->assertSee('const shouldOpenCreateModal = true;');
    }

    public function test_user_index_returns_ajax_payload_for_live_search(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['username' => 'find_me_live']);
        User::factory()->create(['username' => 'hide_me_live']);

        $response = $this->actingAs($admin)->get(route('admin.users.index', [
            'q' => 'find_me_live',
            'ajax' => 1,
        ]));

        $response->assertOk();
        $response->assertJsonStructure(['table_rows_html', 'table_footer_html']);

        $payload = $response->json();
        $this->assertIsArray($payload);
        $this->assertStringContainsString('find_me_live', (string) ($payload['table_rows_html'] ?? ''));
        $this->assertStringNotContainsString('hide_me_live', (string) ($payload['table_rows_html'] ?? ''));
    }

    public function test_failed_create_validation_redirects_back_to_modal_entrypoint(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this
            ->actingAs($admin)
            ->from(route('admin.users.index', ['open' => 'create']))
            ->post(route('admin.users.store'), [
                'full_name' => '',
                'username' => '',
                'role' => UserRole::WORKER->value,
                'password' => 'short',
                'password_confirmation' => 'short',
            ]);

        $response->assertRedirect(route('admin.users.index', ['open' => 'create']));
        $response->assertSessionHasErrors(['full_name', 'username', 'password']);
    }

    public function test_admin_can_edit_a_user_profile_and_password(): void
    {
        $admin = User::factory()->admin()->create();
        $managedUser = User::factory()->create([
            'username' => 'before_edit',
            'full_name' => 'Before Edit',
            'role' => UserRole::CUSTOMER,
            'password_hash' => 'old-password',
        ]);

        $response = $this->actingAs($admin)->put(route('admin.users.update', $managedUser), [
            'full_name' => 'After Edit',
            'username' => 'after_edit',
            'role' => UserRole::OWNER->value,
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ]);

        $response->assertRedirect(route('admin.users.edit', $managedUser));
        $response->assertSessionHas('status');

        $managedUser->refresh();

        $this->assertSame('After Edit', $managedUser->full_name);
        $this->assertSame('after_edit', $managedUser->username);
        $this->assertSame(UserRole::OWNER, $managedUser->role);
        $this->assertTrue(Hash::check('new-secret-123', $managedUser->password_hash));
    }

    public function test_admin_can_deactivate_and_reactivate_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $managedUser = User::factory()->create(['is_active' => true]);

        $deactivateResponse = $this->actingAs($admin)->patch(route('admin.users.deactivate', $managedUser));
        $deactivateResponse->assertRedirect(route('admin.users.index'));

        $managedUser->refresh();
        $this->assertFalse($managedUser->is_active);
        $this->assertNotNull($managedUser->deactivated_at);

        $reactivateResponse = $this->actingAs($admin)->patch(route('admin.users.reactivate', $managedUser));
        $reactivateResponse->assertRedirect(route('admin.users.index'));

        $managedUser->refresh();
        $this->assertTrue($managedUser->is_active);
        $this->assertNull($managedUser->deactivated_at);
    }

    public function test_admin_cannot_deactivate_own_account(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->patch(route('admin.users.deactivate', $admin));

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('error');
        $admin->refresh();
        $this->assertTrue($admin->is_active);
    }

    public function test_admin_can_bulk_deactivate_and_reactivate_users(): void
    {
        $admin = User::factory()->admin()->create();
        $userOne = User::factory()->create(['is_active' => true]);
        $userTwo = User::factory()->create(['is_active' => true]);

        $deactivateResponse = $this->actingAs($admin)->post(route('admin.users.bulk'), [
            'bulk_action' => 'deactivate',
            'user_ids' => [$userOne->id, $userTwo->id],
            'current_password' => 'password',
        ]);

        $deactivateResponse->assertRedirect(route('admin.users.index'));
        $deactivateResponse->assertSessionHas('status');

        $userOne->refresh();
        $userTwo->refresh();
        $this->assertFalse($userOne->is_active);
        $this->assertFalse($userTwo->is_active);

        $reactivateResponse = $this->actingAs($admin)->post(route('admin.users.bulk'), [
            'bulk_action' => 'reactivate',
            'user_ids' => [$userOne->id, $userTwo->id],
            'current_password' => 'password',
        ]);

        $reactivateResponse->assertRedirect(route('admin.users.index'));
        $reactivateResponse->assertSessionHas('status');

        $userOne->refresh();
        $userTwo->refresh();
        $this->assertTrue($userOne->is_active);
        $this->assertTrue($userTwo->is_active);
    }

    public function test_admin_can_bulk_change_role_and_self_is_skipped(): void
    {
        $admin = User::factory()->admin()->create();
        $userOne = User::factory()->create(['role' => UserRole::CUSTOMER]);
        $userTwo = User::factory()->create(['role' => UserRole::CUSTOMER]);

        $response = $this->actingAs($admin)->post(route('admin.users.bulk'), [
            'bulk_action' => 'change_role',
            'role' => UserRole::WORKER->value,
            'user_ids' => [$admin->id, $userOne->id, $userTwo->id],
            'current_password' => 'password',
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('status');

        $admin->refresh();
        $userOne->refresh();
        $userTwo->refresh();

        $this->assertSame(UserRole::ADMIN, $admin->role);
        $this->assertSame(UserRole::WORKER, $userOne->role);
        $this->assertSame(UserRole::WORKER, $userTwo->role);
    }

    public function test_admin_bulk_action_requires_current_password(): void
    {
        $admin = User::factory()->admin()->create();
        $userOne = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($admin)->post(route('admin.users.bulk'), [
            'bulk_action' => 'deactivate',
            'user_ids' => [$userOne->id],
            'current_password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors(['current_password']);
        $userOne->refresh();
        $this->assertTrue($userOne->is_active);
    }

    public function test_admin_can_save_user_premises_zone(): void
    {
        $admin = User::factory()->admin()->create();
        $managedUser = User::factory()->create([
            'role' => UserRole::CUSTOMER,
            'username' => 'premises_user',
        ]);

        \App\Support\Geofence::set(true, 10.3547270, 124.9659800, 8000);

        $response = $this->actingAs($admin)->put(route('admin.users.update', $managedUser), [
            'full_name' => $managedUser->full_name,
            'username' => $managedUser->username,
            'role' => $managedUser->role->value,
            'user_premises_enabled' => '1',
            'user_premises_shape_type' => 'POLYGON',
            'user_premises_geometry' => json_encode([
                'vertices' => [
                    [10.3547000, 124.9659000],
                    [10.3549000, 124.9664000],
                    [10.3543000, 124.9663000],
                ],
            ]),
        ]);

        $response->assertRedirect(route('admin.users.edit', $managedUser));
        $response->assertSessionHas('status');

        $zone = UserPremisesZone::query()->where('user_id', $managedUser->id)->first();
        $this->assertNotNull($zone);
        $this->assertSame('POLYGON', $zone->shape_type);
        $this->assertTrue($zone->is_active);
    }

    public function test_admin_can_update_owner_farm_location_information(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create([
            'role' => UserRole::OWNER,
            'username' => 'owner_geo',
        ]);

        $farm = Farm::query()->create([
            'farm_name' => 'Owner Farm A',
            'location' => 'Purok 1',
            'sitio' => 'Sitio Uno',
            'barangay' => 'Barangay Uno',
            'municipality' => 'Municipality Uno',
            'province' => 'Province Uno',
            'latitude' => null,
            'longitude' => null,
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->put(route('admin.users.update', $owner), [
            'full_name' => $owner->full_name,
            'username' => $owner->username,
            'role' => $owner->role->value,
            'farm_updates' => [
                [
                    'id' => $farm->id,
                    'latitude' => '10.3547270',
                    'longitude' => '124.9659800',
                ],
            ],
        ]);

        $response->assertRedirect(route('admin.users.edit', $owner));
        $response->assertSessionHas('status');

        $farm->refresh();
        $this->assertSame('10.3547270', number_format((float) $farm->latitude, 7, '.', ''));
        $this->assertSame('124.9659800', number_format((float) $farm->longitude, 7, '.', ''));
    }

    public function test_admin_cannot_set_owner_farm_location_outside_general_geofence(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create([
            'role' => UserRole::OWNER,
            'username' => 'owner_geo_outside',
        ]);

        $farm = Farm::query()->create([
            'farm_name' => 'Owner Farm B',
            'location' => 'Purok 2',
            'sitio' => 'Sitio Dos',
            'barangay' => 'Barangay Dos',
            'municipality' => 'Municipality Dos',
            'province' => 'Province Dos',
            'latitude' => null,
            'longitude' => null,
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);

        Geofence::set(true, 10.3547270, 124.9659800, 1000);

        $response = $this->actingAs($admin)->put(route('admin.users.update', $owner), [
            'full_name' => $owner->full_name,
            'username' => $owner->username,
            'role' => $owner->role->value,
            'farm_updates' => [
                [
                    'id' => $farm->id,
                    'latitude' => '11.0000000',
                    'longitude' => '125.0000000',
                ],
            ],
        ]);

        $response->assertSessionHasErrors([
            'farm_updates.0.latitude',
            'farm_updates.0.longitude',
        ]);

        $farm->refresh();
        $this->assertNull($farm->latitude);
        $this->assertNull($farm->longitude);
    }

    public function test_owner_farm_fence_can_be_saved_inside_general_geofence(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create([
            'role' => UserRole::OWNER,
            'username' => 'owner_fence_valid',
        ]);

        $farm = Farm::query()->create([
            'farm_name' => 'Fence Farm One',
            'location' => 'Purok Uno',
            'barangay' => 'Barangay Uno',
            'municipality' => 'Bontoc',
            'province' => 'Southern Leyte',
            'latitude' => 10.3547270,
            'longitude' => 124.9659800,
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);

        Geofence::set(true, 10.3547270, 124.9659800, 6000);

        $response = $this->actingAs($admin)->put(route('admin.users.update', $owner), [
            'full_name' => $owner->full_name,
            'username' => $owner->username,
            'role' => $owner->role->value,
            'farm_updates' => [
                [
                    'id' => $farm->id,
                    'latitude' => '10.3547270',
                    'longitude' => '124.9659800',
                    'fence_enabled' => '1',
                    'fence_shape_type' => 'POLYGON',
                    'fence_geometry' => json_encode([
                        'vertices' => [
                            [10.3546000, 124.9657000],
                            [10.3549500, 124.9657000],
                            [10.3549500, 124.9662000],
                            [10.3546000, 124.9662000],
                        ],
                    ]),
                ],
            ],
        ]);

        $response->assertRedirect(route('admin.users.edit', $owner));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('farm_premises_zones', [
            'farm_id' => $farm->id,
            'shape_type' => 'POLYGON',
            'is_active' => true,
        ]);
    }

    public function test_owner_farm_location_on_polygon_fence_edge_is_allowed(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create([
            'role' => UserRole::OWNER,
            'username' => 'owner_fence_edge_allowed',
        ]);

        $farm = Farm::query()->create([
            'farm_name' => 'Fence Farm Edge',
            'location' => 'Purok Edge',
            'barangay' => 'Barangay Edge',
            'municipality' => 'Bontoc',
            'province' => 'Southern Leyte',
            'latitude' => null,
            'longitude' => null,
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);

        Geofence::set(true, 10.3547270, 124.9659800, 6000);

        $response = $this->actingAs($admin)->put(route('admin.users.update', $owner), [
            'full_name' => $owner->full_name,
            'username' => $owner->username,
            'role' => $owner->role->value,
            'farm_updates' => [
                [
                    'id' => $farm->id,
                    'latitude' => '10.3549500',
                    'longitude' => '124.9659800',
                    'fence_enabled' => '1',
                    'fence_shape_type' => 'POLYGON',
                    'fence_geometry' => json_encode([
                        'vertices' => [
                            [10.3546000, 124.9657000],
                            [10.3549500, 124.9657000],
                            [10.3549500, 124.9662000],
                            [10.3546000, 124.9662000],
                        ],
                    ]),
                ],
            ],
        ]);

        $response->assertRedirect(route('admin.users.edit', $owner));
        $response->assertSessionHas('status');

        $farm->refresh();
        $this->assertSame('10.3549500', number_format((float) $farm->latitude, 7, '.', ''));
        $this->assertSame('124.9659800', number_format((float) $farm->longitude, 7, '.', ''));
    }

    public function test_owner_farm_fence_save_fails_when_fence_is_outside_general_geofence(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create([
            'role' => UserRole::OWNER,
            'username' => 'owner_fence_outside',
        ]);

        $farm = Farm::query()->create([
            'farm_name' => 'Fence Farm Two',
            'location' => 'Purok Dos',
            'barangay' => 'Barangay Dos',
            'municipality' => 'Bontoc',
            'province' => 'Southern Leyte',
            'latitude' => 10.3547270,
            'longitude' => 124.9659800,
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);

        Geofence::set(true, 10.3547270, 124.9659800, 1500);

        $response = $this->actingAs($admin)->put(route('admin.users.update', $owner), [
            'full_name' => $owner->full_name,
            'username' => $owner->username,
            'role' => $owner->role->value,
            'farm_updates' => [
                [
                    'id' => $farm->id,
                    'latitude' => '10.3547270',
                    'longitude' => '124.9659800',
                    'fence_enabled' => '1',
                    'fence_shape_type' => 'CIRCLE',
                    'fence_geometry' => json_encode([
                        'center_latitude' => 10.3547270,
                        'center_longitude' => 124.9659800,
                        'radius_meters' => 5000,
                    ]),
                ],
            ],
        ]);

        $response->assertSessionHasErrors(['farm_updates.0.fence_geometry']);
        $this->assertDatabaseMissing('farm_premises_zones', [
            'farm_id' => $farm->id,
            'is_active' => true,
        ]);
    }

    public function test_owner_farm_fence_save_fails_when_general_geofence_not_configured(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create([
            'role' => UserRole::OWNER,
            'username' => 'owner_fence_no_geofence',
        ]);

        $farm = Farm::query()->create([
            'farm_name' => 'Fence Farm Three',
            'location' => 'Purok Tres',
            'barangay' => 'Barangay Tres',
            'municipality' => 'Bontoc',
            'province' => 'Southern Leyte',
            'latitude' => 10.3547270,
            'longitude' => 124.9659800,
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->put(route('admin.users.update', $owner), [
            'full_name' => $owner->full_name,
            'username' => $owner->username,
            'role' => $owner->role->value,
            'farm_updates' => [
                [
                    'id' => $farm->id,
                    'latitude' => '10.3547270',
                    'longitude' => '124.9659800',
                    'fence_enabled' => '1',
                    'fence_shape_type' => 'CIRCLE',
                    'fence_geometry' => json_encode([
                        'center_latitude' => 10.3547270,
                        'center_longitude' => 124.9659800,
                        'radius_meters' => 300,
                    ]),
                ],
            ],
        ]);

        $response->assertSessionHasErrors(['farm_updates.0.fence_geometry']);
        $this->assertDatabaseMissing('farm_premises_zones', [
            'farm_id' => $farm->id,
            'is_active' => true,
        ]);
    }

    public function test_owner_farm_point_must_be_inside_enabled_fence(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create([
            'role' => UserRole::OWNER,
            'username' => 'owner_fence_point_outside',
        ]);

        $farm = Farm::query()->create([
            'farm_name' => 'Fence Farm Four',
            'location' => 'Purok Kwatro',
            'barangay' => 'Barangay Kwatro',
            'municipality' => 'Bontoc',
            'province' => 'Southern Leyte',
            'latitude' => null,
            'longitude' => null,
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);

        Geofence::set(true, 10.3547270, 124.9659800, 6000);

        $response = $this->actingAs($admin)->put(route('admin.users.update', $owner), [
            'full_name' => $owner->full_name,
            'username' => $owner->username,
            'role' => $owner->role->value,
            'farm_updates' => [
                [
                    'id' => $farm->id,
                    'latitude' => '10.3600000',
                    'longitude' => '124.9700000',
                    'fence_enabled' => '1',
                    'fence_shape_type' => 'CIRCLE',
                    'fence_geometry' => json_encode([
                        'center_latitude' => 10.3547270,
                        'center_longitude' => 124.9659800,
                        'radius_meters' => 120,
                    ]),
                ],
            ],
        ]);

        $response->assertSessionHasErrors([
            'farm_updates.0.latitude',
            'farm_updates.0.longitude',
        ]);
    }

    public function test_owner_farm_location_update_succeeds_when_fence_is_disabled(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create([
            'role' => UserRole::OWNER,
            'username' => 'owner_fence_disabled',
        ]);

        $farm = Farm::query()->create([
            'farm_name' => 'Fence Farm Five',
            'location' => 'Purok Singko',
            'barangay' => 'Barangay Singko',
            'municipality' => 'Bontoc',
            'province' => 'Southern Leyte',
            'latitude' => null,
            'longitude' => null,
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);

        FarmPremisesZone::query()->create([
            'farm_id' => $farm->id,
            'shape_type' => 'CIRCLE',
            'center_latitude' => 10.3547270,
            'center_longitude' => 124.9659800,
            'radius_meters' => 400,
            'is_active' => true,
        ]);

        Geofence::set(true, 10.3547270, 124.9659800, 6000);

        $response = $this->actingAs($admin)->put(route('admin.users.update', $owner), [
            'full_name' => $owner->full_name,
            'username' => $owner->username,
            'role' => $owner->role->value,
            'farm_updates' => [
                [
                    'id' => $farm->id,
                    'latitude' => '10.3547270',
                    'longitude' => '124.9659800',
                    'fence_enabled' => '0',
                    'fence_shape_type' => 'CIRCLE',
                    'fence_geometry' => '',
                ],
            ],
        ]);

        $response->assertRedirect(route('admin.users.edit', $owner));
        $response->assertSessionHas('status');

        $farm->refresh();
        $this->assertSame('10.3547270', number_format((float) $farm->latitude, 7, '.', ''));
        $this->assertSame('124.9659800', number_format((float) $farm->longitude, 7, '.', ''));

        $this->assertDatabaseHas('farm_premises_zones', [
            'farm_id' => $farm->id,
            'is_active' => false,
        ]);
    }
}
