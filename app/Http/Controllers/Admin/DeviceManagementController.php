<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceSerialAlias;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DeviceManagementController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $openModal = strtolower(trim((string) $request->query('open', '')));
        $openEditDeviceId = (int) $request->query('device', 0);
        $openRevealDeviceId = (int) $request->query('show', $request->query('device', 0));

        $devices = Device::query()
            ->with([
                'owner:id,full_name,username',
                'farm:id,farm_name,location,sitio,barangay,municipality,province,is_active',
                'aliases:id,device_id,serial_no',
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('module_board_name', 'like', "%{$search}%")
                        ->orWhere('primary_serial_no', 'like', "%{$search}%")
                        ->orWhereHas('aliases', function ($aliasQuery) use ($search) {
                            $aliasQuery->where('serial_no', 'like', "%{$search}%");
                        })
                        ->orWhereHas('owner', function ($ownerQuery) use ($search) {
                            $ownerQuery->where('full_name', 'like', "%{$search}%")
                                ->orWhere('username', 'like', "%{$search}%");
                        })
                        ->orWhereHas('farm', function ($farmQuery) use ($search) {
                            $farmQuery->where('farm_name', 'like', "%{$search}%")
                                ->orWhere('location', 'like', "%{$search}%")
                                ->orWhere('sitio', 'like', "%{$search}%")
                                ->orWhere('barangay', 'like', "%{$search}%")
                                ->orWhere('municipality', 'like', "%{$search}%")
                                ->orWhere('province', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('id')
            ->paginate(15)
            ->appends($search === '' ? [] : ['q' => $search]);

        $owners = User::query()
            ->where('role', UserRole::OWNER->value)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'username']);

        $farms = Farm::query()
            ->whereNotNull('owner_user_id')
            ->where('is_active', true)
            ->orderBy('farm_name')
            ->get(['id', 'farm_name', 'owner_user_id', 'location', 'sitio', 'barangay', 'municipality', 'province', 'latitude', 'longitude']);

        return view('admin.devices.index', [
            'devices' => $devices,
            'search' => $search,
            'owners' => $owners,
            'farms' => $farms,
            'openModal' => $openModal,
            'openEditDeviceId' => $openEditDeviceId > 0 ? $openEditDeviceId : null,
            'openRevealDeviceId' => $openRevealDeviceId > 0 ? $openRevealDeviceId : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateDevicePayload($request);
        $actorId = $request->user()?->id ? (int) $request->user()->id : null;
        $plainApiKey = $this->generateApiKey();

        $device = DB::transaction(function () use ($validated, $plainApiKey, $actorId): Device {
            $device = Device::query()->create([
                'owner_user_id' => (int) $validated['owner_user_id'],
                'farm_id' => (int) $validated['farm_id'],
                'module_board_name' => $validated['module_board_name'],
                'primary_serial_no' => $validated['primary_serial_no'],
                'main_technical_specs' => $validated['main_technical_specs'] ?? null,
                'processing_memory' => $validated['processing_memory'] ?? null,
                'gpio_interfaces' => $validated['gpio_interfaces'] ?? null,
                'api_key_hash' => Hash::make($plainApiKey),
                'api_key_encrypted' => $plainApiKey,
                'is_active' => true,
                'last_seen_at' => null,
                'last_seen_ip' => null,
                'deactivated_at' => null,
                'created_by_user_id' => $actorId,
                'updated_by_user_id' => $actorId,
            ]);

            $this->syncAliases($device, $validated['aliases']);
            return $device;
        });

        return redirect()
            ->route('admin.devices.index')
            ->with('status', 'Device registered successfully.')
            ->with('device_api_key', $plainApiKey)
            ->with('device_api_key_serial', $device->primary_serial_no);
    }

    public function update(Request $request, Device $device): RedirectResponse
    {
        $validated = $this->validateDevicePayload($request, $device);
        $actorId = $request->user()?->id ? (int) $request->user()->id : null;

        DB::transaction(function () use ($device, $validated, $actorId): void {
            $device->update([
                'owner_user_id' => (int) $validated['owner_user_id'],
                'farm_id' => (int) $validated['farm_id'],
                'module_board_name' => $validated['module_board_name'],
                'primary_serial_no' => $validated['primary_serial_no'],
                'main_technical_specs' => $validated['main_technical_specs'] ?? null,
                'processing_memory' => $validated['processing_memory'] ?? null,
                'gpio_interfaces' => $validated['gpio_interfaces'] ?? null,
                'updated_by_user_id' => $actorId,
            ]);

            $this->syncAliases($device, $validated['aliases']);
        });

        return redirect()
            ->route('admin.devices.index')
            ->with('status', 'Device profile updated.');
    }

    public function deactivate(Request $request, Device $device): RedirectResponse
    {
        $device->update([
            'is_active' => false,
            'deactivated_at' => Carbon::now(),
            'updated_by_user_id' => $request->user()?->id ? (int) $request->user()->id : null,
        ]);

        return redirect()
            ->route('admin.devices.index')
            ->with('status', "Device {$device->primary_serial_no} deactivated.");
    }

    public function reactivate(Request $request, Device $device): RedirectResponse
    {
        $device->update([
            'is_active' => true,
            'deactivated_at' => null,
            'updated_by_user_id' => $request->user()?->id ? (int) $request->user()->id : null,
        ]);

        return redirect()
            ->route('admin.devices.index')
            ->with('status', "Device {$device->primary_serial_no} reactivated.");
    }

    public function rotateKey(Request $request, Device $device): RedirectResponse
    {
        $newApiKey = $this->generateApiKey();
        $device->update([
            'api_key_hash' => Hash::make($newApiKey),
            'api_key_encrypted' => $newApiKey,
            'updated_by_user_id' => $request->user()?->id ? (int) $request->user()->id : null,
        ]);

        return redirect()
            ->route('admin.devices.index')
            ->with('status', "API key rotated for device {$device->primary_serial_no}.")
            ->with('device_api_key', $newApiKey)
            ->with('device_api_key_serial', $device->primary_serial_no);
    }

    public function showKey(Request $request, Device $device): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'current_password'],
        ], [
            'current_password.required' => 'Admin password is required to show the device API key.',
            'current_password.current_password' => 'Admin password is incorrect.',
        ]);

        if ($validator->fails()) {
            return redirect()
                ->route('admin.devices.index', [
                    'open' => 'reveal-key',
                    'show' => $device->id,
                ])
                ->withErrors($validator)
                ->withInput([
                    'device_action_mode' => 'reveal-key',
                    'device_id' => $device->id,
                ]);
        }

        $plainApiKey = $device->api_key_encrypted;
        if (!is_string($plainApiKey) || trim($plainApiKey) === '') {
            return redirect()
                ->route('admin.devices.index', [
                    'open' => 'reveal-key',
                    'show' => $device->id,
                ])
                ->withErrors([
                    'current_password' => 'This device key cannot be shown again because it predates secure re-display. Rotate the key to issue a new one.',
                ])
                ->withInput([
                    'device_action_mode' => 'reveal-key',
                    'device_id' => $device->id,
                ]);
        }

        return redirect()
            ->route('admin.devices.index')
            ->with('status', "API key shown for device {$device->primary_serial_no}.")
            ->with('device_api_key', $plainApiKey)
            ->with('device_api_key_serial', $device->primary_serial_no);
    }

    public function ownerFarms(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'owner_user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(static function ($query): void {
                    $query->where('role', UserRole::OWNER->value);
                }),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid owner selection.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $ownerUserId = (int) $validator->validated()['owner_user_id'];

        $farms = Farm::query()
            ->where('owner_user_id', $ownerUserId)
            ->where('is_active', true)
            ->orderBy('farm_name')
            ->get(['id', 'farm_name', 'owner_user_id', 'location', 'sitio', 'barangay', 'municipality', 'province', 'latitude', 'longitude'])
            ->map(static function (Farm $farm): array {
                $locationParts = array_values(array_filter([
                    $farm->location,
                    $farm->sitio,
                    $farm->barangay,
                    $farm->municipality,
                    $farm->province,
                ], static fn ($value): bool => is_string($value) && trim($value) !== ''));

                return [
                    'id' => (int) $farm->id,
                    'farm_name' => (string) $farm->farm_name,
                    'owner_user_id' => (int) $farm->owner_user_id,
                    'location_label' => $locationParts !== [] ? implode(', ', $locationParts) : 'Location not set',
                    'latitude' => $farm->latitude !== null ? (float) $farm->latitude : null,
                    'longitude' => $farm->longitude !== null ? (float) $farm->longitude : null,
                ];
            })
            ->values();

        return response()->json([
            'ok' => true,
            'data' => $farms,
        ]);
    }

    /**
     * @return array{
     *   owner_user_id:int,
     *   farm_id:int,
     *   module_board_name:string,
     *   primary_serial_no:string,
     *   aliases:array<int, string>,
     *   main_technical_specs:string|null,
     *   processing_memory:string|null,
     *   gpio_interfaces:string|null
     * }
     */
    private function validateDevicePayload(Request $request, ?Device $device = null): array
    {
        $primaryUniqueRule = Rule::unique('devices', 'primary_serial_no');
        if ($device !== null) {
            $primaryUniqueRule = $primaryUniqueRule->ignore((int) $device->id);
        }

        $validator = Validator::make($request->all(), [
            'owner_user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'farm_id' => ['required', 'integer', Rule::exists('farms', 'id')],
            'module_board_name' => ['required', 'string', 'max:120'],
            'primary_serial_no' => [
                'required',
                'string',
                'max:120',
                $primaryUniqueRule,
            ],
            'aliases_text' => ['nullable', 'string', 'max:3000'],
            'main_technical_specs' => ['nullable', 'string'],
            'processing_memory' => ['nullable', 'string'],
            'gpio_interfaces' => ['nullable', 'string'],
            'device_form_mode' => ['nullable', 'string', Rule::in(['create', 'edit'])],
            'device_id' => ['nullable', 'integer'],
        ]);

        $ownerUserId = (int) $request->input('owner_user_id');
        $farmId = (int) $request->input('farm_id');
        $primarySerialNo = Device::normalizeSerial((string) $request->input('primary_serial_no', ''));
        $aliases = $this->extractAliases((string) $request->input('aliases_text', ''), $primarySerialNo);

        $validator->after(function ($validator) use ($ownerUserId, $farmId, $primarySerialNo, $aliases, $device): void {
            $owner = User::query()->find($ownerUserId);
            if (!$owner || !$owner->isOwner()) {
                $validator->errors()->add('owner_user_id', 'Selected user must be a Poultry Owner.');
                return;
            }

            $farm = Farm::query()
                ->where('id', $farmId)
                ->first(['id', 'owner_user_id', 'is_active']);

            if (!$farm || (int) $farm->owner_user_id !== $ownerUserId) {
                $validator->errors()->add('farm_id', 'Selected farm does not belong to the selected owner.');
            } elseif (!$farm->is_active) {
                $validator->errors()->add('farm_id', 'Selected farm is inactive. Please choose an active farm.');
            }

            if ($primarySerialNo === '') {
                $validator->errors()->add('primary_serial_no', 'Primary serial number is required.');
                return;
            }

            $primarySerialConflict = Device::query()
                ->where('primary_serial_no', $primarySerialNo)
                ->when($device !== null, function ($query) use ($device) {
                    $query->where('id', '!=', (int) $device->id);
                })
                ->exists();

            if ($primarySerialConflict) {
                $validator->errors()->add('primary_serial_no', 'Primary serial number is already registered.');
            }

            $primaryAliasConflict = DeviceSerialAlias::query()
                ->where('serial_no', $primarySerialNo)
                ->when($device !== null, function ($query) use ($device) {
                    $query->where('device_id', '!=', (int) $device->id);
                })
                ->exists();

            if ($primaryAliasConflict) {
                $validator->errors()->add('primary_serial_no', 'Primary serial number is already used as an alias.');
            }

            if ($aliases === []) {
                return;
            }

            $conflictingPrimarySerials = Device::query()
                ->whereIn('primary_serial_no', $aliases)
                ->when($device !== null, function ($query) use ($device) {
                    $query->where('id', '!=', (int) $device->id);
                })
                ->pluck('primary_serial_no')
                ->all();

            if ($conflictingPrimarySerials !== []) {
                $validator->errors()->add('aliases_text', 'One or more alias serials are already used as primary serial numbers.');
            }

            $conflictingAliases = DeviceSerialAlias::query()
                ->whereIn('serial_no', $aliases)
                ->when($device !== null, function ($query) use ($device) {
                    $query->where('device_id', '!=', (int) $device->id);
                })
                ->pluck('serial_no')
                ->all();

            if ($conflictingAliases !== []) {
                $validator->errors()->add('aliases_text', 'One or more alias serials are already assigned to another device.');
            }
        });

        $validated = $validator->validate();
        $validated['primary_serial_no'] = $primarySerialNo;
        $validated['aliases'] = $aliases;

        return $validated;
    }

    /**
     * @return array<int, string>
     */
    private function extractAliases(string $rawAliases, string $primarySerialNo): array
    {
        $segments = preg_split('/[\r\n,]+/', $rawAliases) ?: [];
        $aliases = [];

        foreach ($segments as $segment) {
            $normalized = Device::normalizeSerial($segment);
            if ($normalized === '' || $normalized === $primarySerialNo) {
                continue;
            }
            $aliases[] = $normalized;
        }

        return array_values(array_unique($aliases));
    }

    /**
     * @param array<int, string> $aliases
     */
    private function syncAliases(Device $device, array $aliases): void
    {
        $device->aliases()->delete();

        foreach ($aliases as $serialNo) {
            $device->aliases()->create([
                'serial_no' => $serialNo,
            ]);
        }
    }

    private function generateApiKey(): string
    {
        $prefix = 'eggpulse_';
        $requiredCharacters = ['r', 'y', 'h', 'n'];
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';

        do {
            $characters = $requiredCharacters;

            while (count($characters) < 12) {
                $characters[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            shuffle($characters);
            $body = implode('', $characters);
        } while ($this->containsOrderedMarkerSequence($body, $requiredCharacters));

        return $prefix . $body;
    }

    /**
     * @param array<int, string> $sequence
     */
    private function containsOrderedMarkerSequence(string $value, array $sequence): bool
    {
        $sequenceIndex = 0;
        $sequenceLength = count($sequence);

        if ($sequenceLength === 0) {
            return false;
        }

        foreach (str_split(strtolower($value)) as $character) {
            if ($character !== $sequence[$sequenceIndex]) {
                continue;
            }

            $sequenceIndex += 1;
            if ($sequenceIndex === $sequenceLength) {
                return true;
            }
        }

        return false;
    }
}
