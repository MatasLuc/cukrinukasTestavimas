<?php
// env.php
// Lightweight environment loader

if (!function_exists('loadEnvFile')) {
    function loadEnvFile(string $path = __DIR__ . '/.env'): void
    {
        static $loaded = false;
        // Leidžiame krauti iš naujo tik jei reikia, bet paprastai užtenka vieną kartą
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

            // Nuimame kabutes jei jos yra
            $valueLength = strlen($value);
            if ($valueLength >= 2) {
                $firstChar = $value[0];
                $lastChar = $value[$valueLength - 1];
                if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            // Priverstinai nustatome kintamuosius, kad .env failas turėtų pirmenybę
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

if (!function_exists('requireEnv')) {
    function requireEnv(string $name): string
    {
        // Pirmiausia tikriname $_ENV, nes putenv ne visada veikia patikimai kai kuriuose serveriuose
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        
        if ($value === false || $value === '' || $value === null) {
            throw new RuntimeException("Missing required environment variable: {$name}");
        }

        return (string)$value;
    }
}
