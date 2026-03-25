<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/WebToPay.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../helpers.php'; // <--- BŪTINA ĮTRAUKTI

$pdo = getPdo();
ensureOrdersTables($pdo);
$config = require __DIR__ . '/config.php';

try {
    $response = WebToPay::parseCallback($_REQUEST, $config['sign_password']);
    $orderId = isset($response['orderid']) ? (int)$response['orderid'] : 0;
    $status = $response['status'] ?? '';
    $isTest = isset($response['test']) && (string)$response['test'] !== '';

    if ($orderId) {
        $paidStatuses = ['1', '2', '3', 'paid', 'completed', 'paid_ok', 'test'];
        $isPaid = in_array($status, $paidStatuses, true) || ($isTest && in_array($status, ['0', 'pending'], true));

        if ($isPaid) {

            // ==========================================
            // SIUNTŲ AUTOMATIZACIJA (PIRMA, KAD TURĖTUME KODĄ)
            // ==========================================
            try {
                $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
                $stmt->execute([$orderId]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($order && empty($order['tracking_number']) && mb_strtolower($order['status']) !== 'apmokėta') {
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
                                
                                $stmtUpdate = $pdo->prepare("UPDATE orders SET lpexpress_parcel_id = ?, lpexpress_request_id = ?, tracking_number = ? WHERE id = ?");
                                $stmtUpdate->execute([$parcelId, $requestId, $barcode, $orderId]);
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
                                $stmtUpdate = $pdo->prepare("UPDATE orders SET tracking_number = ? WHERE id = ?");
                                $stmtUpdate->execute([$barcode, $orderId]);
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                if (function_exists('logError')) {
                    logError('Klaida kuriant siuntą Paysera callback metu: ', $e);
                }
            }
            // ==========================================

            // DABAR PATVIRTINAME UŽSAKYMĄ IR SIUNČIAME LAIŠKUS
            approveOrder($pdo, $orderId);

        } 
        elseif ($status === '0' || $status === 'pending') 
        {
            $pdo->prepare('UPDATE orders SET status = ? WHERE id = ? AND status != ?')->execute(['laukiama apmokėjimo', $orderId, 'apmokėta']);
        } 
        else 
        {
            $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute(['atmesta', $orderId]);
        }
    }
    echo 'OK';
} catch (Exception $e) {
    http_response_code(400);
    if (function_exists('logError')) {
        logError('Paysera callback validation failed', $e);
    }
    echo 'ERROR';
}