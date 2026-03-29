<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Device;
use App\Models\Farm;
use App\Models\User;
use App\Support\Geofence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminFarmManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_farm_with_valid_owner_and_coordinates(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($admin)->post(route('admin.maps.farms.store'), $this->farmPayload([
            'owner_user_id' => $owner->id,
        ]));

        $response->assertRedirect(route('admin.maps.farms'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('farms', [
            'farm_name' => 'North Ridge Farm',
            'owner_user_id' => $owner->id,
            'location' => 'Purok 1',
            'sitio' => 'Sitio Uno',
            'barangay' => 'Barangay Uno',
            'municipality' => 'Municipality Uno',
            'province' => 'Province Uno',
            'latitude' => 10.3547270,
            'longitude' => 124.9659800,
        ]);
    }

    public function test_farm_create_rejects_non_owner_user_id(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();

        $response = $this->actingAs($admin)->post(route('admin.maps.farms.store'), $this->farmPayload([
            'owner_user_id' => $customer->id,
        ]));

        $response->assertSessionHasErrors(['owner_user_id']);
        $this->assertDatabaseMissing('farms', [
            'farm_name' => 'North Ridge Farm',
            'owner_user_id' => $customer->id,
        ]);
    }

    public function test_farm_create_rejects_coordinates_outside_general_geofence_when_configured(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();

        Geofence::set(true, 10.3547270, 124.9659800, 1000);

        $response = $this->actingAs($admin)->post(route('admin.maps.farms.store'), $this->farmPayload([
            'owner_user_id' => $owner->id,
            'latitude' => 11.5000000,
            'longitude' => 125.5000000,
        ]));

        $response->assertSessionHasErrors(['latitude']);
        $this->assertDatabaseMissing('farms', [
            'farm_name' => 'North Ridge Farm',
            'owner_user_id' => $owner->id,
        ]);
    }

    public function test_admin_can_reassign_farm_owner(): void
    {
        $admin = User::factory()->admin()->create();
        $ownerA = User::factory()->owner()->create();
        $ownerB = User::factory()->owner()->create();

        $farm = $this->createFarm($ownerA, [
            'farm_name' => 'Owner Shift Farm',
            'latitude' => 10.3500000,
            'longitude' => 124.9600000,
        ]);

        $response = $this->actingAs($admin)->put(route('admin.maps.farms.update', $farm), $this->farmPayload([
            'farm_name' => 'Owner Shift Farm',
            'owner_user_id' => $ownerB->id,
            'latitude' => 10.3500000,
            'longitude' => 124.9600000,
            'farm_form_mode' => 'edit',
            'farm_id' => $farm->id,
        ]));

        $response->assertRedirect(route('admin.maps.farms'));
        $this->assertDatabaseHas('farms', [
            'id' => $farm->id,
            'owner_user_id' => $ownerB->id,
        ]);
    }

    public function test_reassigning_farm_owner_cascades_assigned_device_owners(): void
    {
        $admin = User::factory()->admin()->create();
        $ownerA = User::factory()->owner()->create();
        $ownerB = User::factory()->owner()->create();
        $farm = $this->createFarm($ownerA, [
            'farm_name' => 'Cascade Farm',
            'latitude' => 10.3495000,
            'longitude' => 124.9640000,
        ]);

        $deviceA = Device::query()->create([
            'owner_user_id' => $ownerA->id,
            'farm_id' => $farm->id,
            'module_board_name' => 'ESP32 Alpha',
            'primary_serial_no' => 'ESP32-CASCADE-A',
            'api_key_hash' => Hash::make('cascade-a'),
            'is_active' => true,
        ]);

        $deviceB = Device::query()->create([
            'owner_user_id' => $ownerA->id,
            'farm_id' => $farm->id,
            'module_board_name' => 'ESP32 Beta',
            'primary_serial_no' => 'ESP32-CASCADE-B',
            'api_key_hash' => Hash::make('cascade-b'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->put(route('admin.maps.farms.update', $farm), $this->farmPayload([
            'farm_name' => 'Cascade Farm',
            'owner_user_id' => $ownerB->id,
            'latitude' => 10.3495000,
            'longitude' => 124.9640000,
            'farm_form_mode' => 'edit',
            'farm_id' => $farm->id,
        ]));

        $response->assertRedirect(route('admin.maps.farms'));

        $this->assertDatabaseHas('devices', [
            'id' => $deviceA->id,
            'owner_user_id' => $ownerB->id,
            'updated_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('devices', [
            'id' => $deviceB->id,
            'owner_user_id' => $ownerB->id,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_non_admin_users_cannot_call_farm_store_or_update_endpoints(): void
    {
        $owner = User::factory()->owner()->create();
        $anotherOwner = User::factory()->owner()->create();
        $farm = $this->createFarm($owner, [
            'farm_name' => 'Restricted Farm',
            'latitude' => 10.3510000,
            'longitude' => 124.9620000,
        ]);

        $storeResponse = $this->actingAs($owner)->post(route('admin.maps.farms.store'), $this->farmPayload([
            'owner_user_id' => $owner->id,
        ]));
        $storeResponse->assertForbidden();

        $updateResponse = $this->actingAs($owner)->put(route('admin.maps.farms.update', $farm), $this->farmPayload([
            'farm_name' => 'Restricted Farm',
            'owner_user_id' => $anotherOwner->id,
            'latitude' => 10.3510000,
            'longitude' => 124.9620000,
            'farm_form_mode' => 'edit',
            'farm_id' => $farm->id,
        ]));
        $updateResponse->assertForbidden();

        $deleteResponse = $this->actingAs($owner)->delete(route('admin.maps.farms.destroy', $farm), [
            'farm_form_mode' => 'delete',
            'farm_id' => $farm->id,
            'current_password' => 'password',
        ]);
        $deleteResponse->assertForbidden();
    }

    public function test_admin_can_reverse_geocode_selected_pin_for_farm_picker(): void
    {
        $admin = User::factory()->admin()->create();

        Http::fake([
            'https://nominatim.openstreetmap.org/reverse*' => Http::response([
                'display_name' => 'Sample Farm Area, Barangay Casao, Bontoc, Southern Leyte, Philippines',
                'address' => [
                    'city_district' => 'Barangay Casao',
                    'city' => 'Bontoc',
                    'state' => 'Southern Leyte',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.maps.farms.reverse-geocode', [
            'latitude' => 10.3547270,
            'longitude' => 124.9659800,
        ]));

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'data' => [
                'barangay' => 'Barangay Casao',
                'municipality' => 'Bontoc',
                'province' => 'Southern Leyte',
            ],
        ]);
    }

    public function test_reverse_geocode_does_not_copy_barangay_into_municipality_when_municipality_is_missing(): void
    {
        $admin = User::factory()->admin()->create();

        Http::fake([
            'https://nominatim.openstreetmap.org/reverse*' => Http::response([
                'display_name' => 'Mercedes, Southern Leyte, Eastern Visayas, Philippines',
                'address' => [
                    'village' => 'Mercedes',
                    'state' => 'Southern Leyte',
                    'region' => 'Eastern Visayas',
                    'country' => 'Philippines',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.maps.farms.reverse-geocode', [
            'latitude' => 10.4000000,
            'longitude' => 125.1700000,
        ]));

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'data' => [
                'barangay' => 'Mercedes',
                'municipality' => null,
                'province' => 'Southern Leyte',
            ],
        ]);
    }

    public function test_non_admin_cannot_reverse_geocode_selected_pin_for_farm_picker(): void
    {
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)->get(route('admin.maps.farms.reverse-geocode', [
            'latitude' => 10.3547270,
            'longitude' => 124.9659800,
        ]));

        $response->assertForbidden();
    }

    public function test_admin_can_delete_farm_after_confirming_password(): void
    {
        $admin = User::factory()->admin()->create([
            'password_hash' => 'secret-1234',
        ]);
        $owner = User::factory()->owner()->create();
        $farm = $this->createFarm($owner, [
            'farm_name' => 'Delete Me Farm',
        ]);

        Device::query()->create([
            'owner_user_id' => $owner->id,
            'farm_id' => $farm->id,
            'module_board_name' => 'ESP32 Delete',
            'primary_serial_no' => 'ESP32-DELETE-1',
            'api_key_hash' => Hash::make('delete-key'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->delete(route('admin.maps.farms.destroy', $farm), [
            'farm_form_mode' => 'delete',
            'farm_id' => $farm->id,
            'current_password' => 'secret-1234',
        ]);

        $response->assertRedirect(route('admin.maps.farms'));
        $response->assertSessionHas('status');
        $this->assertDatabaseMissing('farms', [
            'id' => $farm->id,
        ]);
        $this->assertDatabaseMissing('devices', [
            'farm_id' => $farm->id,
        ]);
    }

    public function test_admin_farm_delete_rejects_incorrect_password(): void
    {
        $admin = User::factory()->admin()->create([
            'password_hash' => 'secret-1234',
        ]);
        $owner = User::factory()->owner()->create();
        $farm = $this->createFarm($owner, [
            'farm_name' => 'Protected Farm',
        ]);

        $response = $this->actingAs($admin)->from(route('admin.maps.farms'))->delete(route('admin.maps.farms.destroy', $farm), [
            'farm_form_mode' => 'delete',
            'farm_id' => $farm->id,
            'current_password' => 'wrong-secret',
        ]);

        $response->assertRedirect(route('admin.maps.farms'));
        $response->assertSessionHasErrors(['current_password']);
        $this->assertDatabaseHas('farms', [
            'id' => $farm->id,
        ]);
    }

    public function test_farm_map_page_still_renders_map_payload_and_device_registry_route_still_works(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();
        $farm = $this->createFarm($owner, [
            'farm_name' => 'Map Regression Farm',
        ]);

        $mapResponse = $this->actingAs($admin)->get(route('admin.maps.farms'));
        $mapResponse->assertOk();
        $mapResponse->assertSee('Farm &amp; Map Management', false);
        $mapResponse->assertSee('Farm Locations Map');
        $mapResponse->assertSee('Map Regression Farm');
        $mapResponse->assertSee('Farm &amp; Map Guide', false);
        $mapResponse->assertSee('Map Guide');
        $mapResponse->assertSee('Pin Farm Coordinates');
        $mapResponse->assertSee('create_location_picker_map');

        $payload = $mapResponse->viewData('farmLocationsMapPayload');
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('farms', $payload);
        $this->assertTrue(collect($payload['farms'])->contains(fn (array $row): bool => (int) ($row['id'] ?? 0) === $farm->id));

        $devicePage = $this->actingAs($admin)->get(route('admin.devices.index'));
        $devicePage->assertOk();
        $devicePage->assertSee('Device Registry');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function farmPayload(array $overrides = []): array
    {
        return array_merge([
            'farm_name' => 'North Ridge Farm',
            'owner_user_id' => null,
            'location' => 'Purok 1',
            'sitio' => 'Sitio Uno',
            'barangay' => 'Barangay Uno',
            'municipality' => 'Municipality Uno',
            'province' => 'Province Uno',
            'latitude' => 10.3547270,
            'longitude' => 124.9659800,
            'farm_form_mode' => 'create',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createFarm(User $owner, array $overrides = []): Farm
    {
        return Farm::query()->create(array_merge([
            'farm_name' => 'Primary Farm',
            'location' => 'Purok 1',
            'sitio' => 'Sitio Uno',
            'barangay' => 'Barangay Uno',
            'municipality' => 'Municipality Uno',
            'province' => 'Province Uno',
            'latitude' => 10.3547270,
            'longitude' => 124.9659800,
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ], $overrides));
    }
}
