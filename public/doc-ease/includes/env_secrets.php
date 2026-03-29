<?php

if (!function_exists('doc_ease_env_value')) {
    /**
     * Read secrets from runtime environment first, then from project .env files.
     * This intentionally avoids reading plaintext secret files under webroot.
     */
    function doc_ease_env_value($name) {
        $name = trim((string) $name);
        if ($name === '') return '';

        $runtime = getenv($name);
        if (is_string($runtime)) {
            $runtime = trim($runtime);
            if ($runtime !== '') return $runtime;
        }

        if (isset($_ENV[$name])) {
            $envArrayValue = trim((string) $_ENV[$name]);
            if ($envArrayValue !== '') return $envArrayValue;
        }

        static $loaded = false;
        static $cache = [];

        if (!$loaded) {
            $loaded = true;
            $paths = [
                __DIR__ . '/../.env',
                dirname(__DIR__, 3) . '/.env',
            ];

            foreach ($paths as $path) {
                if (!is_file($path)) continue;

                $raw = (string) @file_get_contents($path);
                if ($raw === '') continue;

                $lines = preg_split('/\r\n|\n|\r/', $raw);
                if (!is_array($lines)) continue;

                foreach ($lines as $line) {
                    $line = trim((string) $line);
                    if ($line === '' || $line[0] === '#') continue;

                    $eqPos = strpos($line, '=');
                    if ($eqPos === false) continue;

                    $key = trim((string) substr($line, 0, $eqPos));
                    if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) continue;

                    $value = trim((string) substr($line, $eqPos + 1));
                    if ($value === '') {
                        if (!array_key_exists($key, $cache)) $cache[$key] = '';
                        continue;
                    }

                    $first = $value[0];
                    $last = $value[strlen($value) - 1];
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $value = substr($value, 1, -1);
                    } else {
                        $commentPos = strpos($value, ' #');
                        if ($commentPos !== false) {
                            $value = rtrim((string) substr($value, 0, $commentPos));
                        }
                    }

                    $value = str_replace(['\n', '\r', '\t'], ["\n", "\r", "\t"], $value);

                    if (!array_key_exists($key, $cache)) {
                        $cache[$key] = $value;
                    }
                }

                // Use the first readable .env file as the source of truth.
                break;
            }
        }

        $fromCache = $cache[$name] ?? '';
        return trim((string) $fromCache);
    }
}
