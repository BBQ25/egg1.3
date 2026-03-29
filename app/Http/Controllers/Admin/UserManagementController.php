<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserRegistrationStatus;
use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Models\User;
use App\Support\FarmPremises;
use App\Support\Geofence;
use App\Support\UserPremises;
use InvalidArgumentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $search = trim((string) $request->query('q', ''));
        $openModal = strtolower(trim((string) $request->query('open', ''))) === 'create';

        $users = User::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('username', 'like', "%{$search}%")
                        ->orWhere('full_name', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(15)
            ->appends($search === '' ? [] : ['q' => $search]);

        if ($request->boolean('ajax')) {
            return response()->json([
                'table_rows_html' => view('admin.users.partials.table_rows', [
                    'users' => $users,
                ])->render(),
                'table_footer_html' => view('admin.users.partials.table_footer', [
                    'users' => $users,
                ])->render(),
            ]);
        }

        return view('admin.users.index', [
            'users' => $users,
            'search' => $search,
            'roleOptions' => UserRole::labels(),
            'openModal' => $openModal,
        ]);
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('admin.users.index', ['open' => 'create']);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique('users', 'username')],
            'role' => ['required', Rule::in(array_keys(UserRole::labels()))],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $nameParts = $this->splitFullName($validated['full_name']);

        User::query()->create([
            'full_name' => $validated['full_name'],
            'first_name' => $nameParts['first_name'],
            'middle_name' => $nameParts['middle_name'],
            'last_name' => $nameParts['last_name'],
            'address' => null,
            'username' => $validated['username'],
            'password_hash' => $validated['password'],
            'role' => $validated['role'],
            'is_active' => true,
            'registration_status' => UserRegistrationStatus::APPROVED->value,
            'approved_by_user_id' => (int) $request->user()->id,
            'approved_at' => Carbon::now(),
            'denied_by_user_id' => null,
            'denied_at' => null,
            'denial_reason' => null,
            'deactivated_at' => null,
        ]);

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'User account created successfully.');
    }

    public function edit(User $user): View
    {
        $ownerFarms = $user->isOwner()
            ? $user->ownedFarms()->with('premisesZone')->orderBy('id')->get()
            : collect();

        return view('admin.users.edit', [
            'user' => $user,
            'roleOptions' => UserRole::labels(),
            'geofenceShapeOptions' => Geofence::shapeOptions(),
            'userPremisesSettings' => UserPremises::settingsForUser($user),
            'userPremisesMapPayload' => UserPremises::mapPayloadForUser($user),
            'ownerFarms' => $ownerFarms,
            'farmFencesMapPayload' => $user->isOwner() ? FarmPremises::mapPayloadForFarms($ownerFarms) : null,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $userPremisesEnabled = $request->boolean('user_premises_enabled');

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'role' => ['required', Rule::in(array_keys(UserRole::labels()))],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'user_premises_enabled' => ['nullable', 'boolean'],
            'user_premises_shape_type' => [$userPremisesEnabled ? 'required' : 'nullable', Rule::in(array_keys(Geofence::shapeOptions()))],
            'user_premises_geometry' => [$userPremisesEnabled ? 'required' : 'nullable', 'string'],
            'farm_updates' => ['nullable', 'array'],
            'farm_updates.*.id' => ['required', 'integer', Rule::exists('farms', 'id')],
            'farm_updates.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'farm_updates.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'farm_updates.*.fence_enabled' => ['nullable', 'boolean'],
            'farm_updates.*.fence_shape_type' => ['nullable', Rule::in(array_keys(Geofence::shapeOptions()))],
            'farm_updates.*.fence_geometry' => ['nullable', 'string'],
        ]);
        $nameParts = $this->splitFullName($validated['full_name']);

        $payload = [
            'full_name' => $validated['full_name'],
            'first_name' => $nameParts['first_name'],
            'middle_name' => $nameParts['middle_name'],
            'last_name' => $nameParts['last_name'],
            'username' => $validated['username'],
            'role' => $validated['role'],
        ];

        if (! empty($validated['password'])) {
            $payload['password_hash'] = $validated['password'];
        }

        $user->update($payload);

        $actorUserId = $request->user()?->id ? (int) $request->user()->id : null;

        $premisesShapeType = strtoupper(trim((string) ($validated['user_premises_shape_type'] ?? '')));
        $premisesGeometryRaw = trim((string) ($validated['user_premises_geometry'] ?? ''));
        $premisesDecodedGeometry = $premisesGeometryRaw === '' ? null : json_decode($premisesGeometryRaw, true);

        if (is_array($premisesDecodedGeometry)) {
            $inferredShapeType = Geofence::inferShapeType($premisesDecodedGeometry);
            if ($inferredShapeType !== null) {
                $premisesShapeType = $inferredShapeType;
            }
        }

        try {
            if ($user->isAdmin() || !$userPremisesEnabled) {
                UserPremises::clearForUser($user, $actorUserId);
            } else {
                if (!is_array($premisesDecodedGeometry) || $premisesShapeType === '') {
                    return redirect()
                        ->back()
                        ->withInput()
                        ->withErrors(['user_premises_geometry' => 'Please draw and save a valid user premises zone.']);
                }

                UserPremises::saveZoneForUser(
                    $user,
                    $premisesShapeType,
                    $premisesDecodedGeometry,
                    $actorUserId
                );
            }
        } catch (InvalidArgumentException $exception) {
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['user_premises_geometry' => $exception->getMessage()]);
        }

        $farmUpdates = $validated['farm_updates'] ?? [];
        if ($user->isOwner() && is_array($farmUpdates) && $farmUpdates !== []) {
            $ownedFarmIds = $user->ownedFarms()
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all();
            $geofenceConfigured = (bool) (Geofence::settings()['configured'] ?? false);

            foreach ($farmUpdates as $index => $farmUpdate) {
                $farmId = (int) ($farmUpdate['id'] ?? 0);
                if (!in_array($farmId, $ownedFarmIds, true)) {
                    continue;
                }

                $farm = Farm::query()
                    ->with('premisesZone')
                    ->where('id', $farmId)
                    ->where('owner_user_id', (int) $user->id)
                    ->first();

                if (!$farm) {
                    continue;
                }

                $latitude = $farmUpdate['latitude'] ?? null;
                $longitude = $farmUpdate['longitude'] ?? null;
                $latitude = $latitude === '' || $latitude === null ? null : (float) $latitude;
                $longitude = $longitude === '' || $longitude === null ? null : (float) $longitude;
                $fenceEnabled = filter_var(($farmUpdate['fence_enabled'] ?? false), FILTER_VALIDATE_BOOLEAN);
                $fenceShapeType = strtoupper(trim((string) ($farmUpdate['fence_shape_type'] ?? '')));
                $fenceGeometryRaw = trim((string) ($farmUpdate['fence_geometry'] ?? ''));
                $fenceDecodedGeometry = $fenceGeometryRaw === '' ? null : json_decode($fenceGeometryRaw, true);

                if (is_array($fenceDecodedGeometry)) {
                    $inferredShapeType = Geofence::inferShapeType($fenceDecodedGeometry);
                    if ($inferredShapeType !== null) {
                        $fenceShapeType = $inferredShapeType;
                    }
                }

                if (($latitude === null) !== ($longitude === null)) {
                    return redirect()
                        ->back()
                        ->withInput()
                        ->withErrors([
                            "farm_updates.{$index}.latitude" => 'Both farm latitude and longitude are required together.',
                            "farm_updates.{$index}.longitude" => 'Both farm latitude and longitude are required together.',
                        ]);
                }

                if (
                    $geofenceConfigured
                    && $latitude !== null
                    && $longitude !== null
                    && !Geofence::containsInConfiguredGeometry($latitude, $longitude)
                ) {
                    return redirect()
                        ->back()
                        ->withInput()
                        ->withErrors([
                            "farm_updates.{$index}.latitude" => 'Farm geolocation must be inside the general geofence.',
                            "farm_updates.{$index}.longitude" => 'Farm geolocation must be inside the general geofence.',
                        ]);
                }

                if ($fenceEnabled) {
                    $skipFenceSave = false;
                    if (!$geofenceConfigured) {
                        $existingFenceSettings = FarmPremises::settingsForFarm($farm);
                        $existingFenceGeometry = $existingFenceSettings['geometry'] ?? null;
                        $existingComparableGeometry = is_array($existingFenceGeometry) ? $existingFenceGeometry : null;
                        if (is_array($existingComparableGeometry)) {
                            unset($existingComparableGeometry['shape_type']);
                        }

                        $matchesExistingFence =
                            (bool) ($existingFenceSettings['enabled'] ?? false)
                            && strtoupper((string) ($existingFenceSettings['shape_type'] ?? '')) === $fenceShapeType
                            && is_array($fenceDecodedGeometry)
                            && json_encode($existingComparableGeometry) === json_encode($fenceDecodedGeometry);

                        if (!$matchesExistingFence) {
                            return redirect()
                                ->back()
                                ->withInput()
                                ->withErrors([
                                    "farm_updates.{$index}.fence_geometry" => 'Configure the general geofence first before saving farm fences.',
                                ]);
                        }

                        $skipFenceSave = true;
                    }

                    if (!is_array($fenceDecodedGeometry) || $fenceShapeType === '') {
                        return redirect()
                            ->back()
                            ->withInput()
                            ->withErrors([
                                "farm_updates.{$index}.fence_shape_type" => 'Please select a valid fence shape.',
                                "farm_updates.{$index}.fence_geometry" => 'Please draw and save a valid farm fence.',
                            ]);
                    }

                    $farmPointInsideFence = $skipFenceSave
                        ? FarmPremises::containsForFarm($farm, $latitude ?? 0.0, $longitude ?? 0.0)
                        : FarmPremises::containsInDraftGeometry($fenceShapeType, $fenceDecodedGeometry, $latitude ?? 0.0, $longitude ?? 0.0);

                    if ($latitude !== null && $longitude !== null && !$farmPointInsideFence) {
                        return redirect()
                            ->back()
                            ->withInput()
                            ->withErrors([
                                "farm_updates.{$index}.latitude" => 'Farm latitude/longitude must be inside the farm fence.',
                                "farm_updates.{$index}.longitude" => 'Farm latitude/longitude must be inside the farm fence.',
                            ]);
                    }

                    if (!$skipFenceSave) {
                        try {
                            FarmPremises::saveZoneForFarm(
                                $farm,
                                $fenceShapeType,
                                $fenceDecodedGeometry,
                                $actorUserId
                            );
                        } catch (InvalidArgumentException $exception) {
                            return redirect()
                                ->back()
                                ->withInput()
                                ->withErrors([
                                    "farm_updates.{$index}.fence_geometry" => $exception->getMessage(),
                                ]);
                        }
                    }
                } else {
                    FarmPremises::clearForFarm($farm, $actorUserId);
                }

                $farm->update([
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ]);
            }
        }

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('status', 'User profile updated.');
    }

    public function bulkUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'bulk_action' => ['required', Rule::in(['deactivate', 'reactivate', 'change_role'])],
            'role' => ['nullable', Rule::in(array_keys(UserRole::labels())), 'required_if:bulk_action,change_role'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', Rule::exists('users', 'id')],
            'current_password' => ['required', 'current_password'],
            'return_q' => ['nullable', 'string', 'max:120'],
        ], [
            'current_password.required' => 'Admin password is required to apply bulk actions.',
            'current_password.current_password' => 'Admin password is incorrect.',
        ]);

        $selectedIds = collect($validated['user_ids'])
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values();

        $actorId = (int) $request->user()->id;
        $skippedSelf = 0;
        $affected = 0;

        switch ($validated['bulk_action']) {
            case 'deactivate':
                $targetIds = $selectedIds->reject(static fn (int $id) => $id === $actorId)->values();
                $skippedSelf = $selectedIds->count() - $targetIds->count();

                if ($targetIds->isNotEmpty()) {
                    $affected = User::query()
                        ->whereIn('id', $targetIds->all())
                        ->update([
                            'is_active' => false,
                            'deactivated_at' => Carbon::now(),
                        ]);
                }

                $message = "Bulk deactivated {$affected} user(s).";
                break;

            case 'reactivate':
                $affected = User::query()
                    ->whereIn('id', $selectedIds->all())
                    ->update([
                        'is_active' => true,
                        'deactivated_at' => null,
                    ]);

                $message = "Bulk reactivated {$affected} user(s).";
                break;

            case 'change_role':
                $targetIds = $selectedIds->reject(static fn (int $id) => $id === $actorId)->values();
                $skippedSelf = $selectedIds->count() - $targetIds->count();

                if ($targetIds->isNotEmpty()) {
                    $affected = User::query()
                        ->whereIn('id', $targetIds->all())
                        ->update(['role' => $validated['role']]);
                }

                $roleLabel = UserRole::from($validated['role'])->label();
                $message = "Updated role to {$roleLabel} for {$affected} user(s).";
                break;

            default:
                $message = 'No bulk action was applied.';
                break;
        }

        if ($skippedSelf > 0) {
            $message .= ' Your own account was skipped for safety.';
        }

        $query = trim((string) ($validated['return_q'] ?? ''));

        return redirect()
            ->route('admin.users.index', $query === '' ? [] : ['q' => $query])
            ->with('status', $message);
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        if ($request->user()?->id === $user->id) {
            return redirect()
                ->route('admin.users.index')
                ->with('error', 'You cannot deactivate your own account.');
        }

        $user->update([
            'is_active' => false,
            'deactivated_at' => Carbon::now(),
        ]);

        return redirect()
            ->route('admin.users.index')
            ->with('status', "User {$user->username} has been deactivated.");
    }

    public function reactivate(User $user): RedirectResponse
    {
        $user->update([
            'is_active' => true,
            'deactivated_at' => null,
        ]);

        return redirect()
            ->route('admin.users.index')
            ->with('status', "User {$user->username} has been reactivated.");
    }

    public function approve(Request $request, User $user): RedirectResponse
    {
        if ($user->isApproved()) {
            return redirect()
                ->route('admin.users.index')
                ->with('status', "User {$user->username} is already approved.");
        }

        $user->update([
            'registration_status' => UserRegistrationStatus::APPROVED->value,
            'approved_by_user_id' => (int) $request->user()->id,
            'approved_at' => Carbon::now(),
            'denied_by_user_id' => null,
            'denied_at' => null,
            'denial_reason' => null,
            'is_active' => true,
            'deactivated_at' => null,
        ]);

        return redirect()
            ->route('admin.users.index')
            ->with('status', "User {$user->username} has been approved.");
    }

    public function deny(Request $request, User $user): RedirectResponse
    {
        if ($request->user()?->id === $user->id) {
            return redirect()
                ->route('admin.users.index')
                ->with('error', 'You cannot deny your own account.');
        }

        $validated = $request->validate([
            'denial_reason' => ['required', 'string', 'max:500'],
        ]);

        $user->update([
            'registration_status' => UserRegistrationStatus::DENIED->value,
            'approved_by_user_id' => null,
            'approved_at' => null,
            'denied_by_user_id' => (int) $request->user()->id,
            'denied_at' => Carbon::now(),
            'denial_reason' => trim((string) $validated['denial_reason']),
        ]);

        return redirect()
            ->route('admin.users.index')
            ->with('status', "User {$user->username} has been denied.");
    }

    /**
     * @return array{first_name: string|null, middle_name: string|null, last_name: string|null}
     */
    private function splitFullName(string $fullName): array
    {
        $tokens = array_values(array_filter(preg_split('/\s+/', trim($fullName)) ?: [], static fn (string $token): bool => $token !== ''));

        if ($tokens === []) {
            return [
                'first_name' => null,
                'middle_name' => null,
                'last_name' => null,
            ];
        }

        if (count($tokens) === 1) {
            return [
                'first_name' => $tokens[0],
                'middle_name' => null,
                'last_name' => null,
            ];
        }

        $firstName = array_shift($tokens);
        $lastName = array_pop($tokens);
        $middleName = $tokens === [] ? null : implode(' ', $tokens);

        return [
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
        ];
    }

}
