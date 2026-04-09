<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AppSetting;
use App\Models\Farm;
use App\Models\FarmPremisesZone;
use App\Models\GeofenceZone;
use App\Models\User;
use App\Support\AppTimezone;
use App\Support\EggWeightRanges;
use App\Support\Geofence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_settings_page(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.settings.edit'));

        $response->assertOk();
        $response->assertSee('Admin Settings');
        $response->assertSee('Figtree');
        $response->assertSee('Poppins');
        $response->assertSee('Cambria');
        $response->assertSee('Front Pages &gt; Landing Page', false);
        $response->assertSee('Layouts &gt; Blank', false);
        $response->assertSee('Layouts');
        $response->assertSee('Front Pages');
        $response->assertSee('Multi Level');
        $response->assertSee('Support');
        $response->assertSee('Documentation');
        $response->assertSee('layouts');
        $response->assertSee('front-pages');
        $response->assertSee('front-pages-landing-page');
        $response->assertSee('multi-level');
        $response->assertSee('support');
        $response->assertSee('documentation');
        $response->assertSee('Search pages to hide');
        $response->assertSee('Hide visible results');
        $response->assertSee('Clear all hidden');
        $response->assertSee('Timezone &amp; Clock', false);
        $response->assertSee('Philippine Standard Time');
        $response->assertSee('Weight Range Settings');
        $response->assertSee('Peewee');
        $response->assertSee('Extra-Large');
    }

    public function test_non_admin_cannot_access_settings_page(): void
    {
        $user = User::factory()->create(['role' => UserRole::CUSTOMER]);

        $response = $this->actingAs($user)->get(route('admin.settings.edit'));

        $response->assertForbidden();
    }

    public function test_admin_can_access_access_boundary_page(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.settings.access-boundary'));

        $response->assertOk();
        $response->assertSee('Access Boundary');
        $response->assertSee('System Geofence');
        $response->assertSee('Access Boundary Guide');
        $response->assertSee('Map Guide');
    }

    public function test_non_admin_cannot_access_access_boundary_page(): void
    {
        $user = User::factory()->create(['role' => UserRole::CUSTOMER]);

        $response = $this->actingAs($user)->get(route('admin.settings.access-boundary'));

        $response->assertForbidden();
    }

    public function test_admin_can_access_location_overview_page(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create([
            'role' => UserRole::OWNER,
        ]);

        Farm::query()->create([
            'farm_name' => 'Settings Location Farm',
            'location' => 'Purok 5',
            'sitio' => 'Sitio Lima',
            'barangay' => 'Barangay Cinco',
            'municipality' => 'Municipality Cinco',
            'province' => 'Province Cinco',
            'latitude' => 10.3547270,
            'longitude' => 124.9659800,
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.settings.location-overview'));

        $response->assertOk();
        $response->assertSee('Location Overview');
        $response->assertSee('All Farm Locations');
        $response->assertSee('Settings Location Farm');
        $response->assertSee('Location Overview Guide');
        $response->assertSee('Map Guide');
    }

    public function test_non_admin_cannot_access_location_overview_page(): void
    {
        $user = User::factory()->create(['role' => UserRole::CUSTOMER]);

        $response = $this->actingAs($user)->get(route('admin.settings.location-overview'));

        $response->assertForbidden();
    }

    public function test_admin_can_update_font_style_to_poppins(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->put(route('admin.settings.update'), [
            'app_timezone' => 'Asia/Manila',
            'font_style' => 'poppins',
            'egg_weight_ranges' => $this->defaultEggWeightRangePayload(),
        ]);

        $response->assertRedirect(route('admin.settings.edit'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('app_settings', [
            'setting_key' => 'ui_font_style',
            'setting_value' => 'poppins',
        ]);

        $this->assertSame('poppins', $setting = AppSetting::query()->where('setting_key', 'ui_font_style')->value('setting_value'));
    }

    public function test_admin_can_update_font_style_to_cambria(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->put(route('admin.settings.update'), [
            'app_timezone' => 'Asia/Manila',
            'font_style' => 'cambria',
            'egg_weight_ranges' => $this->defaultEggWeightRangePayload(),
        ]);

        $response->assertRedirect(route('admin.settings.edit'));
        $response->assertSessionHas('status');

        $setting = AppSetting::query()->where('setting_key', 'ui_font_style')->first();
        $this->assertNotNull($setting);
        $this->assertSame('cambria', $setting->setting_value);

        $this->assertSame('cambria', AppSetting::query()->where('setting_key', 'ui_font_style')->value('setting_value'));
    }

    public function test_admin_settings_reject_unknown_disabled_pages(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->put(route('admin.settings.update'), [
            'app_timezone' => 'Asia/Manila',
            'font_style' => 'figtree',
            'disabled_pages' => ['not-a-real-page'],
            'egg_weight_ranges' => $this->defaultEggWeightRangePayload(),
        ]);

        $response->assertSessionHasErrors(['disabled_pages.0']);

        $stored = AppSetting::query()->where('setting_key', 'disabled_pages')->value('setting_value');
        $this->assertNull($stored);
    }

    public function test_admin_can_save_virtual_sidebar_keys_as_disabled_pages(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->put(route('admin.settings.update'), [
            'app_timezone' => 'Asia/Manila',
            'font_style' => 'figtree',
            'disabled_pages' => [
                'layouts',
                'front-pages',
                'front-pages-landing-page',
                'multi-level',
                'support',
                'documentation',
            ],
            'egg_weight_ranges' => $this->defaultEggWeightRangePayload(),
        ]);

        $response->assertRedirect(route('admin.settings.edit'));

        $stored = AppSetting::query()->where('setting_key', 'disabled_pages')->value('setting_value');
        $this->assertNotNull($stored);

        $decoded = json_decode($stored, true);
        $this->assertIsArray($decoded);
        $this->assertContains('layouts', $decoded);
        $this->assertContains('front-pages', $decoded);
        $this->assertContains('front-pages-landing-page', $decoded);
        $this->assertContains('multi-level', $decoded);
        $this->assertContains('support', $decoded);
        $this->assertContains('documentation', $decoded);
    }

    public function test_admin_can_update_egg_weight_ranges(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->defaultEggWeightRangePayload();
        $payload['medium']['max'] = '58.99';
        $payload['large']['min'] = '59.00';
        $payload['large']['max'] = '63.99';
        $payload['extra_large']['min'] = '64.00';
        $payload['extra_large']['max'] = '69.99';

        $response = $this->actingAs($admin)->put(route('admin.settings.update'), [
            'app_timezone' => 'Asia/Manila',
            'font_style' => 'figtree',
            'egg_weight_ranges' => $payload,
        ]);

        $response->assertRedirect(route('admin.settings.edit'));
        $response->assertSessionHas('status');

        $stored = AppSetting::query()->where('setting_key', EggWeightRanges::SETTING_KEY)->value('setting_value');
        $this->assertNotNull($stored);

        $decoded = json_decode($stored, true);
        $this->assertIsArray($decoded);
        $this->assertSame('59.00', $decoded['large']['min'] ?? null);
        $this->assertSame('63.99', $decoded['large']['max'] ?? null);
        $this->assertSame('Large', EggWeightRanges::classify(60.10));
    }

    public function test_admin_settings_reject_overlapping_egg_weight_ranges(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->defaultEggWeightRangePayload();
        $payload['small']['max'] = '55.50';
        $payload['medium']['min'] = '55.00';

        $response = $this->actingAs($admin)->put(route('admin.settings.update'), [
            'app_timezone' => 'Asia/Manila',
            'font_style' => 'figtree',
            'egg_weight_ranges' => $payload,
        ]);

        $response->assertSessionHasErrors(['egg_weight_ranges.medium.min']);

        $stored = AppSetting::query()->where('setting_key', EggWeightRanges::SETTING_KEY)->value('setting_value');
        $this->assertNull($stored);
    }

    public function test_admin_can_update_timezone_to_utc(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->put(route('admin.settings.update'), [
            'app_timezone' => 'UTC',
            'font_style' => 'figtree',
            'egg_weight_ranges' => $this->defaultEggWeightRangePayload(),
        ]);

        $response->assertRedirect(route('admin.settings.edit'));

        $this->assertDatabaseHas('app_settings', [
            'setting_key' => AppTimezone::SETTING_KEY,
            'setting_value' => 'UTC',
        ]);
    }

    public function test_admin_settings_reject_invalid_timezone(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->put(route('admin.settings.update'), [
            'app_timezone' => 'Mars/Phobos',
            'font_style' => 'figtree',
            'egg_weight_ranges' => $this->defaultEggWeightRangePayload(),
        ]);

        $response->assertSessionHasErrors(['app_timezone']);
        $this->assertNull(AppSetting::query()->where('setting_key', AppTimezone::SETTING_KEY)->value('setting_value'));
    }

    public function test_admin_can_enable_polygon_geofence_and_persist_geometry(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->put(route('admin.settings.access-boundary.update'), [
            'geofence_enabled' => '1',
            'geofence_shape_type' => 'POLYGON',
            'geofence_geometry' => json_encode([
                'vertices' => [
                    [10.3547000, 124.9659000],
                    [10.3549000, 124.9664000],
                    [10.3543000, 124.9663000],
                ],
            ]),
        ]);

        $response->assertRedirect(route('admin.settings.access-boundary'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('app_settings', [
            'setting_key' => 'geofence_enabled',
            'setting_value' => '1',
        ]);

        $zone = GeofenceZone::query()->where('is_active', true)->first();
        $this->assertNotNull($zone);
        $this->assertSame('POLYGON', $zone->shape_type);
        $this->assertNotNull($zone->vertices_json);

        $vertices = json_decode((string) $zone->vertices_json, true);
        $this->assertIsArray($vertices);
        $this->assertCount(3, $vertices);
    }

    public function test_admin_geofence_save_infers_shape_from_geometry_payload(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->put(route('admin.settings.access-boundary.update'), [
            'geofence_enabled' => '1',
            'geofence_shape_type' => 'CIRCLE',
            'geofence_geometry' => json_encode([
                'vertices' => [
                    [10.3547000, 124.9659000],
                    [10.3549000, 124.9664000],
                    [10.3543000, 124.9663000],
                ],
            ]),
        ]);

        $response->assertRedirect(route('admin.settings.access-boundary'));
        $response->assertSessionHas('status');

        $zone = GeofenceZone::query()->where('is_active', true)->first();
        $this->assertNotNull($zone);
        $this->assertSame('POLYGON', $zone->shape_type);
    }

    public function test_admin_can_save_multiple_general_geofence_zones(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->put(route('admin.settings.access-boundary.update'), [
            'geofence_enabled' => '1',
            'geofence_shape_type' => 'CIRCLE',
            'geofence_geometry' => json_encode([
                'zones' => [
                    [
                        'shape_type' => 'CIRCLE',
                        'geometry' => [
                            'center_latitude' => 10.3547270,
                            'center_longitude' => 124.9659800,
                            'radius_meters' => 150,
                        ],
                    ],
                    [
                        'shape_type' => 'POLYGON',
                        'geometry' => [
                            'vertices' => [
                                [10.3600000, 124.9700000],
                                [10.3603000, 124.9705000],
                                [10.3597000, 124.9706000],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $response->assertRedirect(route('admin.settings.access-boundary'));

        $activeZones = GeofenceZone::query()
            ->where('is_active', true)
            ->get();

        $this->assertCount(2, $activeZones);
        $this->assertEqualsCanonicalizing(['CIRCLE', 'POLYGON'], $activeZones->pluck('shape_type')->all());

        $payload = Geofence::mapPayload();
        $this->assertCount(2, $payload['geometries'] ?? []);
    }

    public function test_admin_can_access_pure_farm_map_page(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create([
            'role' => UserRole::OWNER,
        ]);

        Farm::query()->create([
            'farm_name' => 'Map Farm One',
            'location' => 'Purok 1',
            'sitio' => 'Sitio Uno',
            'barangay' => 'Barangay Uno',
            'municipality' => 'Municipality Uno',
            'province' => 'Province Uno',
            'latitude' => 10.3547270,
            'longitude' => 124.9659800,
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.maps.farms'));

        $response->assertOk();
        $response->assertSee('Farm Locations Map');
        $response->assertSee('Map Farm One');
    }

    public function test_farm_map_page_payload_includes_farm_fence_geometry(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create([
            'role' => UserRole::OWNER,
        ]);

        $farm = Farm::query()->create([
            'farm_name' => 'Map Fence Farm',
            'location' => 'Purok 2',
            'sitio' => 'Sitio Dos',
            'barangay' => 'Barangay Dos',
            'municipality' => 'Municipality Dos',
            'province' => 'Province Dos',
            'latitude' => 10.3547270,
            'longitude' => 124.9659800,
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);

        FarmPremisesZone::query()->create([
            'farm_id' => $farm->id,
            'shape_type' => 'POLYGON',
            'vertices_json' => json_encode([
                [10.3546000, 124.9657000],
                [10.3549500, 124.9657000],
                [10.3549500, 124.9662000],
                [10.3546000, 124.9662000],
            ]),
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.maps.farms'));

        $response->assertOk();
        $payload = $response->viewData('farmLocationsMapPayload');
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('farms', $payload);

        $farmPayload = collect($payload['farms'])->firstWhere('id', $farm->id);
        $this->assertNotNull($farmPayload);
        $this->assertSame(true, (bool) ($farmPayload['fence']['enabled'] ?? false));
        $this->assertSame('POLYGON', $farmPayload['fence']['shape_type'] ?? null);
    }

    public function test_non_admin_cannot_access_pure_farm_map_page(): void
    {
        $user = User::factory()->create(['role' => UserRole::CUSTOMER]);

        $response = $this->actingAs($user)->get(route('admin.maps.farms'));

        $response->assertForbidden();
    }

    /**
     * @return array<string, array{min:string,max:string}>
     */
    private function defaultEggWeightRangePayload(): array
    {
        $payload = [];

        foreach (EggWeightRanges::definitions() as $definition) {
            $payload[$definition['slug']] = [
                'min' => number_format((float) $definition['min'], 2, '.', ''),
                'max' => number_format((float) $definition['max'], 2, '.', ''),
            ];
        }

        return $payload;
    }
}
