<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Device;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminDeviceRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_devices_page(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.devices.index'));

        $response->assertOk();
        $response->assertSee('Device Registry');
        $response->assertSee('Detect ESP32');
    }

    public function test_non_admin_user_cannot_access_devices_page(): void
    {
        $customer = User::factory()->customer()->create();

        $response = $this->actingAs($customer)->get(route('admin.devices.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_fetch_owner_farms_json_for_live_dropdowns(): void
    {
        $admin = User::factory()->admin()->create();
        [$ownerA, $farmA] = $this->createOwnerFarm('owner_registry_json_a');
        [$ownerB, $farmB] = $this->createOwnerFarm('owner_registry_json_b');

        $response = $this->actingAs($admin)->getJson(route('admin.devices.owner-farms', [
            'owner_user_id' => $ownerA->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('data.0.id', $farmA->id);
        $response->assertJsonPath('data.0.owner_user_id', $ownerA->id);
        $response->assertJsonMissingExact([
            'id' => $farmB->id,
            'farm_name' => $farmB->farm_name,
            'owner_user_id' => $ownerB->id,
        ]);
    }

    public function test_owner_farms_json_rejects_non_owner_id(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();

        $response = $this->actingAs($admin)->getJson(route('admin.devices.owner-farms', [
            'owner_user_id' => $customer->id,
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('ok', false);
        $response->assertJsonStructure([
            'ok',
            'message',
            'errors' => ['owner_user_id'],
        ]);
    }

    public function test_owner_farms_json_only_returns_active_farms(): void
    {
        $admin = User::factory()->admin()->create();
        [$owner, $activeFarm] = $this->createOwnerFarm('owner_registry_json_active');

        $inactiveFarm = Farm::query()->create([
            'farm_name' => 'Inactive Farm',
            'location' => 'Purok 2',
            'sitio' => 'Sitio Dos',
            'barangay' => 'Barangay Dos',
            'municipality' => 'Municipality Dos',
            'province' => 'Province Dos',
            'latitude' => 10.3547270,
            'longitude' => 124.9659800,
            'owner_user_id' => $owner->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($admin)->getJson(route('admin.devices.owner-farms', [
            'owner_user_id' => $owner->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('data.0.id', $activeFarm->id);
        $response->assertJsonMissingExact([
            'id' => $inactiveFarm->id,
            'farm_name' => $inactiveFarm->farm_name,
            'owner_user_id' => $owner->id,
        ]);
    }

    public function test_admin_can_create_device_with_valid_owner_and_farm_assignment(): void
    {
        $admin = User::factory()->admin()->create();
        [$owner, $farm] = $this->createOwnerFarm('owner_registry_a');

        $response = $this->actingAs($admin)->post(route('admin.devices.store'), [
            'module_board_name' => 'ESP32 DevKit V1',
            'primary_serial_no' => 'esp32-main-001',
            'owner_user_id' => $owner->id,
            'farm_id' => $farm->id,
            'aliases_text' => "esp32-alt-1\nesp32-alt-2",
            'main_technical_specs' => 'Dual-core 240 MHz',
            'processing_memory' => '520KB SRAM',
            'gpio_interfaces' => '34 GPIO, SPI, I2C, UART',
        ]);

        $response->assertRedirect(route('admin.devices.index'));
        $response->assertSessionHas('status');
        $response->assertSessionHas('device_api_key');

        $generatedKey = session('device_api_key');
        $this->assertIsString($generatedKey);
        $this->assertGeneratedDeviceApiKeyFormat($generatedKey);

        $this->assertDatabaseHas('devices', [
            'owner_user_id' => $owner->id,
            'farm_id' => $farm->id,
            'module_board_name' => 'ESP32 DevKit V1',
            'primary_serial_no' => 'ESP32-MAIN-001',
            'is_active' => true,
        ]);

        $device = Device::query()->where('primary_serial_no', 'ESP32-MAIN-001')->firstOrFail();
        $this->assertSame(2, $device->aliases()->count());
        $this->assertDatabaseHas('device_serial_aliases', [
            'device_id' => $device->id,
            'serial_no' => 'ESP32-ALT-1',
        ]);
    }

    public function test_owner_and_farm_mismatch_fails_validation(): void
    {
        $admin = User::factory()->admin()->create();
        [$ownerA, $farmA] = $this->createOwnerFarm('owner_registry_b');
        [$ownerB] = $this->createOwnerFarm('owner_registry_c');

        $response = $this->actingAs($admin)->post(route('admin.devices.store'), [
            'module_board_name' => 'ESP32-C3',
            'primary_serial_no' => 'esp32-mismatch-001',
            'owner_user_id' => $ownerB->id,
            'farm_id' => $farmA->id,
        ]);

        $response->assertSessionHasErrors(['farm_id']);

        $this->assertDatabaseMissing('devices', [
            'primary_serial_no' => 'ESP32-MISMATCH-001',
        ]);
    }

    public function test_duplicate_primary_serial_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        [$owner, $farm] = $this->createOwnerFarm('owner_registry_d');

        Device::query()->create([
            'owner_user_id' => $owner->id,
            'farm_id' => $farm->id,
            'module_board_name' => 'ESP32 Existing',
            'primary_serial_no' => 'ESP32-DUPLICATE-001',
            'api_key_hash' => Hash::make('old-key'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.devices.store'), [
            'module_board_name' => 'ESP32 New',
            'primary_serial_no' => 'esp32-duplicate-001',
            'owner_user_id' => $owner->id,
            'farm_id' => $farm->id,
        ]);

        $response->assertSessionHasErrors(['primary_serial_no']);
    }

    public function test_assigning_inactive_farm_fails_validation(): void
    {
        $admin = User::factory()->admin()->create();
        [$owner, $inactiveFarm] = $this->createOwnerFarm('owner_registry_inactive_farm', false);

        $response = $this->actingAs($admin)->post(route('admin.devices.store'), [
            'module_board_name' => 'ESP32 Inactive Farm',
            'primary_serial_no' => 'esp32-inactive-farm-1',
            'owner_user_id' => $owner->id,
            'farm_id' => $inactiveFarm->id,
        ]);

        $response->assertSessionHasErrors(['farm_id']);
        $this->assertDatabaseMissing('devices', [
            'primary_serial_no' => 'ESP32-INACTIVE-FARM-1',
        ]);
    }

    public function test_alias_colliding_with_another_device_primary_serial_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        [$owner, $farm] = $this->createOwnerFarm('owner_registry_e');

        Device::query()->create([
            'owner_user_id' => $owner->id,
            'farm_id' => $farm->id,
            'module_board_name' => 'ESP32 Existing',
            'primary_serial_no' => 'ESP32-COLLIDE-PRIMARY',
            'api_key_hash' => Hash::make('old-key'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.devices.store'), [
            'module_board_name' => 'ESP32 Candidate',
            'primary_serial_no' => 'esp32-candidate-1',
            'owner_user_id' => $owner->id,
            'farm_id' => $farm->id,
            'aliases_text' => 'esp32-collide-primary',
        ]);

        $response->assertSessionHasErrors(['aliases_text']);
    }

    public function test_admin_can_deactivate_and_reactivate_device(): void
    {
        $admin = User::factory()->admin()->create();
        $device = $this->createDevice('ESP32-LIFECYCLE-001', 'lifecycle-key');

        $deactivateResponse = $this->actingAs($admin)->patch(route('admin.devices.deactivate', $device));
        $deactivateResponse->assertRedirect(route('admin.devices.index'));

        $device->refresh();
        $this->assertFalse($device->is_active);
        $this->assertNotNull($device->deactivated_at);
        $this->assertSame($admin->id, $device->updated_by_user_id);

        $reactivateResponse = $this->actingAs($admin)->patch(route('admin.devices.reactivate', $device));
        $reactivateResponse->assertRedirect(route('admin.devices.index'));

        $device->refresh();
        $this->assertTrue($device->is_active);
        $this->assertNull($device->deactivated_at);
        $this->assertSame($admin->id, $device->updated_by_user_id);
    }

    public function test_rotating_device_key_invalidates_the_previous_key(): void
    {
        $admin = User::factory()->admin()->create();
        $oldKey = 'legacy-device-key';
        $device = $this->createDevice('ESP32-ROTATE-001', $oldKey);

        $response = $this->actingAs($admin)->post(route('admin.devices.rotate-key', $device));

        $response->assertRedirect(route('admin.devices.index'));
        $response->assertSessionHas('device_api_key');

        $newKey = session('device_api_key');
        $this->assertIsString($newKey);
        $this->assertNotSame($oldKey, $newKey);
        $this->assertGeneratedDeviceApiKeyFormat($newKey);

        $device->refresh();
        $this->assertFalse(Hash::check($oldKey, $device->api_key_hash));
        $this->assertTrue(Hash::check((string) $newKey, $device->api_key_hash));
        $this->assertSame($newKey, $device->api_key_encrypted);
    }

    public function test_admin_can_show_existing_device_key_with_current_password(): void
    {
        $admin = User::factory()->admin()->create([
            'password_hash' => 'secret-1234',
        ]);
        $device = $this->createDevice('ESP32-SHOW-001', 'device-key-123');

        $response = $this->actingAs($admin)->post(route('admin.devices.show-key', $device), [
            'current_password' => 'secret-1234',
        ]);

        $response->assertRedirect(route('admin.devices.index'));
        $response->assertSessionHas('device_api_key', 'device-key-123');
        $response->assertSessionHas('device_api_key_serial', 'ESP32-SHOW-001');
    }

    public function test_show_existing_device_key_requires_current_admin_password(): void
    {
        $admin = User::factory()->admin()->create([
            'password_hash' => 'secret-1234',
        ]);
        $device = $this->createDevice('ESP32-SHOW-002', 'device-key-456');

        $response = $this->from(route('admin.devices.index'))->actingAs($admin)->post(route('admin.devices.show-key', $device), [
            'current_password' => 'wrong-password',
        ]);

        $response->assertRedirect(route('admin.devices.index', [
            'open' => 'reveal-key',
            'show' => $device->id,
        ]));
        $response->assertSessionHasErrors(['current_password']);
    }

    public function test_legacy_device_key_without_encrypted_copy_cannot_be_shown_again(): void
    {
        $admin = User::factory()->admin()->create([
            'password_hash' => 'secret-1234',
        ]);
        [$owner, $farm] = $this->createOwnerFarm('owner_for_legacy_show');

        $device = Device::query()->create([
            'owner_user_id' => $owner->id,
            'farm_id' => $farm->id,
            'module_board_name' => 'ESP32 Legacy Device',
            'primary_serial_no' => 'ESP32-LEGACY-KEY-001',
            'api_key_hash' => Hash::make('legacy-key'),
            'api_key_encrypted' => null,
            'is_active' => true,
        ]);

        $response = $this->from(route('admin.devices.index'))->actingAs($admin)->post(route('admin.devices.show-key', $device), [
            'current_password' => 'secret-1234',
        ]);

        $response->assertRedirect(route('admin.devices.index', [
            'open' => 'reveal-key',
            'show' => $device->id,
        ]));
        $response->assertSessionHasErrors(['current_password']);
    }

    /**
     * @return array{0:\App\Models\User,1:\App\Models\Farm}
     */
    private function createOwnerFarm(string $username, bool $isActive = true): array
    {
        $owner = User::factory()->create([
            'role' => UserRole::OWNER,
            'username' => $username,
        ]);

        $farm = Farm::query()->create([
            'farm_name' => 'Farm-' . $username,
            'location' => 'Purok 1',
            'sitio' => 'Sitio Uno',
            'barangay' => 'Barangay Uno',
            'municipality' => 'Municipality Uno',
            'province' => 'Province Uno',
            'latitude' => 10.3547270,
            'longitude' => 124.9659800,
            'owner_user_id' => $owner->id,
            'is_active' => $isActive,
        ]);

        return [$owner, $farm];
    }

    private function createDevice(string $serial, string $plainKey): Device
    {
        [$owner, $farm] = $this->createOwnerFarm('owner_for_' . strtolower($serial));

        return Device::query()->create([
            'owner_user_id' => $owner->id,
            'farm_id' => $farm->id,
            'module_board_name' => 'ESP32 Device',
            'primary_serial_no' => strtoupper($serial),
            'api_key_hash' => Hash::make($plainKey),
            'api_key_encrypted' => $plainKey,
            'is_active' => true,
            'main_technical_specs' => null,
            'processing_memory' => null,
            'gpio_interfaces' => null,
        ]);
    }

    private function assertGeneratedDeviceApiKeyFormat(string $apiKey): void
    {
        $this->assertStringStartsWith('eggpulse_', $apiKey);

        $body = substr($apiKey, strlen('eggpulse_'));
        $this->assertSame(12, strlen($body));
        $this->assertMatchesRegularExpression('/^[a-z0-9]{12}$/', $body);

        foreach (['r', 'y', 'h', 'n'] as $marker) {
            $this->assertStringContainsString($marker, $body);
        }

        $this->assertFalse($this->containsOrderedMarkerSequence($body, ['r', 'y', 'h', 'n']));
    }

    /**
     * @param array<int, string> $sequence
     */
    private function containsOrderedMarkerSequence(string $value, array $sequence): bool
    {
        $sequenceIndex = 0;

        foreach (str_split($value) as $character) {
            if ($character !== $sequence[$sequenceIndex]) {
                continue;
            }

            $sequenceIndex += 1;
            if ($sequenceIndex === count($sequence)) {
                return true;
            }
        }

        return false;
    }
}
