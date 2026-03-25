<?php
session_start();
require __DIR__ . '/../db.php';
require __DIR__ . '/WebToPay.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../order_functions.php'; // BŪTINA ĮTRAUKTI

$config = require __DIR__ . '/config.php';
$pdo = getPdo();
ensureOrdersTables($pdo);

function paysera_accept_log($msg) {
    $logFile = __DIR__ . '/../paysera_webhook_log.txt';
    $entry = date('Y-m-d H:i:s') . " [ACCEPT] - " . $msg . "\n";
    file_put_contents($logFile, $entry, FILE_APPEND);
}

$orderId = 0;

try {
    // Jeigu Paysera neperdavė jokių parametrų, negalime validuoti su parseCallback
    if (empty($_REQUEST['data'])) {
        throw new Exception("Nėra 'data' parametro užklausoje. Galbūt tai tiesioginis perėjimas.");
    }

    $response = WebToPay::parseCallback($_REQUEST, $config['sign_password']);
    $orderId = isset($response['orderid']) ? (int)$response['orderid'] : 0;
    
    // Statusą BŪTINA paversti į string dėl PHP apribojimų
    $status = (string)($response['status'] ?? '');
    $isTest = !empty($response['test']) && $response['test'] != '0';
    
    $amountCents = isset($response['amount']) ? (int)$response['amount'] : 0;
    $currency = isset($response['currency']) ? (string)$response['currency'] : 'EUR';

    paysera_accept_log("Accept užklausa: Order ID = $orderId, Status = $status, IsTest = " . ($isTest ? 'Taip' : 'Ne'));

    if ($orderId) {
        $paidStatuses = ['1', '2', '3', 'paid', 'completed', 'paid_ok'];
        $isPaid = in_array($status, $paidStatuses, true) || ($isTest && in_array($status, ['0', '1', 'pending'], true));

        if ($isPaid) {
            $_SESSION['gtm_purchase_event'] = [
                'transaction_id' => (string)$orderId,
                'value' => $amountCents / 100,
                'currency' => $currency
            ];

            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            $currentStatus = $order ? mb_strtolower($order['status']) : '';

            // Jeigu fone esantis callback nespėjo suveikti, sutvarkome užsakymą čia
            if ($order && !in_array($currentStatus, ['apmokėta', 'apdorojama', 'išsiųsta', 'įvykdyta'])) {
                
                $stmtLock = $pdo->prepare("UPDATE orders SET status = 'apdorojama' WHERE id = ? AND status = ?");
                $stmtLock->execute([$orderId, $order['status']]);
                
                if ($stmtLock->rowCount() > 0) {
                    paysera_accept_log("Užsakymas #$orderId užrakintas accept failo. Pradedamas siuntos kūrimas.");

                    try {
                        if (in_array($order['delivery_method'], ['lpexpress_terminal', 'lpexpress_courier'])) {
                            require_once __DIR__ . '/../lpexpress_helper.php';
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
                                $requestId = $lpHelper->initiateShipping($parcelId);
                                if ($requestId) {
                                    sleep(2);
                                    $barcode = $lpHelper->getShippingStatus($requestId);
                                    $pdo->prepare("UPDATE orders SET lpexpress_parcel_id = ?, lpexpress_request_id = ?, tracking_number = ? WHERE id = ?")
                                        ->execute([$parcelId, $requestId, $barcode, $orderId]);
                                }
                            }
                        } elseif ($order['delivery_method'] === 'omniva_terminal') {
                            require_once __DIR__ . '/../omniva_helper.php';
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
                                    $pdo->prepare("UPDATE orders SET tracking_number = ? WHERE id = ?")
                                        ->execute([$barcode, $orderId]);
                                }
                            }
                        }
                    } catch (Exception $e) {
                        paysera_accept_log('Klaida kuriant siuntą accept.php metu: ' . $e->getMessage());
                    }

                    $paymentIntentId = 'PAYSERA_' . $orderId;
                    completeOrder($pdo, $orderId, true, $paymentIntentId);
                }
            }

            $_SESSION['flash_success'] = 'Apmokėjimas patvirtintas. Ačiū!';
            
            // Išvalome krepšelius
            unset($_SESSION['cart'], $_SESSION['cart_community'], $_SESSION['checkout_delivery'], $_SESSION['cart_variations']);
            
        } else {
            $newStatus = ($status === '0' || $status === 'pending') ? 'laukiama apmokėjimo' : 'atmesta';
            $pdo->prepare("UPDATE orders SET status = ? WHERE id = ? AND status NOT IN ('apmokėta', 'apdorojama', 'išsiųsta', 'įvykdyta')")->execute([$newStatus, $orderId]);
            $_SESSION['flash_error'] = 'Mokėjimas nebuvo įvykdytas (Statusas: '.$status.'). Bandykite dar kartą arba susisiekite su mumis.';
        }
    }
} catch (Exception $e) {
    paysera_accept_log('Klaida accept.php bloke: ' . $e->getMessage());
    
    // Pabandome atkurti orderId iš GET kintamųjų (jei perduodame)
    if (!$orderId && isset($_GET['order_id'])) {
        $orderId = (int)$_GET['order_id'];
    } elseif (!$orderId && isset($_GET['orderid'])) {
        $orderId = (int)$_GET['orderid'];
    }

    if ($orderId) {
        // Patikriname, ar tiesiog callback'as jau spėjo padaryti darbą
        $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $statusDB = mb_strtolower((string)$stmt->fetchColumn());
        
        if (in_array($statusDB, ['apmokėta', 'apdorojama', 'išsiųsta', 'įvykdyta'])) {
            $_SESSION['flash_success'] = 'Apmokėjimas patvirtintas. Ačiū!';
            unset($_SESSION['cart'], $_SESSION['cart_community'], $_SESSION['checkout_delivery'], $_SESSION['cart_variations']);
        } else {
            $_SESSION['flash_error'] = 'Nepavyko patvirtinti mokėjimo arba duomenys vėluoja. Susisiekite su mumis.';
        }
    } else {
        $_SESSION['flash_error'] = 'Nepavyko patvirtinti mokėjimo. Bandykite dar kartą arba susisiekite su mumis.';
    }
}

if (isset($_SESSION['user_id'])) {
    header('Location: /orders.php');
} else {
    header('Location: /order_success.php?