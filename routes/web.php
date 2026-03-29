<?php

use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\DeviceManagementController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\FarmMapController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Api\DeviceIngestController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LoginBypassController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GuideCenterController;
use App\Http\Controllers\DocEase\AuthController as DocEaseAuthController;
use App\Http\Controllers\GeofenceController;
use App\Http\Controllers\MachineBlueprintController;
use App\Http\Controllers\Monitoring\BatchMonitoringController;
use App\Http\Controllers\Monitoring\EggRecordExplorerController;
use App\Http\Controllers\Monitoring\NotificationsController;
use App\Http\Controllers\Monitoring\ProductionReportController;
use App\Http\Controllers\Monitoring\ValidationAccuracyController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\PriceMonitoringController;
use App\Http\Controllers\Owner\FarmController as OwnerFarmController;
use App\Http\Controllers\DocEase\Academic\AssignmentController as DocEaseAcademicAssignmentController;
use App\Http\Controllers\DocEase\Academic\SubjectController as DocEaseAcademicSubjectController;
use App\Http\Controllers\DocEase\DashboardController as DocEaseDashboardController;
use App\Http\Controllers\Legacy\DocEaseController;
use App\Http\Controllers\SneatPageController;
use Illuminate\Support\Facades\Route;

$appBasePath = trim((string) config('app.base_path', ''), '/');

$registerRoutes = static function (string $prefix = '', bool $withNames = false): void {
    $prefix = trim($prefix, '/');

    $uri = static function (string $path) use ($prefix): string {
        if ($prefix === '') {
            return $path;
        }

        if ($path === '/') {
            return '/' . $prefix;
        }

        return '/' . $prefix . $path;
    };

    $loginRoute = Route::middleware('guest')->get($uri('/login'), [LoginController::class, 'create']);
    if ($withNames) {
        $loginRoute->name('login');
    }

    $loginSubmitRoute = Route::middleware('guest')->post($uri('/login'), [LoginController::class, 'store']);
    if ($withNames) {
        $loginSubmitRoute->name('login.store');
    }

    $loginBypassRoute = Route::middleware('guest')->post($uri('/login/bypass'), [LoginBypassController::class, 'store']);
    if ($withNames) {
        $loginBypassRoute->name('login.bypass');
    }

    $loginCoverRoute = Route::middleware('guest')->get($uri('/auth/login-cover'), [LoginController::class, 'create']);
    if ($withNames) {
        $loginCoverRoute->name('auth.login-cover');
    }

    $registerRoute = Route::middleware('guest')->get($uri('/register'), [RegisterController::class, 'create']);
    if ($withNames) {
        $registerRoute->name('register');
    }

    $registerStoreRoute = Route::middleware('guest')->post($uri('/register'), [RegisterController::class, 'store']);
    if ($withNames) {
        $registerStoreRoute->name('register.store');
    }

    $geofenceRestrictedRoute = Route::get($uri('/geofence/restricted'), [GeofenceController::class, 'restricted']);
    if ($withNames) {
        $geofenceRestrictedRoute->name('geofence.restricted');
    }

    $homeRoute = Route::middleware(['auth', 'geofence'])->get($uri('/'), [DashboardController::class, 'index']);
    if ($withNames) {
        $homeRoute->name('home');
    }

    $dashboardRoute = Route::middleware(['auth', 'geofence'])->get($uri('/dashboard'), [DashboardController::class, 'index']);
    if ($withNames) {
        $dashboardRoute->name('dashboard');
    }

    $dashboardDataRoute = Route::middleware(['auth', 'geofence'])->get($uri('/dashboard/data'), [DashboardController::class, 'data']);
    if ($withNames) {
        $dashboardDataRoute->name('dashboard.data');
    }

    $guidesRoute = Route::middleware(['auth', 'geofence'])->get($uri('/guides'), [GuideCenterController::class, 'index']);
    if ($withNames) {
        $guidesRoute->name('guides.index');
    }

    $machineBlueprintRoute = Route::middleware(['auth', 'geofence', 'machine-blueprint.access'])->get($uri('/machine-blueprint'), [MachineBlueprintController::class, 'index']);
    if ($withNames) {
        $machineBlueprintRoute->name('machine-blueprint.index');
    }

    $ownerFarmsRoute = Route::middleware(['auth', 'geofence'])->get($uri('/owner/my-farms'), [OwnerFarmController::class, 'index']);
    if ($withNames) {
        $ownerFarmsRoute->name('owner.farms.index');
    }

    $ownerFarmsStoreClaimRoute = Route::middleware(['auth', 'geofence'])->post($uri('/owner/my-farms'), [OwnerFarmController::class, 'storeClaim']);
    if ($withNames) {
        $ownerFarmsStoreClaimRoute->name('owner.farms.store');
    }

    $ownerFarmsUpdateRoute = Route::middleware(['auth', 'geofence'])->put($uri('/owner/my-farms/{farm}'), [OwnerFarmController::class, 'update']);
    if ($withNames) {
        $ownerFarmsUpdateRoute->name('owner.farms.update');
    }

    $ownerFarmsReverseGeocodeRoute = Route::middleware(['auth', 'geofence'])->get($uri('/owner/my-farms/reverse-geocode'), [OwnerFarmController::class, 'reverseGeocode']);
    if ($withNames) {
        $ownerFarmsReverseGeocodeRoute->name('owner.farms.reverse-geocode');
    }

    $inventoryRoute = Route::middleware(['auth', 'geofence'])->get($uri('/inventory'), [InventoryController::class, 'index']);
    if ($withNames) {
        $inventoryRoute->name('inventory.index');
    }

    $inventoryStoreItemRoute = Route::middleware(['auth', 'geofence'])->post($uri('/inventory/items'), [InventoryController::class, 'storeItem']);
    if ($withNames) {
        $inventoryStoreItemRoute->name('inventory.items.store');
    }

    $inventoryUpdateItemRoute = Route::middleware(['auth', 'geofence'])->put($uri('/inventory/items/{item}'), [InventoryController::class, 'updateItem']);
    if ($withNames) {
        $inventoryUpdateItemRoute->name('inventory.items.update');
    }

    $inventoryStoreMovementRoute = Route::middleware(['auth', 'geofence'])->post($uri('/inventory/movements'), [InventoryController::class, 'storeMovement']);
    if ($withNames) {
        $inventoryStoreMovementRoute->name('inventory.movements.store');
    }

    $inventoryPricingUpdateRoute = Route::middleware(['auth', 'geofence'])->post($uri('/inventory/pricing'), [InventoryController::class, 'updatePricing']);
    if ($withNames) {
        $inventoryPricingUpdateRoute->name('inventory.pricing.update');
    }

    $batchMonitoringRoute = Route::middleware(['auth', 'geofence'])->get($uri('/monitoring/batch-monitoring'), [BatchMonitoringController::class, 'index']);
    if ($withNames) {
        $batchMonitoringRoute->name('monitoring.batches.index');
    }

    $batchMonitoringStoreRoute = Route::middleware(['auth', 'geofence'])->post($uri('/monitoring/batch-monitoring'), [BatchMonitoringController::class, 'store']);
    if ($withNames) {
        $batchMonitoringStoreRoute->name('monitoring.batches.store');
    }

    $eggRecordExplorerRoute = Route::middleware(['auth', 'geofence'])->get($uri('/monitoring/egg-records'), [EggRecordExplorerController::class, 'index']);
    if ($withNames) {
        $eggRecordExplorerRoute->name('monitoring.records.index');
    }

    $eggRecordExplorerLiveRoute = Route::middleware(['auth', 'geofence'])->get($uri('/monitoring/egg-records/live'), [EggRecordExplorerController::class, 'live']);
    if ($withNames) {
        $eggRecordExplorerLiveRoute->name('monitoring.records.live');
    }

    $eggRecordExplorerExportRoute = Route::middleware(['auth', 'geofence'])->get($uri('/monitoring/egg-records/export'), [EggRecordExplorerController::class, 'export']);
    if ($withNames) {
        $eggRecordExplorerExportRoute->name('monitoring.records.export');
    }

    $productionReportRoute = Route::middleware(['auth', 'geofence'])->get($uri('/monitoring/production-reports'), [ProductionReportController::class, 'index']);
    if ($withNames) {
        $productionReportRoute->name('monitoring.reports.production.index');
    }

    $productionReportExportRoute = Route::middleware(['auth', 'geofence'])->get($uri('/monitoring/production-reports/export'), [ProductionReportController::class, 'export']);
    if ($withNames) {
        $productionReportExportRoute->name('monitoring.reports.production.export');
    }

    $monitoringNotificationsRoute = Route::middleware(['auth', 'geofence'])->get($uri('/monitoring/notifications'), [NotificationsController::class, 'index']);
    if ($withNames) {
        $monitoringNotificationsRoute->name('monitoring.notifications.index');
    }

    $validationAccuracyRoute = Route::middleware(['auth', 'geofence'])->get($uri('/monitoring/validation'), [ValidationAccuracyController::class, 'index']);
    if ($withNames) {
        $validationAccuracyRoute->name('monitoring.validation.index');
    }

    $validationAccuracyRunStoreRoute = Route::middleware(['auth', 'geofence'])->post($uri('/monitoring/validation/runs'), [ValidationAccuracyController::class, 'storeRun']);
    if ($withNames) {
        $validationAccuracyRunStoreRoute->name('monitoring.validation.runs.store');
    }

    $validationAccuracyMeasurementStoreRoute = Route::middleware(['auth', 'geofence'])->post($uri('/monitoring/validation/runs/{run}/measurements'), [ValidationAccuracyController::class, 'storeMeasurement']);
    if ($withNames) {
        $validationAccuracyMeasurementStoreRoute->name('monitoring.validation.measurements.store');
    }

    $validationAccuracyExportRoute = Route::middleware(['auth', 'geofence'])->get($uri('/monitoring/validation/runs/{run}/export'), [ValidationAccuracyController::class, 'export']);
    if ($withNames) {
        $validationAccuracyExportRoute->name('monitoring.validation.export');
    }

    $batchMonitoringExportRoute = Route::middleware(['auth', 'geofence'])->get($uri('/monitoring/batch-monitoring/export'), [BatchMonitoringController::class, 'exportIndex']);
    if ($withNames) {
        $batchMonitoringExportRoute->name('monitoring.batches.export');
    }

    $batchMonitoringShowRoute = Route::middleware(['auth', 'geofence'])->get($uri('/monitoring/batch-monitoring/{farm}/{device}/{batchCode}'), [BatchMonitoringController::class, 'show'])
        ->where('batchCode', '[^/]+');
    if ($withNames) {
        $batchMonitoringShowRoute->name('monitoring.batches.show');
    }

    $batchMonitoringShowExportRoute = Route::middleware(['auth', 'geofence'])->get($uri('/monitoring/batch-monitoring/{farm}/{device}/{batchCode}/export'), [BatchMonitoringController::class, 'exportShow'])
        ->where('batchCode', '[^/]+');
    if ($withNames) {
        $batchMonitoringShowExportRoute->name('monitoring.batches.show.export');
    }

    $batchMonitoringCloseRoute = Route::middleware(['auth', 'geofence'])->patch($uri('/monitoring/batch-monitoring/{farm}/{device}/{batchCode}/close'), [BatchMonitoringController::class, 'close'])
        ->where('batchCode', '[^/]+');
    if ($withNames) {
        $batchMonitoringCloseRoute->name('monitoring.batches.close');
    }

    $priceMonitoringRoute = Route::middleware(['auth', 'geofence'])->get($uri('/price-monitoring'), [PriceMonitoringController::class, 'index']);
    if ($withNames) {
        $priceMonitoringRoute->name('price-monitoring.index');
    }

    $logoutRoute = Route::middleware(['auth', 'geofence'])->post($uri('/logout'), [LoginController::class, 'destroy']);
    if ($withNames) {
        $logoutRoute->name('logout');
    }

    $adminCreateRoute = Route::middleware(['auth', 'geofence', 'admin'])->get($uri('/admin/users/create'), [UserManagementController::class, 'create']);
    if ($withNames) {
        $adminCreateRoute->name('admin.users.create');
    }

    $adminIndexRoute = Route::middleware(['auth', 'geofence', 'admin'])->get($uri('/admin/users'), [UserManagementController::class, 'index']);
    if ($withNames) {
        $adminIndexRoute->name('admin.users.index');
    }

    $adminStoreRoute = Route::middleware(['auth', 'geofence', 'admin'])->post($uri('/admin/users'), [UserManagementController::class, 'store']);
    if ($withNames) {
        $adminStoreRoute->name('admin.users.store');
    }

    $adminDevicesIndexRoute = Route::middleware(['auth', 'geofence', 'admin'])->get($uri('/admin/devices'), [DeviceManagementController::class, 'index']);
    if ($withNames) {
        $adminDevicesIndexRoute->name('admin.devices.index');
    }

    $adminDevicesOwnerFarmsRoute = Route::middleware(['auth', 'geofence', 'admin'])->get($uri('/admin/devices/owner-farms'), [DeviceManagementController::class, 'ownerFarms']);
    if ($withNames) {
        $adminDevicesOwnerFarmsRoute->name('admin.devices.owner-farms');
    }

    $adminDevicesStoreRoute = Route::middleware(['auth', 'geofence', 'admin'])->post($uri('/admin/devices'), [DeviceManagementController::class, 'store']);
    if ($withNames) {
        $adminDevicesStoreRoute->name('admin.devices.store');
    }

    $adminDevicesUpdateRoute = Route::middleware(['auth', 'geofence', 'admin'])->put($uri('/admin/devices/{device}'), [DeviceManagementController::class, 'update']);
    if ($withNames) {
        $adminDevicesUpdateRoute->name('admin.devices.update');
    }

    $adminDevicesDeactivateRoute = Route::middleware(['auth', 'geofence', 'admin'])->patch($uri('/admin/devices/{device}/deactivate'), [DeviceManagementController::class, 'deactivate']);
    if ($withNames) {
        $adminDevicesDeactivateRoute->name('admin.devices.deactivate');
    }

    $adminDevicesReactivateRoute = Route::middleware(['auth', 'geofence', 'admin'])->patch($uri('/admin/devices/{device}/reactivate'), [DeviceManagementController::class, 'reactivate']);
    if ($withNames) {
        $adminDevicesReactivateRoute->name('admin.devices.reactivate');
    }

    $adminDevicesRotateKeyRoute = Route::middleware(['auth', 'geofence', 'admin'])->post($uri('/admin/devices/{device}/rotate-key'), [DeviceManagementController::class, 'rotateKey']);
    if ($withNames) {
        $adminDevicesRotateKeyRoute->name('admin.devices.rotate-key');
    }

    $adminDevicesShowKeyRoute = Route::middleware(['auth', 'geofence', 'admin'])->post($uri('/admin/devices/{device}/show-key'), [DeviceManagementController::class, 'showKey']);
    if ($withNames) {
        $adminDevicesShowKeyRoute->name('admin.devices.show-key');
    }

    $adminBulkRoute = Route::middleware(['auth', 'geofence', 'admin'])->post($uri('/admin/users/bulk'), [UserManagementController::class, 'bulkUpdate']);
    if ($withNames) {
        $adminBulkRoute->name('admin.users.bulk');
    }

    $adminEditRoute = Route::middleware(['auth', 'geofence', 'admin'])->get($uri('/admin/users/{user}/edit'), [UserManagementController::class, 'edit']);
    if ($withNames) {
        $adminEditRoute->name('admin.users.edit');
    }

    $adminUpdateRoute = Route::middleware(['auth', 'geofence', 'admin'])->put($uri('/admin/users/{user}'), [UserManagementController::class, 'update']);
    if ($withNames) {
        $adminUpdateRoute->name('admin.users.update');
    }

    $adminDeactivateRoute = Route::middleware(['auth', 'geofence', 'admin'])->patch($uri('/admin/users/{user}/deactivate'), [UserManagementController::class, 'deactivate']);
    if ($withNames) {
        $adminDeactivateRoute->name('admin.users.deactivate');
    }

    $adminReactivateRoute = Route::middleware(['auth', 'geofence', 'admin'])->patch($uri('/admin/users/{user}/reactivate'), [UserManagementController::class, 'reactivate']);
    if ($withNames) {
        $adminReactivateRoute->name('admin.users.reactivate');
    }

    $adminApproveRoute = Route::middleware(['auth', 'geofence', 'admin'])->patch($uri('/admin/users/{user}/approve'), [UserManagementController::class, 'approve']);
    if ($withNames) {
        $adminApproveRoute->name('admin.users.approve');
    }

    $adminDenyRoute = Route::middleware(['auth', 'geofence', 'admin'])->patch($uri('/admin/users/{user}/deny'), [UserManagementController::class, 'deny']);
    if ($withNames) {
        $adminDenyRoute->name('admin.users.deny');
    }

    $adminSettingsEditRoute = Route::middleware(['auth', 'geofence', 'admin'])->get($uri('/admin/settings'), [SettingsController::class, 'edit']);
    if ($withNames) {
        $adminSettingsEditRoute->name('admin.settings.edit');
    }

    $adminSettingsAccessBoundaryRoute = Route::middleware(['auth', 'geofence', 'admin'])->get($uri('/admin/settings/access-boundary'), [SettingsController::class, 'accessBoundary']);
    if ($withNames) {
        $adminSettingsAccessBoundaryRoute->name('admin.settings.access-boundary');
    }

    $adminSettingsAccessBoundaryUpdateRoute = Route::middleware(['auth', 'geofence', 'admin'])->put($uri('/admin/settings/access-boundary'), [SettingsController::class, 'updateAccessBoundary']);
    if ($withNames) {
        $adminSettingsAccessBoundaryUpdateRoute->name('admin.settings.access-boundary.update');
    }

    $adminSettingsLocationOverviewRoute = Route::middleware(['auth', 'geofence', 'admin'])->get($uri('/admin/settings/location-overview'), [SettingsController::class, 'locationOverview']);
    if ($withNames) {
        $adminSettingsLocationOverviewRoute->name('admin.settings.location-overview');
    }

    $adminSettingsUpdateRoute = Route::middleware(['auth', 'geofence', 'admin'])->put($uri('/admin/settings'), [SettingsController::class, 'update']);
    if ($withNames) {
        $adminSettingsUpdateRoute->name('admin.settings.update');
    }

    $adminFarmMapRoute = Route::middleware(['auth', 'geofence', 'admin'])->get($uri('/admin/maps/farms'), [FarmMapController::class, 'index']);
    if ($withNames) {
        $adminFarmMapRoute->name('admin.maps.farms');
    }

    $adminFarmMapStoreRoute = Route::middleware(['auth', 'geofence', 'admin'])->post($uri('/admin/maps/farms'), [FarmMapController::class, 'store']);
    if ($withNames) {
        $adminFarmMapStoreRoute->name('admin.maps.farms.store');
    }

    $adminFarmMapReverseGeocodeRoute = Route::middleware(['auth', 'geofence', 'admin'])->get($uri('/admin/maps/farms/reverse-geocode'), [FarmMapController::class, 'reverseGeocode']);
    if ($withNames) {
        $adminFarmMapReverseGeocodeRoute->name('admin.maps.farms.reverse-geocode');
    }

    $adminFarmMapUpdateRoute = Route::middleware(['auth', 'geofence', 'admin'])->put($uri('/admin/maps/farms/{farm}'), [FarmMapController::class, 'update']);
    if ($withNames) {
        $adminFarmMapUpdateRoute->name('admin.maps.farms.update');
    }

    $adminFarmMapDestroyRoute = Route::middleware(['auth', 'geofence', 'admin'])->delete($uri('/admin/maps/farms/{farm}'), [FarmMapController::class, 'destroy']);
    if ($withNames) {
        $adminFarmMapDestroyRoute->name('admin.maps.farms.destroy');
    }

    $adminFarmMapApproveRequestRoute = Route::middleware(['auth', 'geofence', 'admin'])->post($uri('/admin/maps/farm-requests/{farmChangeRequest}/approve'), [FarmMapController::class, 'approveChangeRequest']);
    if ($withNames) {
        $adminFarmMapApproveRequestRoute->name('admin.maps.farm-requests.approve');
    }

    $adminFarmMapRejectRequestRoute = Route::middleware(['auth', 'geofence', 'admin'])->post($uri('/admin/maps/farm-requests/{farmChangeRequest}/reject'), [FarmMapController::class, 'rejectChangeRequest']);
    if ($withNames) {
        $adminFarmMapRejectRequestRoute->name('admin.maps.farm-requests.reject');
    }

    $adminFormsGradeSheetRoute = Route::middleware(['auth', 'geofence', 'admin'])->get($uri('/admin/forms/gradesheet'), [ReportController::class, 'gradeSheet']);
    if ($withNames) {
        $adminFormsGradeSheetRoute->name('admin.forms.gradesheet');
    }

    $formsGradeSheetDownloadRoute = Route::middleware(['auth', 'geofence', 'admin'])->post($uri('/forms/gradesheet'), [ReportController::class, 'downloadGradeSheet']);
    if ($withNames) {
        $formsGradeSheetDownloadRoute->name('forms.gradesheet.download');
    }

    $adminFormsEasyLoginRoute = Route::middleware(['auth', 'geofence', 'admin'])->get($uri('/admin/forms/easy-login'), [ReportController::class, 'easyLogin']);
    if ($withNames) {
        $adminFormsEasyLoginRoute->name('admin.forms.easy-login');
    }

    $formsEasyLoginHrmisTimeInRoute = Route::middleware(['auth', 'geofence', 'admin'])->post($uri('/forms/easy-login/hrmis/time-in'), [ReportController::class, 'runHrmisEasyLoginTimeIn']);
    if ($withNames) {
        $formsEasyLoginHrmisTimeInRoute->name('forms.easy-login.hrmis.time-in');
    }

    $formsCesGradeSheetDownloadRoute = Route::middleware(['auth', 'geofence', 'admin'])->post($uri('/forms/gradesheet/ces'), [ReportController::class, 'downloadCesGradeSheet']);
    if ($withNames) {
        $formsCesGradeSheetDownloadRoute->name('forms.gradesheet.ces.download');
    }

    $formsCesConnectionTestRoute = Route::middleware(['auth', 'geofence', 'admin'])->post($uri('/forms/gradesheet/ces/test-connection'), [ReportController::class, 'testCesConnection']);
    if ($withNames) {
        $formsCesConnectionTestRoute->name('forms.gradesheet.ces.test');
    }

    $deviceIngestRoute = Route::middleware(['throttle:device-ingest'])->post($uri('/api/devices/ingest'), [DeviceIngestController::class, 'store']);
    if ($withNames) {
        $deviceIngestRoute->name('api.devices.ingest');
    }

    $legacyDocEaseIndexRoute = Route::middleware(['auth', 'geofence', 'doc-ease.enabled', 'doc-ease.access'])->get($uri('/legacy/doc-ease'), [DocEaseController::class, 'index']);
    if ($withNames) {
        $legacyDocEaseIndexRoute->name('legacy.doc-ease.index');
    }

    $docEaseLoginRoute = Route::middleware(['doc-ease.enabled', 'guest:doc_ease'])->get($uri('/doc-ease/login'), [DocEaseAuthController::class, 'create']);
    if ($withNames) {
        $docEaseLoginRoute->name('doc-ease.login');
    }

    $docEaseLoginStoreRoute = Route::middleware(['doc-ease.enabled', 'guest:doc_ease'])->post($uri('/doc-ease/login'), [DocEaseAuthController::class, 'store']);
    if ($withNames) {
        $docEaseLoginStoreRoute->name('doc-ease.login.store');
    }

    $docEasePortalRoute = Route::middleware(['doc-ease.enabled', 'auth:doc_ease'])->get($uri('/doc-ease/portal'), [DocEaseAuthController::class, 'portal']);
    if ($withNames) {
        $docEasePortalRoute->name('doc-ease.portal');
    }

    $docEasePortalLaunchLegacyRoute = Route::middleware(['doc-ease.enabled', 'auth:doc_ease'])->post($uri('/doc-ease/portal/launch-legacy'), [DocEaseAuthController::class, 'launchLegacy']);
    if ($withNames) {
        $docEasePortalLaunchLegacyRoute->name('doc-ease.portal.launch-legacy');
    }

    $docEaseAcademicIndexRoute = Route::middleware(['doc-ease.enabled', 'auth:doc_ease', 'doc-ease.admin'])->get($uri('/doc-ease/academic'), static function () use ($uri) {
        return redirect()->to($uri('/doc-ease/academic/assignments'));
    });
    if ($withNames) {
        $docEaseAcademicIndexRoute->name('doc-ease.academic.index');
    }

    $docEaseAcademicSubjectsIndexRoute = Route::middleware(['doc-ease.enabled', 'auth:doc_ease', 'doc-ease.admin'])->get($uri('/doc-ease/academic/subjects'), [DocEaseAcademicSubjectController::class, 'index']);
    if ($withNames) {
        $docEaseAcademicSubjectsIndexRoute->name('doc-ease.academic.subjects.index');
    }

    $docEaseAcademicSubjectsStoreRoute = Route::middleware(['doc-ease.enabled', 'auth:doc_ease', 'doc-ease.admin'])->post($uri('/doc-ease/academic/subjects'), [DocEaseAcademicSubjectController::class, 'store']);
    if ($withNames) {
        $docEaseAcademicSubjectsStoreRoute->name('doc-ease.academic.subjects.store');
    }

    $docEaseAcademicSubjectsEditRoute = Route::middleware(['doc-ease.enabled', 'auth:doc_ease', 'doc-ease.admin'])->get($uri('/doc-ease/academic/subjects/{subject}/edit'), [DocEaseAcademicSubjectController::class, 'edit']);
    if ($withNames) {
        $docEaseAcademicSubjectsEditRoute->name('doc-ease.academic.subjects.edit');
    }

    $docEaseAcademicSubjectsUpdateRoute = Route::middleware(['doc-ease.enabled', 'auth:doc_ease', 'doc-ease.admin'])->put($uri('/doc-ease/academic/subjects/{subject}'), [DocEaseAcademicSubjectController::class, 'update']);
    if ($withNames) {
        $docEaseAcademicSubjectsUpdateRoute->name('doc-ease.academic.subjects.update');
    }

    $docEaseAcademicSubjectsStatusRoute = Route::middleware(['doc-ease.enabled', 'auth:doc_ease', 'doc-ease.admin'])->patch($uri('/doc-ease/academic/subjects/{subject}/status'), [DocEaseAcademicSubjectController::class, 'toggleStatus']);
    if ($withNames) {
        $docEaseAcademicSubjectsStatusRoute->name('doc-ease.academic.subjects.status');
    }

    $docEaseAcademicSubjectsDestroyRoute = Route::middleware(['doc-ease.enabled', 'auth:doc_ease', 'doc-ease.admin'])->delete($uri('/doc-ease/academic/subjects/{subject}'), [DocEaseAcademicSubjectController::class, 'destroy']);
    if ($withNames) {
        $docEaseAcademicSubjectsDestroyRoute->name('doc-ease.academic.subjects.destroy');
    }

    $docEaseAcademicAssignmentsIndexRoute = Route::middleware(['doc-ease.enabled', 'auth:doc_ease', 'doc-ease.admin'])->get($uri('/doc-ease/academic/assignments'), [DocEaseAcademicAssignmentController::class, 'index']);
    if ($withNames) {
        $docEaseAcademicAssignmentsIndexRoute->name('doc-ease.academic.assignments.index');
    }

    $docEaseAcademicAssignmentsStoreRoute = Route::middleware(['doc-ease.enabled', 'auth:doc_ease', 'doc-ease.admin'])->post($uri('/doc-ease/academic/assignments'), [DocEaseAcademicAssignmentController::class, 'store']);
    if ($withNames) {
        $docEaseAcademicAssignmentsStoreRoute->name('doc-ease.academic.assignments.store');
    }

    $docEaseAcademicAssignmentsRevokeRoute = Route::middleware(['doc-ease.enabled', 'auth:doc_ease', 'doc-ease.admin'])->post($uri('/doc-ease/academic/assignments/{assignmentId}/revoke'), [DocEaseAcademicAssignmentController::class, 'revoke']);
    if ($withNames) {
        $docEaseAcademicAssignmentsRevokeRoute->name('doc-ease.academic.assignments.revoke');
    }

    $docEaseLogoutRoute = Route::middleware(['doc-ease.enabled', 'auth:doc_ease'])->post($uri('/doc-ease/logout'), [DocEaseAuthController::class, 'destroy']);
    if ($withNames) {
        $docEaseLogoutRoute->name('doc-ease.logout');
    }

    $docEaseDashboardRoute = Route::middleware(['auth', 'geofence', 'doc-ease.enabled', 'doc-ease.access'])->get($uri('/doc-ease'), [DocEaseDashboardController::class, 'index']);
    if ($withNames) {
        $docEaseDashboardRoute->name('doc-ease.dashboard');
    }

    $legacyDocEaseLaunchRoute = Route::middleware(['auth', 'geofence', 'doc-ease.enabled', 'doc-ease.access'])->post($uri('/legacy/doc-ease/launch'), [DocEaseController::class, 'launch']);
    if ($withNames) {
        $legacyDocEaseLaunchRoute->name('legacy.doc-ease.launch');
    }

    $legacyAdminReportsGradeSheetRoute = Route::middleware(['auth', 'geofence', 'admin'])->get($uri('/admin/reports/gradesheet'), function () use ($uri) {
        return redirect()->to($uri('/admin/forms/gradesheet'), 301);
    });
    if ($withNames) {
        $legacyAdminReportsGradeSheetRoute->name('legacy.admin.reports.gradesheet');
    }

    $legacyReportGradeSheetDownloadRoute = Route::middleware(['auth', 'geofence', 'admin'])->post($uri('/report/gradesheet'), function () use ($uri) {
        // Preserve POST method + payload when redirecting to the new endpoint.
        return redirect()->to($uri('/forms/gradesheet'), 308);
    });
    if ($withNames) {
        $legacyReportGradeSheetDownloadRoute->name('legacy.report.gradesheet.download');
    }

    $legacyReportCesGradeSheetDownloadRoute = Route::middleware(['auth', 'geofence', 'admin'])->post($uri('/report/gradesheet/ces'), function () use ($uri) {
        // Preserve POST method + payload when redirecting to the new endpoint.
        return redirect()->to($uri('/forms/gradesheet/ces'), 308);
    });
    if ($withNames) {
        $legacyReportCesGradeSheetDownloadRoute->name('legacy.report.gradesheet.ces.download');
    }

    $legacyReportCesConnectionTestRoute = Route::middleware(['auth', 'geofence', 'admin'])->post($uri('/report/gradesheet/ces/test-connection'), function () use ($uri) {
        // Preserve POST method + payload when redirecting to the new endpoint.
        return redirect()->to($uri('/forms/gradesheet/ces/test-connection'), 308);
    });
    if ($withNames) {
        $legacyReportCesConnectionTestRoute->name('legacy.report.gradesheet.ces.test');
    }

    $legacyHtmlRoute = Route::middleware(['auth', 'geofence'])->get($uri('/{page}.html'), [SneatPageController::class, 'legacy'])
        ->where('page', '[A-Za-z0-9\-]+');
    if ($withNames) {
        $legacyHtmlRoute->name('sneat.page.legacy.html');
    }

    $legacyPhpRoute = Route::middleware(['auth', 'geofence'])->get($uri('/{page}.php'), [SneatPageController::class, 'legacy'])
        ->where('page', '[A-Za-z0-9\-]+');
    if ($withNames) {
        $legacyPhpRoute->name('sneat.page.legacy.php');
    }

    $cleanPageRoute = Route::middleware(['auth', 'geofence'])->get($uri('/{page}'), [SneatPageController::class, 'show'])
        ->where('page', '[A-Za-z0-9\-]+');
    if ($withNames) {
        $cleanPageRoute->name('sneat.page');
    }
};

$registerRoutes(withNames: true);

if ($appBasePath !== '') {
    $registerRoutes(prefix: $appBasePath);
}

Route::fallback(static function () {
    abort(404);
});
