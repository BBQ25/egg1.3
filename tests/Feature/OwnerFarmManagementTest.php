<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\FarmChangeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OwnerFarmManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_access_my_farms_page_and_only_see_owned_farms(): void
    {
        $owner = User::factory()->owner()->create();
        $otherOwner = User::factory()->owner()->create();

        $ownedFarm = $this->createFarm($owner, ['farm_name' => 'Owner Farm']);
        $this->createFarm($otherOwner, ['farm_name' => 'Foreign Farm']);

        $response = $this->actingAs($owner)->get(route('owner.farms.index'));

        $response->assertOk();
        $response->assertSee('My Farms');
        $response->assertSee($ownedFarm->farm_name);
        $response->assertDontSee('Foreign Farm');
        $response->assertSee(route('owner.farms.index'));
    }

    public function test_owner_can_submit_claim_request_for_admin_approval(): void
    {
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)->post(route('owner.farms.store'), [
            'farm_name' => 'Claimed Farm',
            'location' => 'Purok 9',
            'sitio' => 'Sitio Nueve',
            'barangay' => 'Barangay Nueve',
            'municipality' => 'Municipality Nueve',
            'province' => 'Province Nueve',
            'latitude' => 10.3600000,
            'longitude' => 124.9700000,
            'farm_form_mode' => 'claim',
        ]);

        $response->assertRedirect(route('owner.farms.index'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('farm_change_requests', [
            'owner_user_id' => $owner->id,
            'request_type' => 'CLAIM',
            'status' => 'PENDING',
            'farm_name' => 'Claimed Farm',
            'location' => 'Purok 9',
            'sitio' => 'Sitio Nueve',
            'barangay' => 'Barangay Nueve',
            'municipality' => 'Municipality Nueve',
            'province' => 'Province Nueve',
            'latitude' => 10.3600000,
            'longitude' => 124.9700000,
        ]);
    }

    public function test_owner_can_submit_location_update_request_without_mutating_live_farm_until_approved(): void
    {
        $owner = User::factory()->owner()->create();
        $farm = $this->createFarm($owner);

        $response = $this->actingAs($owner)->put(route('owner.farms.update', $farm), [
            'farm_name' => 'Updated Owner Farm',
            'location' => 'Purok 9',
            'sitio' => 'Sitio Nueve',
            'barangay' => 'Barangay Nueve',
            'municipality' => 'Municipality Nueve',
            'province' => 'Province Nueve',
            'latitude' => 10.3600000,
            'longitude' => 124.9700000,
            'farm_form_mode' => 'edit',
            'farm_id' => $farm->id,
        ]);

        $response->assertRedirect(route('owner.farms.index'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('farm_change_requests', [
            'farm_id' => $farm->id,
            'owner_user_id' => $owner->id,
            'request_type' => 'LOCATION_UPDATE',
            'status' => 'PENDING',
            'farm_name' => 'Updated Owner Farm',
        ]);

        $this->assertDatabaseHas('farms', [
            'id' => $farm->id,
            'farm_name' => 'North Ridge Farm',
            'location' => 'Purok 1',
        ]);
    }

    public function test_owner_cannot_submit_update_for_another_owners_farm(): void
    {
        $owner = User::factory()->owner()->create();
        $otherOwner = User::factory()->owner()->create();
        $farm = $this->createFarm($otherOwner, ['farm_name' => 'Restricted Farm']);

        $response = $this->actingAs($owner)->put(route('owner.farms.update', $farm), [
            'farm_name' => 'Tampered Farm',
            'location' => 'Purok 1',
            'sitio' => 'Sitio Uno',
            'barangay' => 'Barangay Uno',
            'municipality' => 'Municipality Uno',
            'province' => 'Province Uno',
            'latitude' => 10.3547270,
            'longitude' => 124.9659800,
            'farm_form_mode' => 'edit',
            'farm_id' => $farm->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('farms', [
            'id' => $farm->id,
            'farm_name' => 'Restricted Farm',
        ]);
    }

    public function test_non_owner_roles_cannot_access_my_farms_page(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $customer = User::factory()->customer()->create();

        $this->actingAs($admin)->get(route('owner.farms.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('owner.farms.index'))->assertForbidden();
        $this->actingAs($customer)->get(route('owner.farms.index'))->assertForbidden();
    }

    public function test_owner_outside_geofence_request_is_recorded_and_flagged_for_admin_review(): void
    {
        $owner = User::factory()->owner()->create();
        $farm = $this->createFarm($owner);

        \App\Support\Geofence::set(true, 10.3547270, 124.9659800, 1000);

        $response = $this->actingAs($owner)
            ->withSession([
                'geofence.latitude' => 10.3547270,
                'geofence.longitude' => 124.9659800,
            ])
            ->from(route('owner.farms.index'))
            ->put(route('owner.farms.update', $farm), [
            'farm_name' => 'North Ridge Farm',
            'location' => 'Purok 1',
            'sitio' => 'Sitio Uno',
            'barangay' => 'Barangay Uno',
            'municipality' => 'Municipality Uno',
            'province' => 'Province Uno',
            'latitude' => 11.5000000,
            'longitude' => 125.5000000,
            'farm_form_mode' => 'edit',
            'farm_id' => $farm->id,
        ]);

        $response->assertRedirect(route('owner.farms.index'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('farm_change_requests', [
            'farm_id' => $farm->id,
            'owner_user_id' => $owner->id,
            'status' => 'PENDING',
            'inside_general_geofence' => false,
        ]);
    }

    public function test_owner_can_reverse_geocode_selected_pin_for_my_farms(): void
    {
        $owner = User::factory()->owner()->create();

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

        $response = $this->actingAs($owner)->get(route('owner.farms.reverse-geocode', [
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

    private function createFarm(User $owner, array $overrides = []): Farm
    {
        return Farm::query()->create(array_merge([
            'farm_name' => 'North Ridge Farm',
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
