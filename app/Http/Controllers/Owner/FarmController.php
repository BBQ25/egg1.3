<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Models\FarmChangeRequest;
use App\Services\ReverseGeocodingService;
use App\Support\FarmPremises;
use App\Support\Geofence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FarmController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user && $user->isOwner(), 403);

        $farms = $user->ownedFarms()
            ->select(['id', 'farm_name', 'owner_user_id', 'location', 'sitio', 'latitude', 'longitude', 'barangay', 'municipality', 'province', 'is_active'])
            ->with(['owner:id,full_name,username', 'premisesZone'])
            ->withCount('devices')
            ->orderBy('farm_name')
            ->get();

        $changeRequests = FarmChangeRequest::query()
            ->with(['farm:id,farm_name'])
            ->where('owner_user_id', (int) $user->id)
            ->latest('submitted_at')
            ->latest('id')
            ->get();

        return view('owner.farms.index', [
            'farms' => $farms,
            'changeRequests' => $changeRequests,
            'farmLocationsMapPayload' => FarmPremises::mapPayloadForFarms($farms),
            'generalGeofenceConfigured' => (bool) (Geofence::settings()['configured'] ?? false),
        ]);
    }

    public function storeClaim(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $user->isOwner(), 403);

        $validated = $this->validateFarmPayload($request, false);
        $insideGeofence = $this->resolveGeofenceContainment($validated['latitude'], $validated['longitude']);

        FarmChangeRequest::query()->create([
            'farm_id' => null,
            'owner_user_id' => (int) $user->id,
            'request_type' => 'CLAIM',
            'status' => 'PENDING',
            'farm_name' => $validated['farm_name'],
            'location' => $validated['location'],
            'sitio' => $validated['sitio'],
            'barangay' => $validated['barangay'],
            'municipality' => $validated['municipality'],
            'province' => $validated['province'],
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'inside_general_geofence' => $insideGeofence,
            'submitted_at' => now(),
        ]);

        return redirect()
            ->route('owner.farms.index')
            ->with('status', $insideGeofence === false
                ? 'Farm claim submitted. The pinned location is outside the general geofence, so admin review is required before approval.'
                : 'Farm claim submitted for admin approval.');
    }

    public function update(Request $request, Farm $farm): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $user->isOwner(), 403);
        abort_unless((int) $farm->owner_user_id === (int) $user->id, 403);

        $validated = $this->validateFarmPayload($request, true);
        $insideGeofence = $this->resolveGeofenceContainment($validated['latitude'], $validated['longitude']);

        FarmChangeRequest::query()->create([
            'farm_id' => (int) $farm->id,
            'owner_user_id' => (int) $user->id,
            'request_type' => 'LOCATION_UPDATE',
            'status' => 'PENDING',
            'farm_name' => $validated['farm_name'],
            'location' => $validated['location'],
            'sitio' => $validated['sitio'],
            'barangay' => $validated['barangay'],
            'municipality' => $validated['municipality'],
            'province' => $validated['province'],
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'inside_general_geofence' => $insideGeofence,
            'submitted_at' => now(),
        ]);

        return redirect()
            ->route('owner.farms.index')
            ->with('status', $insideGeofence === false
                ? "Farm update request submitted for {$farm->farm_name}. The plotted location is outside the general geofence, so admin review is required."
                : "Farm update request submitted for {$farm->farm_name}. Awaiting admin approval.");
    }

    public function reverseGeocode(Request $request, ReverseGeocodingService $reverseGeocoding): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->isOwner(), 403);

        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $latitude = round((float) $validated['latitude'], 7);
        $longitude = round((float) $validated['longitude'], 7);

        try {
            $result = $reverseGeocoding->reverse(
                $latitude,
                $longitude,
                $request->header('Accept-Language')
            );
        } catch (\Throwable) {
            return response()->json([
                'ok' => false,
                'message' => 'Unable to resolve address details for the selected pin.',
            ], 502);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'barangay' => $result['barangay'] ?? null,
                'municipality' => $result['municipality'] ?? null,
                'province' => $result['province'] ?? null,
                'display_name' => $result['display_name'] ?? null,
            ],
        ]);
    }

    /**
     * @return array{
     *   farm_name:string,
     *   location:string,
     *   sitio:string,
     *   barangay:string,
     *   municipality:string,
     *   province:string,
     *   latitude:float,
     *   longitude:float
     * }
     */
    private function validateFarmPayload(Request $request, bool $isEdit): array
    {
        $validator = Validator::make($request->all(), [
            'farm_name' => ['required', 'string', 'max:120'],
            'location' => ['required', 'string', 'max:160'],
            'sitio' => ['required', 'string', 'max:120'],
            'barangay' => ['required', 'string', 'max:120'],
            'municipality' => ['required', 'string', 'max:120'],
            'province' => ['required', 'string', 'max:120'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'farm_form_mode' => ['nullable', 'string', Rule::in($isEdit ? ['edit'] : ['claim'])],
            'farm_id' => ['nullable', 'integer'],
        ]);

        $validated = $validator->validate();

        return [
            'farm_name' => trim((string) $validated['farm_name']),
            'location' => trim((string) $validated['location']),
            'sitio' => trim((string) $validated['sitio']),
            'barangay' => trim((string) $validated['barangay']),
            'municipality' => trim((string) $validated['municipality']),
            'province' => trim((string) $validated['province']),
            'latitude' => round((float) $validated['latitude'], 7),
            'longitude' => round((float) $validated['longitude'], 7),
        ];
    }

    private function resolveGeofenceContainment(float $latitude, float $longitude): ?bool
    {
        $geofenceSettings = Geofence::settings();
        if (!($geofenceSettings['configured'] ?? false)) {
            return null;
        }

        return Geofence::containsInConfiguredGeometry($latitude, $longitude);
    }
}
