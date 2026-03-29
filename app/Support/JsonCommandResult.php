<?php

namespace App\Support;

use Throwable;

class JsonCommandResult
{
    /**
     * @return array<string, mixed>
     */
    public static function decode(string $stdout): array
    {
        if ($stdout === '') {
            return [];
        }

        try {
            $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            // Fall through to best-effort line parsing.
        }

        $lines = preg_split('/\R/', $stdout) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim((string) $lines[$i]);
            if ($line === '') {
                continue;
            }

            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                return is_array($decoded) ? $decoded : [];
            } catch (Throwable) {
                continue;
            }
        }

        return [];
    }
}
