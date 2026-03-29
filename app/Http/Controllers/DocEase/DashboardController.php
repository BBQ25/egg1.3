<?php

namespace App\Http\Controllers\DocEase;

use App\Domain\DocEase\DocEaseGateway;
use App\Domain\DocEase\DocEaseReadModel;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DocEaseGateway $docEaseGateway,
        private readonly DocEaseReadModel $docEaseReadModel
    ) {
    }

    public function index(): View
    {
        $snapshot = $this->docEaseReadModel->snapshot();

        return view('doc-ease.dashboard', [
            'snapshot' => $snapshot,
            'bridgeEnabled' => $this->docEaseGateway->bridgeEnabled(),
            'bridgePath' => $this->docEaseGateway->bridgePath(),
            'bridgeExists' => $this->docEaseGateway->bridgeExists(),
            'bridgeSecretConfigured' => $this->docEaseGateway->bridgeSecretConfigured(),
            'entrypoint' => $this->docEaseGateway->entrypoint(),
            'entrypointExists' => $this->docEaseGateway->entrypointExists(),
            'allowedRoles' => $this->docEaseGateway->allowedRoles(),
            'directPathLockEnabled' => (bool) config('doc_ease.direct_lock.enabled', false),
            'directPathGatewayPath' => (string) config('doc_ease.direct_lock.gateway_path', '/legacy/doc-ease'),
        ]);
    }
}

