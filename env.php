<?php
// Lightweight environment loader to read variables from a local .env file when present.
// This avoids hardcoding secrets in the repository and keeps configuration in the environment.

if (!function_exists('loadEnvFile')) {
    function loadEnvFile(string $path = __DIR__ . '/.env'): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '#') === 0) {
                continue;
            }

            $delimiterPos = strpos($trimmed, '=');
            if ($delimiterPos === false) {
                continue;
            }

            $name = trim(substr($trimmed, 0, $delimiterPos));
            $value = trim(substr($trimmed, $delimiterPos + 1));

            if ($name === '') {
                continue;
            }

            $valueLength = strlen($value);
            if ($valueLength >= 2) {
                $firstChar = $value[0];
                $lastChar = $value[$valueLength - 1];
                if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if (getenv($name) === false) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

if (!function_exists('requireEnv')) {
    function requireEnv(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new RuntimeException("Missing required environment variable: {$name}");
        }

        return $value;
    }
}
