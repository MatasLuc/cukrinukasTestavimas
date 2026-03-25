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
    require_once __DIR__ . '/lpexpress_helper.php'; // Įtraukiame LP Express Helperį
    
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
// UŽSAKYMO TVARKYMAS IR AUTOMATIZACIJA
// ---------------------------------------------------

if ($orderId) {
    $pdo = getPdo();
    
    // Naudojame centralizuotą funkciją ir paduodame Payment Intent ID, kad pririštų turgelio prekes
    if (function_exists('completeOrder')) {
        $result = completeOrder($pdo, $orderId, true, $paymentIntentId);
        if ($result) {
            webhook_log("Užsakymas #$orderId sėkmingai užbaigtas (Webhooks inicijavo).");
            
            try {
                // Gauname užsakymo duomenis
                $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
                $stmt->execute([$orderId]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($order && in_array($order['delivery_method'], ['lpexpress_terminal', 'lpexpress_courier'])) {
                    // === LP EXPRESS AUTOMATIZACIJA ===
                    webhook_log("Pradedamas LP Express siuntos kūrimas užsakymui #$orderId");
                    $lpHelper = new LPExpressHelper($pdo);
                    
                    $deliveryDetails = json_decode($order['delivery_details'], true);
                    $terminalId = $deliveryDetails['locker_id'] ?? null;
                    
                    $parcelId = $lpHelper->createParcel(
                        $orderId,
                        $order['delivery_method'],
                        $order['customer_name'],
                        $order['customer_phone'],
                        $order['customer_email'],
                        $order['customer_address'],
                        $terminalId
                    );
                    
                    if ($parcelId) {
                        webhook_log("LP Express siunta sukurta. Parcel ID: $parcelId");
                        $requestId = $lpHelper->initiateShipping($parcelId);
                        
                        if ($requestId) {
                            webhook_log("LP Express siunta inicijuota. Request ID: $requestId");
                            sleep(2);
                            $barcode = $lpHelper->getShippingStatus($requestId);
                            webhook_log("Gautas barkodas: " . ($barcode ?? 'NEGAUTA'));
                            
                            $stmtUpdate = $pdo->prepare("
                                UPDATE orders 
                                SET lpexpress_parcel_id = ?, 
                                    lpexpress_request_id = ?, 
                                    tracking_number = ? 
                                WHERE id = ?
                            ");
                            $stmtUpdate->execute([$parcelId, $requestId, $barcode, $orderId]);
                            webhook_log("LP Express duomenys išsaugoti DB.");
                        } else {
                            webhook_log("KLAIDA: Nepavyko inicijuoti siuntos (nėra requestId).");
                        }
                    } else {
                        webhook_log("KLAIDA: Nepavyko sukurti siuntos (nėra parcelId).");
                    }
                } 
                elseif ($order && $order['delivery_method'] === 'omniva_terminal') {
                    // === OMNIVA AUTOMATIZACIJA ===
                    webhook_log("Pradedamas Omniva siuntos kūrimas užsakymui #$orderId");
                    require_once __DIR__ . '/omniva_helper.php';
                    $omnivaHelper = new OmnivaHelper($pdo);
                    
                    $deliveryDetails = json_decode($order['delivery_details'], true);
                    $terminalId = $deliveryDetails['locker_id'] ?? null;
                    
                    if ($terminalId) {
                        $barcode = $omnivaHelper->createParcel(
                            $orderId,
                            $order['customer_name'],
                            $order['customer_phone'],
                            $order['customer_email'],
                            $terminalId
                        );
                        
                        if ($barcode) {
                            webhook_log("Omniva siunta sukurta. Barkodas: $barcode");
                            $stmtUpdate = $pdo->prepare("UPDATE orders SET tracking_number = ? WHERE id = ?");
                            $stmtUpdate->execute([$barcode, $orderId]);
                            webhook_log("Omniva duomenys išsaugoti DB.");
                        } else {
                            webhook_log("KLAIDA: Nepavyko sukurti Omniva siuntos.");
                        }
                    } else {
                        webhook_log("KLAIDA: Nėra terminalId Omniva siuntai.");
                    }
                } 
                else {
                    webhook_log("Siuntų automatizacija praleista (pristatymo būdas: " . ($order['delivery_method'] ?? 'N/A') . ")");
                }
            } catch (Exception $e) {
                webhook_log("Siuntų automatizacijos klaida: " . $e->getMessage());
            }

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