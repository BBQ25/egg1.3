<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\FarmChangeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFarmChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_approve_farm_claim_request_and_create_live_farm(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();

        $request = FarmChangeRequest::query()->create([
            'farm_id' => null,
            'owner_user_id' => $owner->id,
            'request_type' => 'CLAIM',
            'status' => 'PENDING',
            'farm_name' => 'Claim Queue Farm',
            'location' => 'Purok 5',
            'sitio' => 'Sitio Cinco',
            'barangay' => 'Barangay Cinco',
            'municipality' => 'Municipality Cinco',
            'province' => 'Province Cinco',
            'latitude' => 10.3650000,
            'longitude' => 124.9750000,
            'inside_general_geofence' => false,
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.maps.farm-requests.approve', $request));

        $response->assertRedirect(route('admin.maps.farms'));
        $this->assertDatabaseHas('farms', [
            'farm_name' => 'Claim Queue Farm',
            'owner_user_id' => $owner->id,
        ]);
        $this->assertDatabaseHas('farm_change_requests', [
            'id' => $request->id,
            'status' => 'APPROVED',
            'reviewed_by_user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_approve_location_update_request_and_apply_it_to_live_farm(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();
        $farm = Farm::query()->create([
            'farm_name' => 'Live Farm',
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

        $request = FarmChangeRequest::query()->create([
            'farm_id' => $farm->id,
            'owner_user_id' => $owner->id,
            'request_type' => 'LOCATION_UPDATE',
            'status' => 'PENDING',
            'farm_name' => 'Live Farm Updated',
            'location' => 'Purok 8',
            'sitio' => 'Sitio Ocho',
            'barangay' => 'Barangay Ocho',
            'municipality' => 'Municipality Ocho',
            'province' => 'Province Ocho',
            'latitude' => 10.3700000,
            'longitude' => 124.9800000,
            'inside_general_geofence' => true,
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.maps.farm-requests.approve', $request));

        $response->assertRedirect(route('admin.maps.farms'));
        $this->assertDatabaseHas('farms', [
            'id' => $farm->id,
            'farm_name' => 'Live Farm Updated',
            'location' => 'Purok 8',
            'latitude' => 10.3700000,
            'longitude' => 124.9800000,
        ]);
        $this->assertDatabaseHas('farm_change_requests', [
            'id' => $request->id,
            'status' => 'APPROVED',
        ]);
    }

    public function test_admin_can_reject_request_without_changing_live_farm(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();
        $farm = Farm::query()->create([
            'farm_name' => 'Review Farm',
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

        $request = FarmChangeRequest::query()->create([
            'farm_id' => $farm->id,
            'owner_user_id' => $owner->id,
            'request_type' => 'LOCATION_UPDATE',
            'status' => 'PENDING',
            'farm_name' => 'Rejected Farm Update',
            'location' => 'Purok 3',
            'sitio' => 'Sitio Tres',
            'barangay' => 'Barangay Tres',
            'municipality' => 'Municipality Tres',
            'province' => 'Province Tres',
            'latitude' => 10.3900000,
            'longitude' => 124.9900000,
            'inside_general_geofence' => false,
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.maps.farm-requests.reject', $request), [
            'admin_notes' => 'Rejected after manual review.',
        ]);

        $response->assertRedirect(route('admin.maps.farms'));
        $this->assertDatabaseHas('farm_change_requests', [
            'id' => $request->id,
            'status' => 'REJECTED',
            'reviewed_by_user_id' => $admin->id,
            'admin_notes' => 'Rejected after manual review.',
        ]);
        $this->assertDatabaseHas('farms', [
            'id' => $farm->id,
            'farm_name' => 'Review Farm',
            'location' => 'Purok 1',
        ]);
    }
}
