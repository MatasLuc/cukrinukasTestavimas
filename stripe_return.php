<?php
// stripe_return.php
session_start();
require_once __DIR__ . '/lib/stripe/init.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/order_functions.php'; // Mūsų sukurta funkcija

// Pagalbinė funkcija env kintamiesiems (kad nereiktų dubliuoti kodo)
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
        $success = completeOrder($pdo, $orderId, 'Stripe');

        if ($success) {
            $_SESSION['flash_success'] = 'Apmokėjimas sėkmingas! Užsakymas vykdomas.';
            header('Location: /order_success.php');
            exit;
        }
    }
} catch (Exception $e) {
    // Klaida gaunant sesiją
}

// Jei kažkas nepavyko
$_SESSION['flash_error'] = 'Nepavyko patvirtinti mokėjimo. Jei pinigai nuskaičiuoti, susisiekite su mumis.';
header('Location: /orders.php');
exit;
?>
