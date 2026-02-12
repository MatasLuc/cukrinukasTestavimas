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
    
    if (file_exists(__DIR__ . '/order_functions.php')) {
        require_once __DIR__ . '/order_functions.php';
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
// LOGIKA: Bandome gauti Order ID priklausomai nuo įvykio
// ---------------------------------------------------

$orderId = null;

if ($event->type == 'checkout.session.completed') {
    $session = $event->data->object;
    // Pirmumas: client_reference_id
    if (!empty($session->client_reference_id)) {
        $orderId = $session->client_reference_id;
    } 
    // Atsarginis variantas: metadata
    elseif (!empty($session->metadata->order_id)) {
        $orderId = $session->metadata->order_id;
    }
    webhook_log("Checkout Session Completed. Order ID: " . ($orderId ?? 'NERASTAS'));
} 
elseif ($event->type == 'payment_intent.succeeded') {
    $intent = $event->data->object;
    // Payment intent ID slepiasi metadata lauke (kurį įdėjome stripe_checkout.php)
    if (!empty($intent->metadata->order_id)) {
        $orderId = $intent->metadata->order_id;
    }
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
    
    // Tikriname esamą būseną
    $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $orderStatus = $stmt->fetchColumn();

    if ($orderStatus && $orderStatus !== 'Apmokėta') {
        try {
            $pdo->beginTransaction();

            // 1. Atnaujiname statusą
            $stmtUpdate = $pdo->prepare("UPDATE orders SET status = 'Apmokėta', updated_at = NOW() WHERE id = ?");
            $stmtUpdate->execute([$orderId]);
            webhook_log("Užsakymas #$orderId pažymėtas kaip Apmokėta.");

            // 2. Sumažiname likučius
            $stmtItems = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
            $stmtItems->execute([$orderId]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            if ($items) {
                $stmtStock = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
                foreach ($items as $item) {
                    $stmtStock->execute([$item['quantity'], $item['product_id']]);
                }
                webhook_log("Likučiai sumažinti.");
            }

            $pdo->commit();

            // 3. Siunčiame laiškus
            if (function_exists('sendOrderConfirmationEmail')) {
                sendOrderConfirmationEmail($orderId, $pdo);
                webhook_log("Laiškai išsiųsti.");
            } else {
                webhook_log("DĖMESIO: Funkcija sendOrderConfirmationEmail nerasta.");
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            webhook_log("DB KLAIDA: " . $e->getMessage());
            http_response_code(500);
            exit();
        }
    } else {
        webhook_log("Užsakymas #$orderId nerastas arba jau apmokėtas (Statusas: $orderStatus).");
    }
} else {
    webhook_log("Klaida: Iš Stripe duomenų nepavyko nustatyti Order ID.");
}

http_response_code(200);
