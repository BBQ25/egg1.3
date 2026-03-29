<?php

namespace App\Http\Controllers\Ops;

use App\Contracts\DeployTriggerRunner;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GithubDeployWebhookController extends Controller
{
    public function __construct(
        private readonly DeployTriggerRunner $deployTriggerRunner
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (!config('deploy.github.enabled')) {
            abort(404);
        }

        $secret = trim((string) config('deploy.github.secret'));
        if ($secret === '') {
            Log::warning('GitHub deploy webhook secret is not configured.');

            return response()->json([
                'message' => 'Webhook secret not configured.',
            ], 500);
        }

        $event = trim((string) $request->header('X-GitHub-Event', ''));
        $signature = trim((string) $request->header('X-Hub-Signature-256', ''));
        $rawPayload = $request->getContent();

        if ($signature === '' || !$this->hasValidSignature($rawPayload, $signature, $secret)) {
            Log::warning('Rejected GitHub deploy webhook due to invalid signature.', [
                'event' => $event,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Invalid signature.',
            ], 401);
        }

        if ($event === 'ping') {
            return response()->json([
                'message' => 'pong',
            ]);
        }

        if ($event !== 'push') {
            return response()->json([
                'message' => 'ignored',
            ], 202);
        }

        $payload = $this->decodePayload($request, $rawPayload);
        if (!is_array($payload)) {
            return response()->json([
                'message' => 'Invalid JSON payload.',
            ], 400);
        }

        $expectedRepository = trim((string) config('deploy.github.repository'));
        $receivedRepository = trim((string) data_get($payload, 'repository.full_name', ''));
        if ($expectedRepository !== '' && $receivedRepository !== $expectedRepository) {
            Log::info('Ignored GitHub deploy webhook for different repository.', [
                'received_repository' => $receivedRepository,
            ]);

            return response()->json([
                'message' => 'ignored repository',
            ], 202);
        }

        $expectedRef = 'refs/heads/' . trim((string) config('deploy.github.branch', 'main'));
        $receivedRef = trim((string) ($payload['ref'] ?? ''));
        if ($receivedRef !== $expectedRef) {
            return response()->json([
                'message' => 'ignored branch',
            ], 202);
        }

        $scriptPath = trim((string) config('deploy.github.script', base_path('scripts/eggs-auto-sync.sh')));
        if ($scriptPath === '' || !is_file($scriptPath)) {
            Log::error('GitHub deploy webhook script is missing.', [
                'script' => $scriptPath,
            ]);

            return response()->json([
                'message' => 'Deploy script not found.',
            ], 500);
        }

        $logFile = trim((string) config('deploy.github.log_file', '/www/wwwlogs/eggs-auto-sync.log'));
        $result = $this->deployTriggerRunner->trigger($scriptPath, $logFile);
        if (!($result['ok'] ?? false)) {
            Log::error('GitHub deploy webhook failed to start deploy script.', $result + [
                'script' => $scriptPath,
            ]);

            return response()->json([
                'message' => 'Failed to start deploy.',
            ], 500);
        }

        Log::info('Accepted GitHub deploy webhook.', [
            'repository' => $receivedRepository,
            'ref' => $receivedRef,
            'after' => (string) ($payload['after'] ?? ''),
            'pid' => $result['pid'] ?? null,
        ]);

        return response()->json([
            'message' => 'deploy accepted',
            'pid' => $result['pid'] ?? null,
        ], 202);
    }

    private function hasValidSignature(string $payload, string $signature, string $secret): bool
    {
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    private function decodePayload(Request $request, string $rawPayload): ?array
    {
        $contentType = strtolower(trim((string) $request->header('Content-Type', '')));
        if (str_starts_with($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($rawPayload, $formPayload);
            $jsonPayload = $formPayload['payload'] ?? null;

            return is_string($jsonPayload) ? json_decode($jsonPayload, true) : null;
        }

        return json_decode($rawPayload, true);
    }
}
