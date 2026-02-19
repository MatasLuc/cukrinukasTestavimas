<?php
// stripe_webhook.php

// Logging funkcija
function webhook_log($msg) {
    $logFile = __DIR__ . '/webhook_log.txt';
    $entry = date('Y-m-d H:i:s') . " - " . $msg . "\n";
    file_put_contents($logFile, $entry, FILE_APPEND);
}

webhook_log("--- Webhook gautas ---");

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/webhook_php_errors.log');

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/env.php';
    
    if (file_exists(__DIR__ . '/lib/stripe/init.php')) {
        require_once __DIR__ . '/lib/stripe/init.php';
    } else {
        throw new Exception("Stripe lib nerasta");
    }
    
    // Čia būtinai įtraukiame order_functions su nauja completeOrder funkcija
    if (file_exists(__DIR__ . '/order_functions.php')) {
        require_once __DIR__ . '/order_functions.php';
    } else {
        throw new Exception("order_functions.php nerastas");
    }

} catch (Exception $e) {
    webhook_log("CRITICAL ERROR: " . $e->getMessage());
    http_response_code(500);
    exit();
}

$stripeSecret = $_ENV['STRIPE_SECRET_KEY'] ?? '';
$endpointSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';

\Stripe\Stripe::setApiKey($stripeSecret);

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$event = null;

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload, $sig_header, $endpointSecret
    );
    webhook_log("Parašas patvirtintas. Event type: " . $event->type);
} catch(\UnexpectedValueException $e) {
    webhook_log("Invalid payload");
    http_response_code(400); exit();
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    webhook_log("Invalid signature");
    http_response_code(400); exit();
}

// ---------------------------------------------------
// LOGIKA: Bandome gauti Order ID
// ---------------------------------------------------

$orderId = null;
$paymentIntentId = null;

if ($event->type == 'checkout.session.completed') {
    $session = $event->data->object;
    
    if (!empty($session->client_reference_id)) {
        $orderId = $session->client_reference_id;
    } elseif (!empty($session->metadata->order_id)) {
        $orderId = $session->metadata->order_id;
    }
    
    $paymentIntentId = $session->payment_intent ?? $session->id;
    webhook_log("Checkout Session Completed. Order ID: " . ($orderId ?? 'NERASTAS'));
} 
elseif ($event->type == 'payment_intent.succeeded') {
    $intent = $event->data->object;
    
    if (!empty($intent->metadata->order_id)) {
        $orderId = $intent->metadata->order_id;
    }
    
    $paymentIntentId = $intent->id;
    webhook_log("Payment Intent Succeeded. Order ID iš metadata: " . ($orderId ?? 'NERASTAS'));
} 
else {
    webhook_log("Ignoruojamas eventas: " . $event->type);
    http_response_code(200);
    exit();
}

// ---------------------------------------------------
// UŽSAKYMO TVARKYMAS
// ---------------------------------------------------

if ($orderId) {
    $pdo = getPdo();
    
    // Naudojame centralizuotą funkciją ir paduodame Payment Intent ID, kad pririštų turgelio prekes
    if (function_exists('completeOrder')) {
        $result = completeOrder($pdo, $orderId, true, $paymentIntentId);
        if ($result) {
            webhook_log("Užsakymas #$orderId sėkmingai užbaigtas (Webhooks inicijavo).");
        } else {
            webhook_log("Užsakymas #$orderId jau buvo sutvarkytas arba nerastas.");
        }
    } else {
        webhook_log("CRITICAL: Funkcija completeOrder nerasta.");
    }
} else {
    webhook_log("Klaida: Iš Stripe duomenų nepavyko nustatyti Order ID.");
}

http_response_code(200);
?>
