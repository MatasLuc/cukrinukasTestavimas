<?php

function ensureLogDestination(): string {
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir . '/app.log';
}

function logError(string $message, ?Throwable $exception = null): void {
    $logFile = ensureLogDestination();
    $context = '[' . date('c') . '] ' . $message;
    if ($exception) {
        $context .= ' | ' . $exception->getMessage();
    }
    $context .= PHP_EOL;
    error_log($context, 3, $logFile);
}

function friendlyErrorMessage(): string {
    return 'Įvyko nenumatyta klaida. Bandykite dar kartą vėliau.';
}
