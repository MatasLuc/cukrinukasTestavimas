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

try {
    $response = WebToPay::parseCallback($_REQUEST, $config['sign_password']);
    $orderId = isset($response['orderid']) ? (int)$response['orderid'] : 0;
    $status = $response['status'] ?? '';
    $isTest = isset($response['test']) && (string)$response['test'] !== '';
    
    $amountCents = isset($response['amount']) ? (int)$response['amount'] : 0;
    $currency = isset($response['currency']) ? (string)$response['currency'] : 'EUR';

    if ($orderId) {
        $paidStatuses = ['1', '2', '3', 'paid', 'completed', 'paid_ok', 'test'];
        $isPaid = in_array($status, $paidStatuses, true) || ($isTest && in_array($status, ['0', 'pending'], true));

        if ($isPaid) {
            // Išsaugome GTM duomenis
            $_SESSION['gtm_purchase_event'] = [
                'transaction_id' => (string)$orderId,
                'value' => $amountCents / 100,
                'currency' => $currency
            ];

            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            $currentStatus = $order ? mb_strtolower($order['status']) : '';

            // Apdorojame lygiai taip pat kaip callback'e, jeigu jis nespėjo suveikti
            if ($order && $currentStatus !== 'apmokėta' && $currentStatus !== 'apdorojama' && $currentStatus !== 'išsiųsta' && $currentStatus !== 'įvykdyta') {
                
                $stmtLock = $pdo->prepare("UPDATE orders SET status = 'apdorojama' WHERE id = ? AND status = ?");
                $stmtLock->execute([$orderId, $order['status']]);
                
                if ($stmtLock->rowCount() > 0) {
                    paysera_accept_log("Užsakymas #$orderId užrakintas. Pradedamas siuntos kūrimas iš accept.php");

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
            
            // Išvalome krepšelius, nes sėkmingai apmokėta
            unset($_SESSION['cart']);
            unset($_SESSION['cart_community']);
            unset($_SESSION['checkout_delivery']);
            unset($_SESSION['cart_variations']);
            
        } else {
            $newStatus = ($status === '0' || $status === 'pending') ? 'laukiama apmokėjimo' : 'atmesta';
            $pdo->prepare('UPDATE orders SET status = ? WHERE id = ? AND status != ?')->execute([$newStatus, $orderId, 'apmokėta']);
            $_SESSION['flash_error'] = 'Nepavyko patvirtinti mokėjimo. Bandykite dar kartą arba susisiekite su mumis.';
        }
    }
} catch (Exception $e) {
    if (function_exists('logError')) logError('Payment confirmation failed', $e);
    $_SESSION['flash_error'] = 'Nepavyko patvirtinti mokėjimo. Bandykite dar kartą arba susisiekite su mumis.';
}

if (isset($_SESSION['user_id'])) {
    header('Location: /orders.php');
} else {
    header('Location: /order_success.php?order_id=' . urlencode($orderId ?? ''));
}
exit;