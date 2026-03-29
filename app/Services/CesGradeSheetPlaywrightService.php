<?php

namespace App\Services;

use App\Support\JsonCommandResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class CesGradeSheetPlaywrightService
{
    /**
     * @param array{
     *   base_url:string,
     *   campus?:string,
     *   username:string,
     *   password:string,
     *   school_year:string,
     *   semester:string,
     *   section_code:string,
     *   timeout_seconds?:int,
     *   headless?:bool,
     *   slow_mo_ms?:int
     * } $payload
     * @return array{
     *   binary:string,
     *   filename:string,
     *   meta:array<string,mixed>
     * }
     */
    public function generate(array $payload): array
    {
        $workDir = storage_path('app/ces-playwright');
        $sessionDir = $workDir . DIRECTORY_SEPARATOR . 'sessions';
        File::ensureDirectoryExists($workDir);
        File::ensureDirectoryExists($sessionDir);

        $payloadPath = $workDir . DIRECTORY_SEPARATOR . 'payload-' . Str::uuid() . '.json';
        $outputPath = $workDir . DIRECTORY_SEPARATOR . 'gradesheet-' . Str::uuid() . '.pdf';
        $sessionStatePath = $sessionDir . DIRECTORY_SEPARATOR . hash(
            'sha256',
            strtolower(trim((string) ($payload['base_url'] ?? ''))) . '|' . trim((string) ($payload['username'] ?? ''))
        ) . '.json';

        $scriptPayload = [
            'mode' => 'generate_pdf',
            'baseUrl' => rtrim((string) ($payload['base_url'] ?? ''), '/'),
            'campus' => (string) ($payload['campus'] ?? config('services.ces.campus', 'Bontoc')),
            'username' => (string) ($payload['username'] ?? ''),
            'password' => (string) ($payload['password'] ?? ''),
            'schoolYear' => (string) ($payload['school_year'] ?? ''),
            'semester' => (string) ($payload['semester'] ?? ''),
            'sectionCode' => (string) ($payload['section_code'] ?? ''),
            'encodePath' => '/teacher/encode-grades',
            'loginPath' => '/auth/login-basic',
            'outputPath' => $outputPath,
            'sessionStatePath' => $sessionStatePath,
            'headless' => (bool) ($payload['headless'] ?? true),
            'slowMoMs' => max(0, (int) ($payload['slow_mo_ms'] ?? config('services.ces.playwright_slow_mo_ms', 0))),
        ];

        file_put_contents($payloadPath, json_encode($scriptPayload, JSON_THROW_ON_ERROR));

        $scriptPath = base_path('scripts/ces_generate_gradesheet.mjs');
        if (!is_file($scriptPath)) {
            throw new RuntimeException('CES Playwright script is missing: ' . $scriptPath);
        }

        $nodeBinary = (string) config('services.ces.node_binary', 'node');
        $timeoutSeconds = max(60, (int) ($payload['timeout_seconds'] ?? config('services.ces.playwright_timeout', 240)));

        $process = new Process([$nodeBinary, $scriptPath, $payloadPath], base_path(), null, null, $timeoutSeconds);

        try {
            $process->run();

            $stdout = trim($process->getOutput());
            $stderr = trim($process->getErrorOutput());

            if (!$process->isSuccessful()) {
                $message = 'CES Playwright automation failed.';
                if ($stderr !== '') {
                    $message .= ' ' . $stderr;
                }

                throw new RuntimeException($message);
            }

            $result = JsonCommandResult::decode($stdout);

            $resultOutputPath = (string) ($result['outputPath'] ?? $outputPath);
            if (!is_file($resultOutputPath)) {
                throw new RuntimeException('CES Playwright did not produce a PDF file.');
            }

            $binary = file_get_contents($resultOutputPath);
            if ($binary === false || $binary === '') {
                throw new RuntimeException('CES Playwright produced an empty PDF file.');
            }

            @unlink($resultOutputPath);

            $filename = $this->safeFilename((string) ($result['filename'] ?? 'ces-grade-sheet.pdf'));

            return [
                'binary' => $binary,
                'filename' => $filename,
                'meta' => is_array($result) ? $result : [],
            ];
        } catch (\Throwable $e) {
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }

            throw $e;
        } finally {
            if (is_file($payloadPath)) {
                @unlink($payloadPath);
            }
        }
    }

    /**
     * @param array{
     *   base_url:string,
     *   campus?:string,
     *   username:string,
     *   password:string,
     *   school_year?:string,
     *   semester?:string,
     *   section_code?:string,
     *   timeout_seconds?:int,
     *   headless?:bool,
     *   slow_mo_ms?:int
     * } $payload
     * @return array{
     *   ok:bool,
     *   meta:array<string,mixed>
     * }
     */
    public function testConnection(array $payload): array
    {
        $workDir = storage_path('app/ces-playwright');
        $sessionDir = $workDir . DIRECTORY_SEPARATOR . 'sessions';
        File::ensureDirectoryExists($workDir);
        File::ensureDirectoryExists($sessionDir);

        $payloadPath = $workDir . DIRECTORY_SEPARATOR . 'payload-' . Str::uuid() . '.json';
        $sessionStatePath = $sessionDir . DIRECTORY_SEPARATOR . hash(
            'sha256',
            strtolower(trim((string) ($payload['base_url'] ?? ''))) . '|' . trim((string) ($payload['username'] ?? ''))
        ) . '.json';

        $scriptPayload = [
            'mode' => 'test_connection',
            'baseUrl' => rtrim((string) ($payload['base_url'] ?? ''), '/'),
            'campus' => (string) ($payload['campus'] ?? config('services.ces.campus', 'Bontoc')),
            'username' => (string) ($payload['username'] ?? ''),
            'password' => (string) ($payload['password'] ?? ''),
            'schoolYear' => (string) ($payload['school_year'] ?? '2025-2026'),
            'semester' => (string) ($payload['semester'] ?? '1st Semester'),
            'sectionCode' => (string) ($payload['section_code'] ?? 'IF-2-A-6'),
            'encodePath' => '/teacher/encode-grades',
            'loginPath' => '/auth/login-basic',
            'sessionStatePath' => $sessionStatePath,
            'headless' => (bool) ($payload['headless'] ?? true),
            'slowMoMs' => max(0, (int) ($payload['slow_mo_ms'] ?? config('services.ces.playwright_slow_mo_ms', 0))),
        ];

        file_put_contents($payloadPath, json_encode($scriptPayload, JSON_THROW_ON_ERROR));

        $scriptPath = base_path('scripts/ces_generate_gradesheet.mjs');
        if (!is_file($scriptPath)) {
            throw new RuntimeException('CES Playwright script is missing: ' . $scriptPath);
        }

        $nodeBinary = (string) config('services.ces.node_binary', 'node');
        $timeoutSeconds = max(60, (int) ($payload['timeout_seconds'] ?? config('services.ces.playwright_timeout', 240)));

        $process = new Process([$nodeBinary, $scriptPath, $payloadPath], base_path(), null, null, $timeoutSeconds);

        try {
            $process->run();

            $stdout = trim($process->getOutput());
            $stderr = trim($process->getErrorOutput());

            if (!$process->isSuccessful()) {
                $message = 'CES Playwright connection test failed.';
                if ($stderr !== '') {
                    $message .= ' ' . $stderr;
                }

                throw new RuntimeException($message);
            }

            $result = JsonCommandResult::decode($stdout);

            return [
                'ok' => (bool) ($result['ok'] ?? true),
                'meta' => is_array($result) ? $result : [],
            ];
        } finally {
            if (is_file($payloadPath)) {
                @unlink($payloadPath);
            }
        }
    }

    private function safeFilename(string $filename): string
    {
        $clean = trim($filename);
        if ($clean === '') {
            return 'ces-grade-sheet.pdf';
        }

        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', $clean) ?? 'ces-grade-sheet.pdf';
        $clean = trim($clean, '.-_');

        if ($clean === '') {
            $clean = 'ces-grade-sheet';
        }

        if (!str_ends_with(strtolower($clean), '.pdf')) {
            $clean .= '.pdf';
        }

        return $clean;
    }
}
