<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/WebToPay.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../order_functions.php'; // BŪTINA ĮTRAUKTI

function paysera_webhook_log($msg) {
    $logFile = __DIR__ . '/../paysera_webhook_log.txt';
    $entry = date('Y-m-d H:i:s') . " - " . $msg . "\n";
    file_put_contents($logFile, $entry, FILE_APPEND);
}

paysera_webhook_log("--- Paysera Callback gautas ---");

$pdo = getPdo();
ensureOrdersTables($pdo);
$config = require __DIR__ . '/config.php';

try {
    $response = WebToPay::parseCallback($_REQUEST, $config['sign_password']);
    $orderId = isset($response['orderid']) ? (int)$response['orderid'] : 0;
    
    // BŪTINA paversti į string, kad veiktų in_array patikra!
    $status = (string)($response['status'] ?? '');
    $isTest = !empty($response['test']) && $response['test'] != '0';

    paysera_webhook_log("Callback duomenys: Order ID = $orderId, Status = $status, IsTest = " . ($isTest ? 'Taip' : 'Ne'));

    if ($orderId) {
        $paidStatuses = ['1', '2', '3', 'paid', 'completed', 'paid_ok'];
        // Testiniam režimui tinka ir statusas '0'
        $isPaid = in_array($status, $paidStatuses, true) || ($isTest && in_array($status, ['0', '1', 'pending'], true));

        if ($isPaid) {
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            $currentStatus = $order ? mb_strtolower($order['status']) : '';

            // Tikriname ar užsakymas dar nebuvo apmokėtas
            if ($order && !in_array($currentStatus, ['apmokėta', 'apdorojama', 'išsiųsta', 'įvykdyta'])) {
                
                // RACE CONDITION UŽRAKTAS
                $stmtLock = $pdo->prepare("UPDATE orders SET status = 'apdorojama' WHERE id = ? AND status = ?");
                $stmtLock->execute([$orderId, $order['status']]);
                
                if ($stmtLock->rowCount() > 0) {
                    paysera_webhook_log("Užsakymas #$orderId užrakintas. Pradedamas siuntos kūrimas.");

                    // ==========================================
                    // SIUNTŲ AUTOMATIZACIJA
                    // ==========================================
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
                    } catch (Exception $e) {
                        paysera_webhook_log('Klaida kuriant siuntą Paysera callback metu: ' . $e->getMessage());
                    }

                    // ==========================================
                    // PATVIRTINAME UŽSAKYMĄ IR SIUNČIAME LAIŠKUS
                    // ==========================================
                    $paymentIntentId = 'PAYSERA_' . $orderId;
                    $result = completeOrder($pdo, $orderId, true, $paymentIntentId);
                    if ($result) {
                        paysera_webhook_log("Užsakymas #$orderId sėkmingai užbaigtas.");
                    }
                } else {
                    paysera_webhook_log("Užsakymas #$orderId jau apdorojamas kito proceso.");
                }
            } else {
                paysera_webhook_log("Užsakymas #$orderId jau apmokėtas arba nerastas.");
            }
        } 
        elseif ($status === '0' || $status === 'pending') 
        {
            $pdo->prepare("UPDATE orders SET status = ? WHERE id = ? AND status NOT IN ('apmokėta', 'apdorojama', 'išsiųsta', 'įvykdyta')")->execute(['laukiama apmokėjimo', $orderId]);
        } 
        else 
        {
            $pdo->prepare("UPDATE orders SET status = ? WHERE id = ? AND status NOT IN ('apmokėta', 'apdorojama', 'išsiųsta', 'įvykdyta')")->execute(['atmesta', $orderId]);
        }
    }
    echo 'OK';
} catch (Exception $e) {
    http_response_code(400);
    paysera_webhook_log('Paysera callback validation failed: ' . $e->getMessage());
    echo 'ERROR';
}