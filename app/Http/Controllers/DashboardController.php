<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DashboardContextService;
use App\Services\DashboardMetricsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardContextService $contextService,
        private readonly DashboardMetricsService $metricsService
    ) {
    }

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $context = $this->contextService->resolve($request, $user);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        $payload = $this->metricsService->build($user, $context);

        return view('dashboard', [
            'dashboardPayload' => $payload,
            'dashboardRangeOptions' => [
                DashboardContextService::RANGE_1D => '1D',
                DashboardContextService::RANGE_1W => '1W',
                DashboardContextService::RANGE_1M => '1M',
            ],
            'dashboardShellNav' => $this->dashboardShellNav($user),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $context = $this->contextService->resolve($request, $user);
        } catch (AuthorizationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 403);
        }

        $payload = $this->metricsService->build($user, $context);

        return response()->json($payload);
    }

    /**
     * @return array<int, array{key:string,icon_path:string,label:string,url:string|null,disabled:bool}>
     */
    private function dashboardShellNav(User $user): array
    {
        return [
            [
                'key' => 'dashboard',
                'icon_path' => 'assets/icons/curated/illustration-3d/dashboard.png',
                'label' => 'Dashboard',
                'url' => route('dashboard'),
                'disabled' => false,
                'active' => true,
            ],
            [
                'key' => 'my-farms',
                'icon_path' => 'assets/icons/curated/ui-flat/location.png',
                'label' => 'My Farms',
                'url' => $user->isOwner() ? route('owner.farms.index') : null,
                'disabled' => !$user->isOwner(),
                'active' => false,
            ],
            [
                'key' => 'inventory',
                'icon_path' => 'assets/icons/curated/illustration-3d/statistics-report.png',
                'label' => 'Inventory',
                'url' => ($user->isOwner() || $user->isStaff()) ? route('inventory.index') : null,
                'disabled' => !($user->isOwner() || $user->isStaff()),
                'active' => false,
            ],
            [
                'key' => 'batch-monitoring',
                'icon_path' => 'assets/icons/curated/ui-flat/signal.png',
                'label' => 'Batch Monitoring',
                'url' => ($user->isAdmin() || $user->isOwner() || $user->isStaff()) ? route('monitoring.batches.index') : null,
                'disabled' => !($user->isAdmin() || $user->isOwner() || $user->isStaff()),
                'active' => false,
            ],
            [
                'key' => 'egg-records',
                'icon_path' => 'assets/icons/curated/illustration-3d/statistics-report.png',
                'label' => 'Egg Records',
                'url' => ($user->isAdmin() || $user->isOwner() || $user->isStaff()) ? route('monitoring.records.index') : null,
                'disabled' => !($user->isAdmin() || $user->isOwner() || $user->isStaff()),
                'active' => false,
            ],
            [
                'key' => 'production-reports',
                'icon_path' => 'assets/icons/curated/illustration-3d/statistics-report.png',
                'label' => 'Production Reports',
                'url' => ($user->isAdmin() || $user->isOwner() || $user->isStaff()) ? route('monitoring.reports.production.index') : null,
                'disabled' => !($user->isAdmin() || $user->isOwner() || $user->isStaff()),
                'active' => false,
            ],
            [
                'key' => 'validation',
                'icon_path' => 'assets/icons/curated/ui-flat/signal.png',
                'label' => 'Validation & Accuracy',
                'url' => ($user->isAdmin() || $user->isOwner() || $user->isStaff()) ? route('monitoring.validation.index') : null,
                'disabled' => !($user->isAdmin() || $user->isOwner() || $user->isStaff()),
                'active' => false,
            ],
            [
                'key' => 'price-monitoring',
                'icon_path' => 'assets/icons/curated/illustration-3d/statistics-report.png',
                'label' => 'Price Monitoring',
                'url' => ($user->isAdmin() || $user->isCustomer()) ? route('price-monitoring.index') : null,
                'disabled' => !($user->isAdmin() || $user->isCustomer()),
                'active' => false,
            ],
            [
                'key' => 'machine-blueprint',
                'icon_path' => 'assets/icons/curated/ui-flat/devices.png',
                'label' => 'Machine Blueprint',
                'url' => ($user->isAdmin() || $user->isOwner() || $user->isStaff()) ? route('machine-blueprint.index') : null,
                'disabled' => !($user->isAdmin() || $user->isOwner() || $user->isStaff()),
                'active' => false,
            ],
            [
                'key' => 'topology',
                'icon_path' => 'assets/icons/curated/ui-flat/location.png',
                'label' => 'Topology',
                'url' => $user->isAdmin() ? route('admin.maps.farms') : null,
                'disabled' => !$user->isAdmin(),
                'active' => false,
            ],
            [
                'key' => 'devices',
                'icon_path' => 'assets/icons/curated/ui-flat/devices.png',
                'label' => 'Devices',
                'url' => $user->isAdmin() ? route('admin.devices.index') : null,
                'disabled' => !$user->isAdmin(),
                'active' => false,
            ],
            [
                'key' => 'clients',
                'icon_path' => 'assets/icons/curated/illustration-3d/users.png',
                'label' => 'Clients',
                'url' => $user->isAdmin() ? route('admin.users.index') : null,
                'disabled' => !$user->isAdmin(),
                'active' => false,
            ],
            [
                'key' => 'statistics',
                'icon_path' => 'assets/icons/curated/illustration-3d/statistics-report.png',
                'label' => 'Statistics',
                'url' => $user->isAdmin() ? route('admin.forms.gradesheet') : null,
                'disabled' => !$user->isAdmin(),
                'active' => false,
            ],
            [
                'key' => 'security',
                'icon_path' => 'assets/icons/curated/illustration-3d/lock.png',
                'label' => 'Security',
                'url' => $user->isAdmin() ? route('admin.settings.edit') : null,
                'disabled' => !$user->isAdmin(),
                'active' => false,
            ],
            [
                'key' => 'notifications',
                'icon_path' => 'assets/icons/curated/ui-flat/signal.png',
                'label' => 'Notifications',
                'url' => ($user->isAdmin() || $user->isOwner() || $user->isStaff()) ? route('monitoring.notifications.index') : null,
                'disabled' => !($user->isAdmin() || $user->isOwner() || $user->isStaff()),
                'active' => false,
            ],
            [
                'key' => 'settings',
                'icon_path' => 'assets/icons/curated/ui-flat/settings.png',
                'label' => 'Settings',
                'url' => $user->isAdmin() ? route('admin.settings.edit') : null,
                'disabled' => !$user->isAdmin(),
                'active' => false,
            ],
        ];
    }
}
