<?php

namespace App\Contracts;

interface DeployTriggerRunner
{
    /**
     * @return array{ok: bool, pid?: int|null, exit_code?: int, command?: string, error?: string}
     */
    public function trigger(string $scriptPath, string $logFile): array;
}
