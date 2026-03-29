<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class GuideCenterController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $tracks = $this->guideTracks();
        $visibleTrackKeys = match ($user?->role) {
            UserRole::ADMIN => ['admin', 'owner', 'staff', 'customer'],
            UserRole::OWNER => ['owner'],
            UserRole::WORKER => ['staff'],
            UserRole::CUSTOMER => ['customer'],
            default => [],
        };

        $guideTracks = collect($visibleTrackKeys)
            ->filter(fn (string $key): bool => array_key_exists($key, $tracks))
            ->mapWithKeys(function (string $key) use ($tracks): array {
                $track = $tracks[$key];
                $track['steps'] = collect($track['steps'] ?? [])
                    ->map(function (array $step): array {
                        $routeName = $step['route_name'] ?? null;
                        $step['url'] = is_string($routeName) && $routeName !== '' && Route::has($routeName)
                            ? route($routeName)
                            : null;

                        return $step;
                    })
                    ->all();

                return [$key => $track];
            })
            ->all();

        return view('guides.index', [
            'guideTracks' => $guideTracks,
            'defaultTrackKey' => array_key_first($guideTracks),
            'commonIssues' => $this->commonIssues(),
            'isAdminViewer' => $user?->role === UserRole::ADMIN,
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function guideTracks(): array
    {
        return [
            'admin' => [
                'label' => 'Admin Setup',
                'audience' => 'Admin',
                'summary' => 'Primary setup and validation tasks for system administrators.',
                'steps' => [
                    [
                        'id' => 'admin-review-users',
                        'title' => 'Review User Access',
                        'description' => 'Validate account roles, approval state, and assigned responsibilities.',
                        'action' => 'Open Users and confirm the current account list.',
                        'route_name' => 'admin.users.index',
                    ],
                    [
                        'id' => 'admin-map-farms',
                        'title' => 'Map Farms',
                        'description' => 'Create and maintain farms with complete coordinates and owner assignments.',
                        'action' => 'Open Farm & Map and review the registry and map overlays.',
                        'route_name' => 'admin.maps.farms',
                    ],
                    [
                        'id' => 'admin-assign-device',
                        'title' => 'Assign Device To Owner And Farm',
                        'description' => 'Register ESP32 device and ensure owner and farm match.',
                        'action' => 'Open Devices and complete Create Device form.',
                        'route_name' => 'admin.devices.index',
                    ],
                    [
                        'id' => 'admin-verify-dashboard',
                        'title' => 'Verify Monitoring Output',
                        'description' => 'Confirm counts, device activity, and farm/device filters on Dashboard.',
                        'action' => 'Open Dashboard and check context filters and live cards.',
                        'route_name' => 'dashboard',
                    ],
                ],
                'notes' => [
                    'Farm owner reassignment in Farm & Map auto-updates owner on devices assigned to the same farm.',
                    'General geofence must be configured before enabling strict user premises and farm fence workflows.',
                ],
            ],
            'owner' => [
                'label' => 'Owner Operations',
                'audience' => 'Poultry Owner',
                'summary' => 'Daily monitoring steps and owner responsibilities with admin-dependent actions.',
                'steps' => [
                    [
                        'id' => 'owner-open-dashboard',
                        'title' => 'Open Dashboard',
                        'description' => 'Use dashboard to monitor eggs, quality score, and device activity.',
                        'action' => 'Review your farm/device context and timeline panels.',
                        'route_name' => 'dashboard',
                    ],
                    [
                        'id' => 'owner-check-coverage',
                        'title' => 'Check Device Coverage',
                        'description' => 'Track active farms/devices and verify expected throughput.',
                        'action' => 'Inspect coverage and top active cards.',
                        'route_name' => 'dashboard',
                    ],
                    [
                        'id' => 'owner-review-my-farms',
                        'title' => 'Review My Farms',
                        'description' => 'Open your farm registry and keep map coordinates updated for each owned site.',
                        'action' => 'Open My Farms and confirm each farm has accurate location details.',
                        'route_name' => 'owner.farms.index',
                    ],
                    [
                        'id' => 'owner-view-machine-blueprint',
                        'title' => 'View Machine Blueprint',
                        'description' => 'Review the machine layout and major call-out sections for device orientation and operator reference.',
                        'action' => 'Open Machine Blueprint and inspect the queue ramp, weighing section, chute, and section bins.',
                        'route_name' => 'machine-blueprint.index',
                    ],
                    [
                        'id' => 'owner-coordinate-update',
                        'title' => 'Request Coordinate/Fence Update',
                        'description' => 'Farm coordinates and fences are managed in admin user edit screens.',
                        'action' => 'Coordinate with admin for map and perimeter updates.',
                        'route_name' => null,
                    ],
                    [
                        'id' => 'owner-device-onboarding',
                        'title' => 'Request Device Assignment',
                        'description' => 'Device registration and owner-farm assignment are admin-controlled.',
                        'action' => 'Provide serial details to admin for assignment.',
                        'route_name' => null,
                    ],
                ],
                'notes' => [
                    'If dashboard shows zero activity despite active farm, ask admin to verify device assignment and farm ownership linkage.',
                ],
            ],
            'staff' => [
                'label' => 'Staff Operations',
                'audience' => 'Poultry Staff',
                'summary' => 'How staff should operate within assigned farm scope and geofence restrictions.',
                'steps' => [
                    [
                        'id' => 'staff-login-geofence',
                        'title' => 'Login Within Allowed Premises',
                        'description' => 'Staff access depends on global geofence and optional user premises zone.',
                        'action' => 'Enable device location before signing in.',
                        'route_name' => 'dashboard',
                    ],
                    [
                        'id' => 'staff-monitor-dashboard',
                        'title' => 'Monitor Assigned Farm Data',
                        'description' => 'Use dashboard filters for farm and device visibility allowed to your account.',
                        'action' => 'Check selected range and active device state.',
                        'route_name' => 'dashboard',
                    ],
                    [
                        'id' => 'staff-view-machine-blueprint',
                        'title' => 'Open Machine Blueprint',
                        'description' => 'Use the blueprint page to identify each machine section before operation or issue reporting.',
                        'action' => 'Review the call-outs for queue ramp, weighing section, chute, and section bins.',
                        'route_name' => 'machine-blueprint.index',
                    ],
                    [
                        'id' => 'staff-issue-reporting',
                        'title' => 'Report Assignment Issues',
                        'description' => 'If farm or device is missing, admin must update assignments.',
                        'action' => 'Report missing farm/device linkage to admin.',
                        'route_name' => null,
                    ],
                ],
                'notes' => [
                    'Staff cannot create device records or manage farms directly.',
                ],
            ],
            'customer' => [
                'label' => 'Customer Operations',
                'audience' => 'Customer',
                'summary' => 'Basic system usage and what to expect from restricted customer access.',
                'steps' => [
                    [
                        'id' => 'customer-login',
                        'title' => 'Sign In With Location Enabled',
                        'description' => 'Customer access still follows geofence rules when enabled.',
                        'action' => 'Allow browser geolocation for reliable access.',
                        'route_name' => 'dashboard',
                    ],
                    [
                        'id' => 'customer-view-dashboard',
                        'title' => 'View Available Monitoring Data',
                        'description' => 'Customer view is limited to allowed contexts by role policies.',
                        'action' => 'Use dashboard filters available to your account.',
                        'route_name' => 'dashboard',
                    ],
                    [
                        'id' => 'customer-request-support',
                        'title' => 'Request Admin Support',
                        'description' => 'Account scope, farm mapping, and device setup are admin-controlled.',
                        'action' => 'Contact admin for any access or assignment concerns.',
                        'route_name' => null,
                    ],
                ],
                'notes' => [
                    'Customer accounts cannot modify geofence, user premises, farm records, or device assignment.',
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function commonIssues(): array
    {
        return [
            [
                'title' => 'Blocked by location check',
                'detail' => 'Enable device location, move within the allowed premises, and sign in again if geofence restrictions are active.',
            ],
            [
                'title' => 'Missing farm or device data',
                'detail' => 'Confirm account assignments with an admin if expected farm or device context does not appear on the dashboard.',
            ],
            [
                'title' => 'No recent ingest activity',
                'detail' => 'Ask the assigned operator or admin to verify the ESP32 device connection and ingest credentials.',
            ],
            [
                'title' => 'Map or fence looks incorrect',
                'detail' => 'Coordinate with an admin because farm coordinates, fences, and geofence settings are maintained in admin tools.',
            ],
        ];
    }
}
