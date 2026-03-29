<?php

namespace App\Services;

use App\Support\JsonCommandResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class HrmisEasyLoginPlaywrightService
{
    /**
     * @param array{
     *   base_url:string,
     *   dtr_url?:string,
     *   email?:string,
     *   username?:string,
     *   password?:string,
     *   timeout_seconds?:int,
     *   headless?:bool,
     *   slow_mo_ms?:int
     * } $payload
     * @return array{
     *   ok:bool,
     *   message:string,
     *   meta:array<string,mixed>
     * }
     */
    public function timeIn(array $payload): array
    {
        $workDir = storage_path('app/hrmis-playwright');
        $sessionDir = $workDir . DIRECTORY_SEPARATOR . 'sessions';
        File::ensureDirectoryExists($workDir);
        File::ensureDirectoryExists($sessionDir);

        $payloadPath = $workDir . DIRECTORY_SEPARATOR . 'payload-' . Str::uuid() . '.json';
        $identity = trim((string) ($payload['email'] ?? $payload['username'] ?? ''));
        $sessionStatePath = $sessionDir . DIRECTORY_SEPARATOR . hash(
            'sha256',
            strtolower(trim((string) ($payload['base_url'] ?? ''))) . '|' . strtolower($identity)
        ) . '.json';

        $baseUrl = rtrim((string) ($payload['base_url'] ?? ''), '/');
        $dtrUrl = trim((string) ($payload['dtr_url'] ?? ''));
        if ($dtrUrl === '') {
            $dtrPath = '/' . ltrim((string) config('services.hrmis.my_dtr_path', '/my-dtr'), '/');
            $dtrUrl = $baseUrl . $dtrPath;
        }

        $scriptPayload = [
            'baseUrl' => $baseUrl,
            'dtrUrl' => $dtrUrl,
            'signinPath' => '/' . ltrim((string) config('services.hrmis.signin_path', '/signin'), '/'),
            'googleDtrAuthPath' => '/' . ltrim((string) config('services.hrmis.google_dtr_auth_path', '/auth/googledtr'), '/'),
            'email' => (string) ($payload['email'] ?? config('services.hrmis.email')),
            'username' => (string) ($payload['username'] ?? ''),
            'password' => (string) ($payload['password'] ?? ''),
            'sessionStatePath' => $sessionStatePath,
            'headless' => (bool) ($payload['headless'] ?? true),
            'slowMoMs' => max(0, (int) ($payload['slow_mo_ms'] ?? config('services.hrmis.playwright_slow_mo_ms', 0))),
        ];

        file_put_contents($payloadPath, json_encode($scriptPayload, JSON_THROW_ON_ERROR));

        $scriptPath = base_path('scripts/hrmis_easy_login.mjs');
        if (!is_file($scriptPath)) {
            throw new RuntimeException('HRMIS Playwright script is missing: ' . $scriptPath);
        }

        $nodeBinary = (string) config('services.hrmis.node_binary', 'node');
        $timeoutSeconds = max(60, (int) ($payload['timeout_seconds'] ?? config('services.hrmis.playwright_timeout', 240)));

        $process = new Process([$nodeBinary, $scriptPath, $payloadPath], base_path(), null, null, $timeoutSeconds);

        try {
            $process->run();

            $stdout = trim($process->getOutput());
            $stderr = trim($process->getErrorOutput());

            if (!$process->isSuccessful()) {
                $message = 'HRMIS Playwright automation failed.';
                if ($stderr !== '') {
                    $message .= ' ' . $stderr;
                }

                throw new RuntimeException($message);
            }

            $result = JsonCommandResult::decode($stdout);

            return [
                'ok' => (bool) ($result['ok'] ?? false),
                'message' => trim((string) ($result['message'] ?? '')),
                'meta' => is_array($result) ? $result : [],
            ];
        } finally {
            if (is_file($payloadPath)) {
                @unlink($payloadPath);
            }
        }
    }

}
