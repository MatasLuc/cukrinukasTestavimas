<?php
session_start();
require_once __DIR__ . '/lib/stripe/init.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/order_functions.php';

// Pagalbinė funkcija env
if (!function_exists('requireEnv')) {
    function requireEnv($key) {
        $envPath = __DIR__ . '/.env';
        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                list($name, $value) = explode('=', $line, 2);
                if (trim($name) === $key) return trim($value);
            }
        }
        return getenv($key);
    }
}

$stripeSecret = requireEnv('STRIPE_SECRET_KEY');
\Stripe\Stripe::setApiKey($stripeSecret);

$sessionId = $_GET['session_id'] ?? null;

if (!$sessionId) {
    header('Location: /orders.php');
    exit;
}

try {
    $session = \Stripe\Checkout\Session::retrieve($sessionId);

    if ($session->payment_status === 'paid') {
        $orderId = (int)$session->metadata->order_id;
        $pdo = getPdo();

        // UŽBAIGIAME UŽSAKYMĄ
        if (completeOrder($pdo, $orderId, 'Stripe')) {
            $_SESSION['flash_success'] = 'Apmokėjimas sėkmingas!';
            header('Location: /order_success.php');
            exit;
        }
    }
} catch (Exception $e) {
    // Log error
}

header('Location: /orders.php');
exit;
?>
