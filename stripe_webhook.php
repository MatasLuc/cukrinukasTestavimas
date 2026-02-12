<?php
// stripe_webhook.php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/order_functions.php'; // Įtraukiame funkcijas

\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);
$endpoint_secret = $_ENV['STRIPE_WEBHOOK_SECRET'];

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$event = null;

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload, $sig_header, $endpoint_secret
    );
} catch(\UnexpectedValueException $e) {
    http_response_code(400); exit();
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400); exit();
}

// Apdorojame sėkmingą apmokėjimą
if ($event->type == 'checkout.session.completed') {
    $session = $event->data->object;
    $orderId = $session->client_reference_id; // Čia gauname ID iš stripe_checkout.php

    if ($orderId) {
        $pdo = getPdo();
        
        // Patikriname, ar užsakymas dar neapdorotas (kad nedubliuotų)
        $stmtStatus = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
        $stmtStatus->execute([$orderId]);
        $currentStatus = $stmtStatus->fetchColumn();

        if ($currentStatus && $currentStatus !== 'Apmokėta') {
            try {
                $pdo->beginTransaction();

                // 1. Atnaujiname statusą
                $stmtUpdate = $pdo->prepare("UPDATE orders SET status = 'Apmokėta', updated_at = NOW() WHERE id = ?");
                $stmtUpdate->execute([$orderId]);

                // 2. Sumažiname prekių likučius
                $stmtItems = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
                $stmtItems->execute([$orderId]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                $stmtStock = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
                foreach ($items as $item) {
                    $stmtStock->execute([$item['quantity'], $item['product_id']]);
                }

                $pdo->commit();

                // 3. Išsiunčiame laiškus (naudojame funkciją iš order_functions.php)
                sendOrderConfirmationEmail($orderId, $pdo);

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Webhook Error: " . $e->getMessage());
                http_response_code(500);
                exit();
            }
        }
    }
}

http_response_code(200);
