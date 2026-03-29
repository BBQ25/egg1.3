<?php

namespace App\Support;

use App\Contracts\DeployTriggerRunner;

class ShellDeployTriggerRunner implements DeployTriggerRunner
{
    public function trigger(string $scriptPath, string $logFile): array
    {
        if (!function_exists('exec')) {
            return [
                'ok' => false,
                'error' => 'The exec function is unavailable on this host.',
            ];
        }

        $command = sprintf(
            'nohup /bin/bash %s >> %s 2>&1 & echo $!',
            escapeshellarg($scriptPath),
            escapeshellarg($logFile)
        );

        $output = [];
        $exitCode = 0;
        @exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            return [
                'ok' => false,
                'exit_code' => $exitCode,
                'command' => $command,
            ];
        }

        $pid = isset($output[0]) && is_numeric($output[0]) ? (int) $output[0] : null;

        return [
            'ok' => true,
            'pid' => $pid,
            'exit_code' => $exitCode,
        ];
    }
}
