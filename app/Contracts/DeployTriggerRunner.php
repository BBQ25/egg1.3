<?php

namespace App\Contracts;

interface DeployTriggerRunner
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, queued_at?: string|null, trigger_file?: string, error?: string}
     */
    public function queue(string $triggerFile, array $payload): array;
}
