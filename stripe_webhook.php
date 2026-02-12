<?php
// stripe_webhook.php

// 1. Logging funkcija klaidų paieškai
function webhook_log($msg) {
    $logFile = __DIR__ . '/webhook_log.txt';
    $entry = date('Y-m-d H:i:s') . " - " . $msg . "\n";
    file_put_contents($logFile, $entry, FILE_APPEND);
}

webhook_log("--- Webhook gautas ---");

// Įjungiame klaidų rodymą (tik logui, ne į outputą)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/webhook_php_errors.log');

// 2. Įkeliame priklausomybes
try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/env.php';
    
    // Įkeliame Stripe
    if (file_exists(__DIR__ . '/lib/stripe/init.php')) {
        require_once __DIR__ . '/lib/stripe/init.php';
    } else {
        throw new Exception("Stripe lib nerasta");
    }
    
    // Įkeliame funkcijas laiškams
    if (file_exists(__DIR__ . '/order_functions.php')) {
        require_once __DIR__ . '/order_functions.php';
    } else {
        webhook_log("ĮSPĖJIMAS: order_functions.php nerastas");
    }

} catch (Exception $e) {
    webhook_log("CRITICAL ERROR kraunant failus: " . $e->getMessage());
    http_response_code(500);
    exit();
}

// 3. Konfigūracija
$stripeSecret = $_ENV['STRIPE_SECRET_KEY'] ?? '';
$endpointSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';

if (empty($stripeSecret) || empty($endpointSecret)) {
    webhook_log("Klaida: Nėra API raktų .env faile");
    http_response_code(500);
    exit();
}

\Stripe\Stripe::setApiKey($stripeSecret);

// 4. Skaitome duomenis
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

// 5. Vykdome logiką
if ($event->type == 'checkout.session.completed') {
    $session = $event->data->object;
    $orderId = $session->client_reference_id; // Čia turi būti mūsų ID

    webhook_log("Mokėjimas pavyko. Order ID: " . $orderId);

    if ($orderId) {
        $pdo = getPdo();
        
        // Patikriname esamą statusą
        $stmt = $pdo->prepare("SELECT status, customer_email FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($order && $order['status'] !== 'Apmokėta') {
            try {
                $pdo->beginTransaction();

                // A. Atnaujiname statusą
                $stmtUpdate = $pdo->prepare("UPDATE orders SET status = 'Apmokėta', updated_at = NOW() WHERE id = ?");
                $stmtUpdate->execute([$orderId]);
                webhook_log("Statusas atnaujintas į 'Apmokėta'");

                // B. Sumažiname likučius
                $stmtItems = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
                $stmtItems->execute([$orderId]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                $stmtStock = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
                foreach ($items as $item) {
                    $stmtStock->execute([$item['quantity'], $item['product_id']]);
                }
                webhook_log("Likučiai sumažinti " . count($items) . " prekėms");

                $pdo->commit();
                webhook_log("DB Transakcija sėkminga");

                // C. Siunčiame laiškus (PO COMMIT, kad jei fail'ins, užsakymas liktų apmokėtas)
                if (function_exists('sendOrderConfirmationEmail')) {
                    webhook_log("Bandome siųsti laišką...");
                    sendOrderConfirmationEmail($orderId, $pdo);
                    webhook_log("Laiško siuntimo funkcija įvykdyta");
                } else {
                    webhook_log("KLAIDA: sendOrderConfirmationEmail funkcija nerasta");
                }

            } catch (Exception $e) {
                $pdo->rollBack();
                webhook_log("DB KLAIDA: " . $e->getMessage());
                http_response_code(500);
                exit();
            }
        } else {
            webhook_log("Užsakymas nerastas arba jau apmokėtas. Status: " . ($order['status'] ?? 'N/A'));
        }
    } else {
        webhook_log("Klaida: Negautas order_id iš Stripe sesijos");
    }
} else {
    webhook_log("Ignoruojamas eventas: " . $event->type);
}

http_response_code(200);
