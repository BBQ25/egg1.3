<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Farm;
use App\Models\FarmChangeRequest;
use App\Models\User;
use App\Services\ReverseGeocodingService;
use App\Support\FarmPremises;
use App\Support\Geofence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FarmMapController extends Controller
{
    public function index(): View
    {
        $farms = Farm::query()
            ->select(['id', 'farm_name', 'owner_user_id', 'location', 'sitio', 'latitude', 'longitude', 'barangay', 'municipality', 'province', 'is_active'])
            ->with(['owner:id,full_name,username', 'premisesZone'])
            ->orderBy('farm_name')
            ->get();

        $owners = User::query()
            ->where('role', UserRole::OWNER->value)
            ->where('is_active', true)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'username']);

        $farmChangeRequests = FarmChangeRequest::query()
            ->with([
                'farm:id,farm_name,owner_user_id',
                'owner:id,full_name,username',
                'reviewer:id,full_name',
            ])
            ->latest('submitted_at')
            ->latest('id')
            ->get();

        return view('admin.maps.farms', [
            'farms' => $farms,
            'owners' => $owners,
            'farmChangeRequests' => $farmChangeRequests,
            'farmLocationsMapPayload' => FarmPremises::mapPayloadForFarms($farms),
            'generalGeofenceConfigured' => (bool) (Geofence::settings()['configured'] ?? false),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateFarmPayload($request);

        Farm::query()->create([
            'farm_name' => $validated['farm_name'],
            'owner_user_id' => (int) $validated['owner_user_id'],
            'location' => $validated['location'],
            'sitio' => $validated['sitio'],
            'barangay' => $validated['barangay'],
            'municipality' => $validated['municipality'],
            'province' => $validated['province'],
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'is_active' => true,
        ]);

        return redirect()
            ->route('admin.maps.farms')
            ->with('status', 'Farm created and assigned to owner.');
    }

    public function update(Request $request, Farm $farm): RedirectResponse
    {
        $validated = $this->validateFarmPayload($request, $farm);
        $actorUserId = $request->user()?->id ? (int) $request->user()->id : null;
        $nextOwnerUserId = (int) $validated['owner_user_id'];
        $ownerChanged = (int) $farm->owner_user_id !== $nextOwnerUserId;

        DB::transaction(function () use ($farm, $validated, $ownerChanged, $nextOwnerUserId, $actorUserId): void {
            $farm->update([
                'farm_name' => $validated['farm_name'],
                'owner_user_id' => $nextOwnerUserId,
                'location' => $validated['location'],
                'sitio' => $validated['sitio'],
                'barangay' => $validated['barangay'],
                'municipality' => $validated['municipality'],
                'province' => $validated['province'],
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
            ]);

            if ($ownerChanged) {
                Device::query()
                    ->where('farm_id', (int) $farm->id)
                    ->update([
                        'owner_user_id' => $nextOwnerUserId,
                        'updated_by_user_id' => $actorUserId,
                        'updated_at' => now(),
                    ]);
            }
        });

        $status = $ownerChanged
            ? 'Farm updated. Owner reassigned and related devices synchronized.'
            : 'Farm updated successfully.';

        return redirect()
            ->route('admin.maps.farms')
            ->with('status', $status);
    }

    public function destroy(Request $request, Farm $farm): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'farm_form_mode' => ['nullable', 'string', Rule::in(['delete'])],
            'farm_id' => ['nullable', 'integer'],
        ], [
            'current_password.required' => 'Admin password is required to delete a farm.',
            'current_password.current_password' => 'Admin password is incorrect.',
        ]);

        $deviceCount = Device::query()
            ->where('farm_id', (int) $farm->id)
            ->count();
        $farmName = (string) $farm->farm_name;

        DB::transaction(function () use ($farm): void {
            $farm->delete();
        });

        $suffix = $deviceCount > 0
            ? " {$deviceCount} linked device(s) were also removed by cascade."
            : '';

        return redirect()
            ->route('admin.maps.farms')
            ->with('status', "Farm {$farmName} deleted successfully.{$suffix}");
    }

    public function reverseGeocode(Request $request, ReverseGeocodingService $reverseGeocoding): JsonResponse
    {
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

    public function approveChangeRequest(Request $request, FarmChangeRequest $farmChangeRequest): RedirectResponse
    {
        abort_unless($farmChangeRequest->status === 'PENDING', 422);

        $actorUserId = $request->user()?->id ? (int) $request->user()->id : null;

        DB::transaction(function () use ($farmChangeRequest, $actorUserId, $request): void {
            if ($farmChangeRequest->request_type === 'CLAIM') {
                $farm = Farm::query()->create([
                    'farm_name' => $farmChangeRequest->farm_name,
                    'owner_user_id' => (int) $farmChangeRequest->owner_user_id,
                    'location' => $farmChangeRequest->location,
                    'sitio' => $farmChangeRequest->sitio,
                    'barangay' => $farmChangeRequest->barangay,
                    'municipality' => $farmChangeRequest->municipality,
                    'province' => $farmChangeRequest->province,
                    'latitude' => $farmChangeRequest->latitude,
                    'longitude' => $farmChangeRequest->longitude,
                    'is_active' => true,
                ]);

                $farmChangeRequest->farm_id = (int) $farm->id;
            } else {
                $farm = $farmChangeRequest->farm;
                abort_if(!$farm, 422);

                $farm->update([
                    'farm_name' => $farmChangeRequest->farm_name,
                    'location' => $farmChangeRequest->location,
                    'sitio' => $farmChangeRequest->sitio,
                    'barangay' => $farmChangeRequest->barangay,
                    'municipality' => $farmChangeRequest->municipality,
                    'province' => $farmChangeRequest->province,
                    'latitude' => $farmChangeRequest->latitude,
                    'longitude' => $farmChangeRequest->longitude,
                ]);
            }

            $farmChangeRequest->status = 'APPROVED';
            $farmChangeRequest->reviewed_at = now();
            $farmChangeRequest->reviewed_by_user_id = $actorUserId;
            $farmChangeRequest->admin_notes = trim((string) $request->input('admin_notes', '')) ?: null;
            $farmChangeRequest->save();
        });

        return redirect()
            ->route('admin.maps.farms')
            ->with('status', 'Farm request approved and applied successfully.');
    }

    public function rejectChangeRequest(Request $request, FarmChangeRequest $farmChangeRequest): RedirectResponse
    {
        abort_unless($farmChangeRequest->status === 'PENDING', 422);

        $farmChangeRequest->update([
            'status' => 'REJECTED',
            'admin_notes' => trim((string) $request->input('admin_notes', '')) ?: null,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $request->user()?->id ? (int) $request->user()->id : null,
        ]);

        return redirect()
            ->route('admin.maps.farms')
            ->with('status', 'Farm request rejected.');
    }

    /**
     * @return array{
     *   farm_name:string,
     *   owner_user_id:int,
     *   location:string,
     *   sitio:string,
     *   barangay:string,
     *   municipality:string,
     *   province:string,
     *   latitude:float,
     *   longitude:float
     * }
     */
    private function validateFarmPayload(Request $request, ?Farm $farm = null): array
    {
        $validator = Validator::make($request->all(), [
            'farm_name' => ['required', 'string', 'max:120'],
            'owner_user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(static function ($query): void {
                    $query->where('role', UserRole::OWNER->value);
                }),
            ],
            'location' => ['required', 'string', 'max:160'],
            'sitio' => ['required', 'string', 'max:120'],
            'barangay' => ['required', 'string', 'max:120'],
            'municipality' => ['required', 'string', 'max:120'],
            'province' => ['required', 'string', 'max:120'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'farm_form_mode' => ['nullable', 'string', Rule::in(['create', 'edit'])],
            'farm_id' => ['nullable', 'integer'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            $latitude = $request->input('latitude');
            $longitude = $request->input('longitude');

            if (!is_numeric($latitude) || !is_numeric($longitude)) {
                return;
            }

            $geofenceSettings = Geofence::settings();
            if (!($geofenceSettings['configured'] ?? false)) {
                return;
            }

            if (!Geofence::containsInConfiguredGeometry((float) $latitude, (float) $longitude)) {
                $validator->errors()->add('latitude', 'Farm coordinates must be inside the configured general geofence.');
            }
        });

        $validated = $validator->validate();

        return [
            'farm_name' => trim((string) $validated['farm_name']),
            'owner_user_id' => (int) $validated['owner_user_id'],
            'location' => trim((string) $validated['location']),
            'sitio' => trim((string) $validated['sitio']),
            'barangay' => trim((string) $validated['barangay']),
            'municipality' => trim((string) $validated['municipality']),
            'province' => trim((string) $validated['province']),
            'latitude' => round((float) $validated['latitude'], 7),
            'longitude' => round((float) $validated['longitude'], 7),
        ];
    }
}
