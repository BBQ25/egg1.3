<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRegistrationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Models\FarmStaffAssignment;
use App\Models\User;
use App\Support\Geofence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register', [
            'roleOptions' => $this->publicRoleOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:60'],
            'middle_name' => ['nullable', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'address' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique('users', 'username')],
            'role' => ['required', Rule::in(array_keys($this->publicRoleOptions()))],
            'password' => ['required', 'string', 'min:8', 'confirmed'],

            'farm_name' => ['nullable', 'string', 'max:120'],
            'farm_location' => ['nullable', 'string', 'max:160'],
            'farm_sitio' => ['nullable', 'string', 'max:120'],
            'farm_barangay' => ['nullable', 'string', 'max:120'],
            'farm_municipality' => ['nullable', 'string', 'max:120'],
            'farm_province' => ['nullable', 'string', 'max:120'],
            'farm_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'farm_longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $selectedRole = UserRole::from((string) $validated['role']);
        $requiresFarm = in_array($selectedRole, [UserRole::OWNER, UserRole::WORKER], true);

        if ($requiresFarm) {
            $request->validate([
                'farm_name' => ['required', 'string', 'max:120'],
                'farm_location' => ['required', 'string', 'max:160'],
                'farm_sitio' => ['required', 'string', 'max:120'],
                'farm_barangay' => ['required', 'string', 'max:120'],
                'farm_municipality' => ['required', 'string', 'max:120'],
                'farm_province' => ['required', 'string', 'max:120'],
                'farm_latitude' => ['required', 'numeric', 'between:-90,90'],
                'farm_longitude' => ['required', 'numeric', 'between:-180,180'],
            ]);

            $farmLatitude = isset($validated['farm_latitude']) ? (float) $validated['farm_latitude'] : null;
            $farmLongitude = isset($validated['farm_longitude']) ? (float) $validated['farm_longitude'] : null;
            $geofenceConfigured = (bool) (Geofence::settings()['configured'] ?? false);

            if (
                $geofenceConfigured
                && $farmLatitude !== null
                && $farmLongitude !== null
                && !Geofence::containsInConfiguredGeometry($farmLatitude, $farmLongitude)
            ) {
                return redirect()
                    ->back()
                    ->withInput()
                    ->withErrors([
                        'farm_latitude' => 'Farm geolocation must be inside the general geofence.',
                        'farm_longitude' => 'Farm geolocation must be inside the general geofence.',
                    ]);
            }
        }

        DB::transaction(function () use ($validated, $selectedRole, $requiresFarm): void {
            $user = User::query()->create([
                'full_name' => $this->composeFullName(
                    (string) $validated['first_name'],
                    (string) ($validated['middle_name'] ?? ''),
                    (string) $validated['last_name']
                ),
                'first_name' => $validated['first_name'],
                'middle_name' => $validated['middle_name'] ?? null,
                'last_name' => $validated['last_name'],
                'address' => $validated['address'],
                'username' => $validated['username'],
                'password_hash' => $validated['password'],
                'role' => $selectedRole->value,
                'is_active' => true,
                'registration_status' => UserRegistrationStatus::PENDING->value,
                'approved_by_user_id' => null,
                'approved_at' => null,
                'denied_by_user_id' => null,
                'denied_at' => null,
                'denial_reason' => null,
                'deactivated_at' => null,
            ]);

            if (!$requiresFarm) {
                return;
            }

            $farm = Farm::query()->create([
                'farm_name' => $validated['farm_name'],
                'location' => $validated['farm_location'],
                'sitio' => $validated['farm_sitio'],
                'barangay' => $validated['farm_barangay'],
                'municipality' => $validated['farm_municipality'],
                'province' => $validated['farm_province'],
                'latitude' => $validated['farm_latitude'],
                'longitude' => $validated['farm_longitude'],
                'owner_user_id' => $selectedRole === UserRole::OWNER ? $user->id : null,
                'is_active' => true,
            ]);

            if ($selectedRole === UserRole::WORKER) {
                FarmStaffAssignment::query()->create([
                    'farm_id' => $farm->id,
                    'user_id' => $user->id,
                ]);
            }
        });

        return redirect()
            ->route('login')
            ->with('status', 'Registration submitted. Your account is pending admin approval.');
    }

    /**
     * @return array<string, string>
     */
    private function publicRoleOptions(): array
    {
        $labels = UserRole::labels();
        unset($labels[UserRole::ADMIN->value]);

        return $labels;
    }

    private function composeFullName(string $firstName, string $middleName, string $lastName): string
    {
        $parts = array_filter([
            trim($firstName),
            trim($middleName),
            trim($lastName),
        ], static fn(string $part): bool => $part !== '');

        return implode(' ', $parts);
    }
}
