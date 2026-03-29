<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Services\DashboardContextService;
use App\Services\MonitoringNotificationsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationsController extends Controller
{
    public function __construct(
        private readonly DashboardContextService $contextService,
        private readonly MonitoringNotificationsService $notificationsService
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user && !$user->isCustomer(), 403);

        try {
            $context = $this->contextService->resolve($request, $user);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        $severity = $this->resolveSeverity($request);
        $payload = $this->notificationsService->build($context, $severity);

        return view('monitoring.notifications.index', [
            'notificationPayload' => $payload,
            'notificationContext' => $context,
            'selectedRange' => $context['range'],
            'selectedSeverity' => $severity,
            'rangeOptions' => [
                DashboardContextService::RANGE_1D => '1D',
                DashboardContextService::RANGE_1W => '1W',
                DashboardContextService::RANGE_1M => '1M',
            ],
            'severityOptions' => [
                MonitoringNotificationsService::SEVERITY_ALL => 'All Alerts',
                MonitoringNotificationsService::SEVERITY_CRITICAL => 'Critical',
                MonitoringNotificationsService::SEVERITY_WARN => 'Warning',
                MonitoringNotificationsService::SEVERITY_INFO => 'Info',
            ],
        ]);
    }

    private function resolveSeverity(Request $request): string
    {
        $severity = strtolower(trim((string) $request->query('severity', MonitoringNotificationsService::SEVERITY_ALL)));

        if (!in_array($severity, MonitoringNotificationsService::allowedSeverities(), true)) {
            return MonitoringNotificationsService::SEVERITY_ALL;
        }

        return $severity;
    }
}
