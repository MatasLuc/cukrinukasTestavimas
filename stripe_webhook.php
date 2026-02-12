<?php
// stripe_webhook.php
// Čia nėra session_start(), nes tai automatinė užklausa iš Stripe

require_once __DIR__ . '/lib/stripe/init.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/order_functions.php';

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
$endpoint_secret = requireEnv('STRIPE_WEBHOOK_SECRET');

\Stripe\Stripe::setApiKey($stripeSecret);

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$event = null;

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload, $sig_header, $endpoint_secret
    );
} catch(\UnexpectedValueException $e) {
    http_response_code(400); 
    exit();
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400); 
    exit();
}

// Tikriname įvykį
if ($event->type == 'checkout.session.completed') {
    $session = $event->data->object;
    
    $orderId = (int)$session->metadata->order_id;
    
    if ($orderId) {
        $pdo = getPdo();
        // Užbaigiame užsakymą (completeOrder funkcija turi apsaugą nuo dubliavimo)
        completeOrder($pdo, $orderId, 'Stripe (Webhook)');
    }
}

http_response_code(200);
?>
