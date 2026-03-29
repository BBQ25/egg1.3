<?php

namespace App\Support;

use App\Contracts\DeployTriggerRunner;

class FileDeployTriggerRunner implements DeployTriggerRunner
{
    public function queue(string $triggerFile, array $payload): array
    {
        $directory = dirname($triggerFile);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return [
                'ok' => false,
                'error' => 'Unable to create the deploy trigger directory.',
                'trigger_file' => $triggerFile,
            ];
        }

        $encodedPayload = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encodedPayload)) {
            return [
                'ok' => false,
                'error' => 'Unable to encode the deploy trigger payload.',
            ];
        }

        if (@file_put_contents($triggerFile, $encodedPayload . PHP_EOL, LOCK_EX) === false) {
            return [
                'ok' => false,
                'error' => 'Unable to write the deploy trigger file.',
                'trigger_file' => $triggerFile,
            ];
        }

        return [
            'ok' => true,
            'queued_at' => $payload['queued_at'] ?? null,
            'trigger_file' => $triggerFile,
        ];
    }
}
