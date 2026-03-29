<?php

namespace App\Http\Controllers\Legacy;

use App\Domain\DocEase\DocEaseGateway;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Illuminate\View\View;

class DocEaseController extends Controller
{
    public function __construct(private readonly DocEaseGateway $docEaseGateway)
    {
    }

    public function index(Request $request): View
    {
        return view('legacy.doc-ease', [
            'entrypoint' => $this->docEaseGateway->entrypoint(),
            'entrypointExists' => $this->docEaseGateway->entrypointExists(),
            'entrypointPublicPath' => $this->docEaseGateway->entrypointPublicPath(),
            'allowedRoles' => $this->docEaseGateway->allowedRoles(),
            'bridgeEnabled' => $this->docEaseGateway->bridgeEnabled(),
            'bridgePath' => $this->docEaseGateway->bridgePath(),
            'bridgeExists' => $this->docEaseGateway->bridgeExists(),
            'bridgePublicPath' => $this->docEaseGateway->bridgePublicPath(),
            'bridgeSecretConfigured' => $this->docEaseGateway->bridgeSecretConfigured(),
            'directPathLockEnabled' => (bool) config('doc_ease.direct_lock.enabled', false),
            'directPathGatewayPath' => (string) config('doc_ease.direct_lock.gateway_path', '/legacy/doc-ease'),
        ]);
    }

    public function launch(Request $request): RedirectResponse
    {
        if (!$this->docEaseGateway->entrypointExists()) {
            abort(404);
        }

        if ($this->docEaseGateway->bridgeEnabled() && !$this->docEaseGateway->bridgeExists()) {
            abort(404);
        }

        if ($this->docEaseGateway->bridgeEnabled() && !$this->docEaseGateway->bridgeSecretConfigured()) {
            abort(503, 'Doc-Ease bridge is enabled but not configured.');
        }

        try {
            $launchUrl = $this->docEaseGateway->launchUrlForUser($request->user());
        } catch (RuntimeException $e) {
            abort(503, $e->getMessage());
        }

        return redirect()->to($launchUrl);
    }
}
