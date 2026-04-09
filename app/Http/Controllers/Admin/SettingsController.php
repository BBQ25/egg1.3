<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Models\User;
use App\Support\AppTimezone;
use App\Support\EggWeightRanges;
use App\Support\FarmPremises;
use App\Support\Geofence;
use App\Support\LoginClickBypass;
use App\Support\UiFont;
use App\Support\MenuVisibility;
use InvalidArgumentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        return view('admin.settings.edit', $this->settingsPageViewData());
    }

    public function accessBoundary(): View
    {
        return view('admin.settings.access-boundary', [
            'geofenceSettings' => Geofence::settings(),
            'geofenceShapeOptions' => Geofence::shapeOptions(),
            'geofenceMapPayload' => Geofence::mapPayload(),
        ]);
    }

    public function updateAccessBoundary(Request $request): RedirectResponse
    {
        $geofenceEnabled = $request->boolean('geofence_enabled');

        $validated = $request->validate([
            'geofence_enabled' => ['nullable', 'boolean'],
            'geofence_shape_type' => [$geofenceEnabled ? 'required' : 'nullable', Rule::in(array_keys(Geofence::shapeOptions()))],
            'geofence_geometry' => [$geofenceEnabled ? 'required' : 'nullable', 'string'],
        ]);

        $shapeType = strtoupper(trim((string) ($validated['geofence_shape_type'] ?? '')));
        $geometryRaw = trim((string) ($validated['geofence_geometry'] ?? ''));
        $decodedGeometry = $geometryRaw === '' ? null : json_decode($geometryRaw, true);
        $zones = $this->parseGeofenceZonesFromDecoded($decodedGeometry, $shapeType);

        if ($geofenceEnabled && $zones === []) {
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['geofence_geometry' => 'Please draw and save a valid geofence on the map.']);
        }

        try {
            if ($zones !== []) {
                Geofence::saveZones(
                    $geofenceEnabled,
                    $zones,
                    $request->user()?->id ? (int) $request->user()->id : null
                );
            } else {
                Geofence::setEnabled($geofenceEnabled);
            }
        } catch (InvalidArgumentException) {
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['geofence_geometry' => 'The drawn geofence is invalid. Please redraw and try again.']);
        }

        return redirect()
            ->route('admin.settings.access-boundary')
            ->with('status', 'Access boundary updated successfully.');
    }

    public function locationOverview(): View
    {
        $farmLocations = $this->farmLocations();

        return view('admin.settings.location-overview', [
            'geofenceSettings' => Geofence::settings(),
            'farmLocationsMapPayload' => FarmPremises::mapPayloadForFarms($farmLocations),
        ]);
    }

    /**
     * @return array<string, array{color: string, icon: string, pages: array<int, string>}>
     */
    private function discoverCategorizedPages(): array
    {
        $pageDirectories = [
            public_path('sneat/html/vertical-menu-template/'),
            public_path('forms/'),
        ];
        $categorizedPages = [
            'Apps & Pages' => ['color' => 'bg-label-primary', 'icon' => 'bx-grid-alt', 'pages' => []],
            'Components' => ['color' => 'bg-label-info', 'icon' => 'bx-box', 'pages' => []],
            'Forms and Tables' => ['color' => 'bg-label-success', 'icon' => 'bx-detail', 'pages' => []],
            'Charts and Maps' => ['color' => 'bg-label-warning', 'icon' => 'bx-pie-chart-alt-2', 'pages' => []],
            'Misc' => ['color' => 'bg-label-secondary', 'icon' => 'bx-shape-polygon', 'pages' => []],
        ];

        $pageNames = [];
        foreach ($pageDirectories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $files = glob($directory . '*.php') ?: [];
            foreach ($files as $file) {
                $basename = basename($file, '.php');
                if ($basename === 'index') {
                    continue;
                }

                $pageNames[] = $basename;
            }
        }

        $pageNames = array_values(array_unique($pageNames));

        foreach ($pageNames as $basename) {
            if (
                str_starts_with($basename, 'app-') ||
                str_starts_with($basename, 'pages-') ||
                str_starts_with($basename, 'auth-') ||
                str_starts_with($basename, 'dashboards-') ||
                str_starts_with($basename, 'layouts-') ||
                str_starts_with($basename, 'front-pages-')
            ) {
                $categorizedPages['Apps & Pages']['pages'][] = $basename;
            } elseif (
                str_starts_with($basename, 'ui-') ||
                str_starts_with($basename, 'extended-ui-') ||
                str_starts_with($basename, 'cards-')
            ) {
                $categorizedPages['Components']['pages'][] = $basename;
            } elseif (
                str_starts_with($basename, 'forms-') ||
                str_starts_with($basename, 'form-') ||
                str_starts_with($basename, 'tables-')
            ) {
                $categorizedPages['Forms and Tables']['pages'][] = $basename;
            } elseif (
                str_starts_with($basename, 'charts-') ||
                str_starts_with($basename, 'maps-')
            ) {
                $categorizedPages['Charts and Maps']['pages'][] = $basename;
            } else {
                $categorizedPages['Misc']['pages'][] = $basename;
            }
        }

        // Virtual entries for sidebar links that are not backed by vertical template PHP files.
        $virtualPages = [
            'Apps & Pages' => [
                'layouts',
                'front-pages',
                'front-pages-landing-page',
                'front-pages-pricing-page',
                'front-pages-payment-page',
                'front-pages-checkout-page',
                'front-pages-help-center-landing',
            ],
            'Misc' => [
                'multi-level',
                'support',
                'documentation',
            ],
        ];

        foreach ($virtualPages as $category => $pages) {
            if (!isset($categorizedPages[$category])) {
                continue;
            }

            $categorizedPages[$category]['pages'] = array_merge($categorizedPages[$category]['pages'], $pages);
        }

        foreach ($categorizedPages as &$categoryData) {
            $categoryData['pages'] = array_values(array_unique($categoryData['pages']));
            sort($categoryData['pages']);
        }
        unset($categoryData);

        return array_filter($categorizedPages, static fn(array $category): bool => count($category['pages']) > 0);
    }

    /**
     * @return array<int, string>
     */
    private function discoverAllPages(): array
    {
        $pages = [];
        foreach ($this->discoverCategorizedPages() as $category) {
            $pages = array_merge($pages, $category['pages']);
        }

        return array_values(array_unique($pages));
    }

    private function buildHierarchy(array $pages): array
    {
        $hierarchy = [];

        foreach ($pages as $page) {
            $parts = $this->hierarchySegmentsForPage($page);
            $current = &$hierarchy;

            foreach ($parts as $index => $part) {
                if ($index === count($parts) - 1) {
                    // Leaf node (the actual checkbox)
                    $current['__leaves'][] = [
                        'label' => $this->displayTitleForPage($page),
                        'value' => $page,
                    ];
                } else {
                    // Folder node
                    if (!isset($current[$part])) {
                        $current[$part] = ['__leaves' => []];
                    }
                    $current = &$current[$part];
                }
            }
        }

        return $hierarchy;
    }

    /**
     * @return array<int, string>
     */
    private function hierarchySegmentsForPage(string $page): array
    {
        $normalized = strtolower(trim($page));

        $singleKeyLabels = [
            'layouts' => 'Layouts',
            'front-pages' => 'Front Pages',
            'multi-level' => 'Multi Level',
            'support' => 'Support',
            'documentation' => 'Documentation',
        ];

        if (isset($singleKeyLabels[$normalized])) {
            return [$singleKeyLabels[$normalized]];
        }

        $prefixGroups = [
            'front-pages-' => 'Front Pages',
            'layouts-' => 'Layouts',
            'form-layouts-' => 'Form Layouts',
        ];

        foreach ($prefixGroups as $prefix => $groupLabel) {
            if (!str_starts_with($normalized, $prefix)) {
                continue;
            }

            $tail = substr($normalized, strlen($prefix));

            if ($tail === '' || $tail === false) {
                return [$groupLabel];
            }

            return [$groupLabel, $this->humanizeSlug($tail)];
        }

        $parts = array_values(array_filter(explode('-', $normalized), static fn(string $part): bool => $part !== ''));

        if ($parts === []) {
            return [ucfirst($normalized)];
        }

        return array_map(fn(string $part): string => $this->humanizeSlug($part), $parts);
    }

    private function displayTitleForPage(string $page): string
    {
        return implode(' > ', $this->hierarchySegmentsForPage($page));
    }

    private function humanizeSlug(string $value): string
    {
        $tokens = array_values(array_filter(explode('-', strtolower(trim($value))), static fn(string $token): bool => $token !== ''));

        if ($tokens === []) {
            return ucfirst($value);
        }

        $dictionary = [
            'api' => 'API',
            'crm' => 'CRM',
            'faq' => 'FAQ',
            'id' => 'ID',
            'ui' => 'UI',
            'esp32' => 'ESP32',
            'ecommerce' => 'eCommerce',
        ];

        $words = array_map(static function (string $token) use ($dictionary): string {
            if (isset($dictionary[$token])) {
                return $dictionary[$token];
            }

            return ucfirst($token);
        }, $tokens);

        return implode(' ', $words);
    }

    public function update(Request $request): RedirectResponse
    {
        $allowedPages = $this->discoverAllPages();
        $rules = [
            'app_timezone' => ['required', Rule::in(array_keys(AppTimezone::options()))],
            'font_style' => ['required', Rule::in(array_keys(UiFont::options()))],
            'disabled_pages' => ['nullable', 'array'],
            'disabled_pages.*' => ['string', Rule::in($allowedPages)],
            'egg_weight_ranges' => ['required', 'array'],
        ];

        foreach (EggWeightRanges::definitions() as $definition) {
            $slug = $definition['slug'];
            $rules["egg_weight_ranges.{$slug}.min"] = ['required', 'numeric', 'min:0'];
            $rules["egg_weight_ranges.{$slug}.max"] = ['required', 'numeric', 'min:0'];
        }

        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request): void {
            $ranges = $request->input('egg_weight_ranges', []);

            if (!is_array($ranges)) {
                return;
            }

            foreach (EggWeightRanges::configurationErrors($ranges) as $key => $message) {
                $validator->errors()->add($key, $message);
            }

            $this->validateLoginBypassSettings($validator, $request);
        });

        $validated = $validator->validate();

        AppTimezone::set($validated['app_timezone']);
        UiFont::set($validated['font_style']);
        MenuVisibility::setDisabled($validated['disabled_pages'] ?? []);
        EggWeightRanges::set($validated['egg_weight_ranges']);
        $this->persistLoginBypassSettings($request);

        return redirect()
            ->route('admin.settings.edit')
            ->with('status', "Admin settings updated successfully.");
    }

    /**
     * @return array{
     *   timezoneOptions:array<string,string>,
     *   currentAppTimezone:string,
     *   currentAppTimezoneLabel:string,
     *   fontOptions:array<string,string>,
     *   currentFontStyle:string,
     *   eggWeightRanges:array<string,array{slug:string,class:string,label:string,min:string,max:string}>,
     *   categorizedPages:array<string,array{color:string,icon:string,pages:array<int,string>,hierarchy:array}>,
     *   disabledPages:array<int,string>,
     *   loginBypassAvailable:bool,
     *   loginBypassEnabled:bool,
     *   loginBypassRules:array<int,array{id:int,rule_label:string,click_count:int,window_seconds:int,target_user_id:int,is_enabled:bool}>,
     *   loginBypassUsers:array<int,array{id:int,full_name:string,username:string,role:string,is_active:bool,registration_status:string}>,
     *   userRoleLabels:array<string,string>
     * }
     */
    private function settingsPageViewData(): array
    {
        $categorizedPages = $this->discoverCategorizedPages();

        foreach ($categorizedPages as &$categoryData) {
            $categoryData['hierarchy'] = $this->buildHierarchy($categoryData['pages']);
        }
        unset($categoryData);

        $loginBypassAvailable = LoginClickBypass::featureAllowed() && Schema::hasTable('login_click_bypass_rules');
        $loginBypassEnabled = false;
        $loginBypassRules = [];
        $loginBypassUsers = [];

        if ($loginBypassAvailable) {
            LoginClickBypass::ensureSeeded();
            $loginBypassEnabled = LoginClickBypass::isEnabled();
            $loginBypassRules = DB::table('login_click_bypass_rules')
                ->orderByDesc('click_count')
                ->orderBy('window_seconds')
                ->orderBy('id')
                ->get([
                    'id',
                    'rule_label',
                    'click_count',
                    'window_seconds',
                    'target_user_id',
                    'is_enabled',
                ])
                ->map(static function ($row): array {
                    return [
                        'id' => (int) ($row->id ?? 0),
                        'rule_label' => (string) ($row->rule_label ?? ''),
                        'click_count' => (int) ($row->click_count ?? 0),
                        'window_seconds' => (int) ($row->window_seconds ?? 0),
                        'target_user_id' => (int) ($row->target_user_id ?? 0),
                        'is_enabled' => (bool) ($row->is_enabled ?? false),
                    ];
                })
                ->values()
                ->all();

            $loginBypassUsers = User::query()
                ->select(['id', 'full_name', 'username', 'role', 'is_active', 'registration_status'])
                ->orderBy('role')
                ->orderBy('full_name')
                ->get()
                ->map(static function (User $user): array {
                    return [
                        'id' => $user->id,
                        'full_name' => (string) $user->full_name,
                        'username' => (string) $user->username,
                        'role' => (string) $user->role->value,
                        'is_active' => (bool) $user->is_active,
                        'registration_status' => (string) $user->registration_status->value,
                    ];
                })
                ->values()
                ->all();
        }

        return [
            'timezoneOptions' => AppTimezone::options(),
            'currentAppTimezone' => AppTimezone::current(),
            'currentAppTimezoneLabel' => AppTimezone::label(),
            'fontOptions' => UiFont::options(),
            'currentFontStyle' => UiFont::current(),
            'eggWeightRanges' => EggWeightRanges::current(),
            'categorizedPages' => $categorizedPages,
            'disabledPages' => MenuVisibility::getDisabled(),
            'loginBypassAvailable' => $loginBypassAvailable,
            'loginBypassEnabled' => $loginBypassEnabled,
            'loginBypassRules' => $loginBypassRules,
            'loginBypassUsers' => $loginBypassUsers,
            'userRoleLabels' => UserRole::labels(),
        ];
    }

    private function validateLoginBypassSettings($validator, Request $request): void
    {
        if (!LoginClickBypass::featureAllowed() || !Schema::hasTable('login_click_bypass_rules')) {
            return;
        }

        $rawRules = $request->input('login_bypass_rules', []);
        if (!is_array($rawRules)) {
            return;
        }

        $patternKeys = [];
        foreach ($rawRules as $index => $row) {
            $row = is_array($row) ? $row : [];
            $delete = filter_var($row['delete'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $ruleId = isset($row['id']) ? (int) $row['id'] : 0;
            $clickCount = $row['click_count'] ?? null;
            $windowSeconds = $row['window_seconds'] ?? null;
            $targetUserId = $row['target_user_id'] ?? null;
            $ruleLabel = trim((string) ($row['rule_label'] ?? ''));

            $hasAny =
                $clickCount !== null ||
                $windowSeconds !== null ||
                $targetUserId !== null ||
                $ruleLabel !== '';

            if ($delete && $ruleId <= 0) {
                continue;
            }

            if (!$hasAny && $ruleId <= 0) {
                continue;
            }

            if ($ruleId > 0 && !DB::table('login_click_bypass_rules')->where('id', $ruleId)->exists()) {
                $validator->errors()->add("login_bypass_rules.{$index}.id", 'Bypass rule was not found.');
                continue;
            }

            if (!is_numeric($clickCount) || (int) $clickCount < 2 || (int) $clickCount > 20) {
                $validator->errors()->add("login_bypass_rules.{$index}.click_count", 'Clicks must be between 2 and 20.');
            }

            if (!is_numeric($windowSeconds) || (int) $windowSeconds < 1 || (int) $windowSeconds > 30) {
                $validator->errors()->add("login_bypass_rules.{$index}.window_seconds", 'Window must be between 1 and 30 seconds.');
            }

            if (!is_numeric($targetUserId) || (int) $targetUserId <= 0) {
                $validator->errors()->add("login_bypass_rules.{$index}.target_user_id", 'Select a target user.');
            } elseif (!User::query()->whereKey((int) $targetUserId)->exists()) {
                $validator->errors()->add("login_bypass_rules.{$index}.target_user_id", 'Target user was not found.');
            }

            if (is_numeric($clickCount) && is_numeric($windowSeconds)) {
                $patternKey = (int) $clickCount . ':' . (int) $windowSeconds;
                if (isset($patternKeys[$patternKey])) {
                    $validator->errors()->add("login_bypass_rules.{$index}.click_count", 'This click/window pattern is already used.');
                } else {
                    $patternKeys[$patternKey] = true;
                }
            }
        }
    }

    private function persistLoginBypassSettings(Request $request): void
    {
        if (!LoginClickBypass::featureAllowed() || !Schema::hasTable('login_click_bypass_rules')) {
            return;
        }

        $rawRules = $request->input('login_bypass_rules', []);
        if (!is_array($rawRules)) {
            $rawRules = [];
        }

        DB::transaction(function () use ($request, $rawRules): void {
            LoginClickBypass::setEnabled($request->boolean('login_bypass_enabled'));

            foreach ($rawRules as $row) {
                $row = is_array($row) ? $row : [];
                $delete = filter_var($row['delete'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $ruleId = isset($row['id']) ? (int) $row['id'] : 0;
                $clickCount = isset($row['click_count']) ? (int) $row['click_count'] : 0;
                $windowSeconds = isset($row['window_seconds']) ? (int) $row['window_seconds'] : 0;
                $targetUserId = isset($row['target_user_id']) ? (int) $row['target_user_id'] : 0;
                $ruleLabel = trim((string) ($row['rule_label'] ?? ''));
                $isEnabled = filter_var($row['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

                $hasAny =
                    $clickCount > 0 ||
                    $windowSeconds > 0 ||
                    $targetUserId > 0 ||
                    $ruleLabel !== '';

                if ($delete) {
                    if ($ruleId > 0) {
                        DB::table('login_click_bypass_rules')->where('id', $ruleId)->delete();
                    }
                    continue;
                }

                if (!$hasAny) {
                    continue;
                }

                $clickCount = max(2, min(20, $clickCount));
                $windowSeconds = max(1, min(30, $windowSeconds));
                if ($ruleLabel === '') {
                    $ruleLabel = sprintf('Bypass: %d clicks', $clickCount);
                }

                $payload = [
                    'rule_label' => $ruleLabel,
                    'click_count' => $clickCount,
                    'window_seconds' => $windowSeconds,
                    'target_user_id' => $targetUserId,
                    'is_enabled' => $isEnabled,
                    'updated_at' => now(),
                ];

                if ($ruleId > 0) {
                    DB::table('login_click_bypass_rules')->where('id', $ruleId)->update($payload);
                    continue;
                }

                $payload['created_at'] = now();
                $payload['created_by_user_id'] = $request->user()?->id;
                DB::table('login_click_bypass_rules')->insert($payload);
            }
        });
    }

    private function farmLocations()
    {
        return Farm::query()
            ->select(['id', 'farm_name', 'owner_user_id', 'location', 'sitio', 'latitude', 'longitude', 'barangay', 'municipality', 'province'])
            ->with(['owner:id,full_name,username', 'premisesZone'])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('farm_name')
            ->get();
    }

    /**
     * @param mixed $decodedGeometry
     * @return array<int, array{shape_type:string, geometry:array<string, mixed>}>
     */
    private function parseGeofenceZonesFromDecoded(mixed $decodedGeometry, string $fallbackShapeType): array
    {
        if (!is_array($decodedGeometry)) {
            return [];
        }

        $zones = [];

        if (isset($decodedGeometry['zones']) && is_array($decodedGeometry['zones'])) {
            foreach ($decodedGeometry['zones'] as $zoneEntry) {
                if (!is_array($zoneEntry)) {
                    continue;
                }

                $zoneGeometry = $zoneEntry['geometry'] ?? $zoneEntry;
                if (!is_array($zoneGeometry)) {
                    continue;
                }

                $zoneShape = strtoupper(trim((string) ($zoneEntry['shape_type'] ?? '')));
                if ($zoneShape === '') {
                    $zoneShape = Geofence::inferShapeType($zoneGeometry) ?? '';
                }

                if ($zoneShape === '') {
                    continue;
                }

                $zones[] = [
                    'shape_type' => $zoneShape,
                    'geometry' => $zoneGeometry,
                ];
            }

            return $zones;
        }

        $singleShape = Geofence::inferShapeType($decodedGeometry)
            ?? strtoupper(trim($fallbackShapeType));

        if ($singleShape === '') {
            return [];
        }

        return [[
            'shape_type' => $singleShape,
            'geometry' => $decodedGeometry,
        ]];
    }
}
