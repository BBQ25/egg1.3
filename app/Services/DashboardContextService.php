<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DashboardContextService
{
    public const SESSION_FARM_ID = 'dashboard.context_farm_id';
    public const SESSION_DEVICE_ID = 'dashboard.context_device_id';
    public const SESSION_RANGE = 'dashboard.range';

    public const RANGE_1D = '1d';
    public const RANGE_1W = '1w';
    public const RANGE_1M = '1m';

    /**
     * @return array<int, string>
     */
    public static function allowedRanges(): array
    {
        return [
            self::RANGE_1D,
            self::RANGE_1W,
            self::RANGE_1M,
        ];
    }

    /**
     * @return array{
     *   range:string,
     *   switcher:array{
     *     farms:array<int, array{id:int,name:string,owner_name:string|null,location:string,is_active:bool}>,
     *     devices:array<int, array{id:int,name:string,serial:string,farm_id:int|null,farm_name:string|null,owner_name:string|null,is_active:bool,last_seen_at:string|null}>
     *   },
     *   selected:array{
     *     farm_id:int|null,
     *     device_id:int|null,
     *     farm:array{id:int,name:string,owner_name:string|null,location:string,is_active:bool}|null,
     *     device:array{id:int,name:string,serial:string,farm_id:int|null,farm_name:string|null,owner_name:string|null,is_active:bool,last_seen_at:string|null}|null
     *   },
     *   scope:array{
     *     role:string,
     *     farm_ids:array<int,int>,
     *     device_ids:array<int,int>
     *   }
     * }
     */
    public function resolve(Request $request, User $user): array
    {
        $role = $this->roleKey($user);

        $farms = $this->accessibleFarms($user)->values();
        $devices = $this->accessibleDevices($user, $farms)->values();

        $farmOptions = $farms->map(function (Farm $farm): array {
            $location = array_values(array_filter([
                $farm->location,
                $farm->sitio,
                $farm->barangay,
                $farm->municipality,
                $farm->province,
            ], static fn ($value): bool => is_string($value) && trim($value) !== ''));

            return [
                'id' => (int) $farm->id,
                'name' => (string) $farm->farm_name,
                'owner_name' => $farm->relationLoaded('owner') && $farm->owner ? (string) $farm->owner->full_name : null,
                'location' => $location === [] ? 'Location not set' : implode(', ', $location),
                'is_active' => (bool) $farm->is_active,
            ];
        })->all();

        $deviceOptions = $devices->map(function (Device $device): array {
            return [
                'id' => (int) $device->id,
                'name' => (string) $device->module_board_name,
                'serial' => (string) $device->primary_serial_no,
                'farm_id' => $device->farm_id !== null ? (int) $device->farm_id : null,
                'farm_name' => $device->relationLoaded('farm') && $device->farm ? (string) $device->farm->farm_name : null,
                'owner_name' => $device->relationLoaded('owner') && $device->owner ? (string) $device->owner->full_name : null,
                'is_active' => (bool) $device->is_active,
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'last_seen_ip' => $device->last_seen_ip ? (string) $device->last_seen_ip : null,
                'created_at' => $device->created_at?->toIso8601String(),
            ];
        })->all();

        $allowedFarmIds = array_values(array_map(static fn (array $farm): int => (int) $farm['id'], $farmOptions));
        $allowedDeviceIds = array_values(array_map(static fn (array $device): int => (int) $device['id'], $deviceOptions));

        $range = $this->resolveRange($request);

        $selectedFarmId = $this->resolveContextId($request, self::SESSION_FARM_ID, 'context_farm_id');
        $selectedDeviceId = $this->resolveContextId($request, self::SESSION_DEVICE_ID, 'context_device_id');

        if ($selectedFarmId !== null && !in_array($selectedFarmId, $allowedFarmIds, true)) {
            throw new AuthorizationException('Selected farm is outside your dashboard scope.');
        }

        if ($selectedDeviceId !== null && !in_array($selectedDeviceId, $allowedDeviceIds, true)) {
            throw new AuthorizationException('Selected device is outside your dashboard scope.');
        }

        $devicesById = [];
        foreach ($deviceOptions as $deviceOption) {
            $devicesById[(int) $deviceOption['id']] = $deviceOption;
        }

        if ($selectedDeviceId !== null && isset($devicesById[$selectedDeviceId])) {
            $deviceFarmId = $devicesById[$selectedDeviceId]['farm_id'];
            if ($selectedFarmId !== null && $deviceFarmId !== null && $selectedFarmId !== (int) $deviceFarmId) {
                throw new AuthorizationException('Selected device does not belong to the selected farm.');
            }

            if ($selectedFarmId === null && $deviceFarmId !== null) {
                $selectedFarmId = (int) $deviceFarmId;
                $request->session()->put(self::SESSION_FARM_ID, $selectedFarmId);
            }
        }

        if ($selectedFarmId !== null && $selectedDeviceId !== null && isset($devicesById[$selectedDeviceId])) {
            $deviceFarmId = $devicesById[$selectedDeviceId]['farm_id'];
            if ($deviceFarmId !== null && (int) $deviceFarmId !== $selectedFarmId) {
                $selectedDeviceId = null;
                $request->session()->forget(self::SESSION_DEVICE_ID);
            }
        }

        $selectedFarm = $selectedFarmId === null
            ? null
            : collect($farmOptions)->first(static fn (array $farm): bool => (int) $farm['id'] === $selectedFarmId);

        $selectedDevice = $selectedDeviceId === null
            ? null
            : $devicesById[$selectedDeviceId] ?? null;

        return [
            'range' => $range,
            'switcher' => [
                'farms' => $farmOptions,
                'devices' => $deviceOptions,
            ],
            'selected' => [
                'farm_id' => $selectedFarmId,
                'device_id' => $selectedDeviceId,
                'farm' => $selectedFarm,
                'device' => $selectedDevice,
            ],
            'scope' => [
                'role' => $role,
                'farm_ids' => $allowedFarmIds,
                'device_ids' => $allowedDeviceIds,
            ],
        ];
    }

    private function resolveRange(Request $request): string
    {
        $input = strtolower(trim((string) $request->query('range', '')));

        if ($input !== '' && in_array($input, self::allowedRanges(), true)) {
            $request->session()->put(self::SESSION_RANGE, $input);

            return $input;
        }

        if ($request->has('range') && $input === '') {
            $request->session()->forget(self::SESSION_RANGE);
        }

        $sessionRange = strtolower(trim((string) $request->session()->get(self::SESSION_RANGE, '')));
        if (in_array($sessionRange, self::allowedRanges(), true)) {
            return $sessionRange;
        }

        return self::RANGE_1D;
    }

    private function resolveContextId(Request $request, string $sessionKey, string $queryKey): ?int
    {
        if ($request->has($queryKey)) {
            $raw = trim((string) $request->query($queryKey, ''));
            if ($raw === '') {
                $request->session()->forget($sessionKey);

                return null;
            }

            if (ctype_digit($raw)) {
                $value = (int) $raw;
                $request->session()->put($sessionKey, $value);

                return $value;
            }

            throw new AuthorizationException('Invalid dashboard context selection.');
        }

        $sessionValue = $request->session()->get($sessionKey);
        if (is_numeric($sessionValue)) {
            return (int) $sessionValue;
        }

        return null;
    }

    /**
     * @return Collection<int, Farm>
     */
    private function accessibleFarms(User $user): Collection
    {
        $query = Farm::query()
            ->with(['owner:id,full_name'])
            ->orderBy('farm_name');

        if ($user->isAdmin()) {
            return $query->get(['id', 'farm_name', 'location', 'sitio', 'barangay', 'municipality', 'province', 'owner_user_id', 'is_active']);
        }

        if ($user->isOwner()) {
            return $query
                ->where('owner_user_id', (int) $user->id)
                ->get(['id', 'farm_name', 'location', 'sitio', 'barangay', 'municipality', 'province', 'owner_user_id', 'is_active']);
        }

        if ($user->isStaff()) {
            $farmIds = $user->staffFarms()->pluck('farms.id')->map(static fn ($id): int => (int) $id)->all();

            if ($farmIds === []) {
                return collect();
            }

            return $query
                ->whereIn('id', $farmIds)
                ->get(['id', 'farm_name', 'location', 'sitio', 'barangay', 'municipality', 'province', 'owner_user_id', 'is_active']);
        }

        return collect();
    }

    /**
     * @param Collection<int, Farm> $farms
     *
     * @return Collection<int, Device>
     */
    private function accessibleDevices(User $user, Collection $farms): Collection
    {
        $query = Device::query()
            ->with([
                'farm:id,farm_name',
                'owner:id,full_name',
            ])
            ->orderBy('module_board_name');

        if ($user->isAdmin()) {
            return $query->get(['id', 'module_board_name', 'primary_serial_no', 'farm_id', 'owner_user_id', 'is_active', 'last_seen_at', 'last_seen_ip', 'created_at']);
        }

        if ($user->isOwner()) {
            return $query
                ->where('owner_user_id', (int) $user->id)
                ->get(['id', 'module_board_name', 'primary_serial_no', 'farm_id', 'owner_user_id', 'is_active', 'last_seen_at', 'last_seen_ip', 'created_at']);
        }

        if ($user->isStaff()) {
            $farmIds = $farms->pluck('id')->map(static fn ($id): int => (int) $id)->all();

            if ($farmIds === []) {
                return collect();
            }

            return $query
                ->whereIn('farm_id', $farmIds)
                ->get(['id', 'module_board_name', 'primary_serial_no', 'farm_id', 'owner_user_id', 'is_active', 'last_seen_at', 'last_seen_ip', 'created_at']);
        }

        return collect();
    }

    private function roleKey(User $user): string
    {
        if ($user->isAdmin()) {
            return 'admin';
        }

        if ($user->isOwner()) {
            return 'owner';
        }

        if ($user->isStaff()) {
            return 'staff';
        }

        return 'customer';
    }
}
