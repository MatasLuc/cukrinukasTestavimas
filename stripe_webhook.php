<?php
// stripe_webhook.php

// Nutildome klaidas į naršyklę, nes tai background procesas, bet galime loginti į failą
ini_set('display_errors', 0); 
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/order_functions.php'; // Čia turi būti laiškų siuntimo logika

// Įkeliame Stripe
if (file_exists(__DIR__ . '/lib/stripe/init.php')) {
    require_once __DIR__ . '/lib/stripe/init.php';
} else {
    http_response_code(500);
    exit();
}

// Gauname raktus
$stripeSecret = $_ENV['STRIPE_SECRET_KEY'] ?? '';
$endpointSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';

if (!$stripeSecret || !$endpointSecret) {
    error_log("Stripe Webhook Error: Nėra API raktų");
    http_response_code(500);
    exit();
}

\Stripe\Stripe::setApiKey($stripeSecret);

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$event = null;

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload, $sig_header, $endpointSecret
    );
} catch(\UnexpectedValueException $e) {
    http_response_code(400); // Invalid payload
    exit();
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400); // Invalid signature
    exit();
}

// Apdorojame įvykį
if ($event->type == 'checkout.session.completed') {
    $session = $event->data->object;
    
    // Ištraukiame order_id
    $orderId = $session->client_reference_id; 

    if ($orderId) {
        $pdo = getPdo();
        
        // Patikriname dabartinį statusą
        $stmtCheck = $pdo->prepare("SELECT status, customer_email, customer_name, total FROM orders WHERE id = ?");
        $stmtCheck->execute([$orderId]);
        $orderInfo = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        // Jei dar neapmokėta, vykdome atnaujinimą
        if ($orderInfo && $orderInfo['status'] !== 'Apmokėta') {
            try {
                $pdo->beginTransaction();

                // 1. Atnaujiname statusą
                $stmtUpdate = $pdo->prepare("UPDATE orders SET status = 'Apmokėta', updated_at = NOW() WHERE id = ?");
                $stmtUpdate->execute([$orderId]);

                // 2. Sumažiname likučius
                $stmtItems = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
                $stmtItems->execute([$orderId]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                $stmtStock = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
                foreach ($items as $item) {
                    $stmtStock->execute([$item['quantity'], $item['product_id']]);
                }

                $pdo->commit();

                // 3. Išsiunčiame laišką (funkcija turi būti order_functions.php)
                if (function_exists('sendOrderConfirmationEmail')) {
                    sendOrderConfirmationEmail($orderId, $pdo);
                } else {
                    error_log("Funkcija sendOrderConfirmationEmail nerasta order_functions.php faile");
                }

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("DB Error Webhook: " . $e->getMessage());
                http_response_code(500);
                exit();
            }
        }
    }
}

http_response_code(200);
