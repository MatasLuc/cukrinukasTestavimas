<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/WebToPay.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../order_functions.php';

function paysera_webhook_log($msg) {
    $logFile = __DIR__ . '/../paysera_webhook_log.txt';
    $entry = date('Y-m-d H:i:s') . " - " . $msg . "\n";
    file_put_contents($logFile, $entry, FILE_APPEND);
}

paysera_webhook_log("=================================================");
paysera_webhook_log("--- Paysera Callback gautas ---");
paysera_webhook_log("Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));

$pdo = getPdo();
ensureOrdersTables($pdo);
$config = require __DIR__ . '/config.php';

try {
    $requestData = array_merge($_GET, $_POST);
    
    $rawBody = file_get_contents('php://input');
    if (empty($requestData['data']) && !empty($rawBody)) {
        parse_str($rawBody, $parsedRaw);
        if (is_array($parsedRaw)) {
            $requestData = array_merge($requestData, $parsedRaw);
        }
    }

    // PATAISYMAS: Paysera siunčia parašą 'ss1' kintamajame, bet jūsų WebToPay klasė ieško 'sign'.
    // Priskiriame ss1 reikšmę į sign.
    if (empty($requestData['sign']) && !empty($requestData['ss1'])) {
        $requestData['sign'] = $requestData['ss1'];
    }

    if (empty($requestData['data'])) {
        throw new Exception("Missing Paysera data. Duomenys nepasiekė skripto.");
    }

    // Naudojame custom failo metodą
    $response = WebToPay::parseCallback($requestData, $config['sign_password']);
    
    $orderId = isset($response['orderid']) ? (int)$response['orderid'] : 0;
    $status = (string)($response['status'] ?? '');
    $isTest = !empty($response['test']) && $response['test'] != '0';

    paysera_webhook_log("Callback analizė: Order ID = $orderId, Status = $status, IsTest = " . ($isTest ? 'Taip' : 'Ne'));

    if ($orderId) {
        $paidStatuses = ['1', '2', '3', 'paid', 'completed', 'paid_ok'];
        $isPaid = in_array($status, $paidStatuses, true) || ($isTest && in_array($status, ['0', '1', 'pending'], true));

        if ($isPaid) {
            paysera_webhook_log("Statusas ($status) identifikuotas kaip APMOKĖTAS.");

            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($order) {
                $currentStatus = mb_strtolower($order['status']);
                if (!in_array($currentStatus, ['apmokėta', 'apdorojama', 'išsiųsta', 'įvykdyta'])) {
                    
                    $stmtLock = $pdo->prepare("UPDATE orders SET status = 'apdorojama' WHERE id = ? AND status = ?");
                    $stmtLock->execute([$orderId, $order['status']]);
                    
                    if ($stmtLock->rowCount() > 0) {
                        paysera_webhook_log("Užsakymas #$orderId SĖKMINGAI užrakintas. Pradedamas siuntos kūrimas.");

                        // SIUNTŲ AUTOMATIZACIJA
                        try {
                            $deliveryDetails = json_decode($order['delivery_details'], true);
                            $terminalId = $deliveryDetails['locker_id'] ?? null;

                            if (in_array($order['delivery_method'], ['lpexpress_terminal', 'lpexpress_courier'])) {
                                require_once __DIR__ . '/../lpexpress_helper.php';
                                $lpHelper = new LPExpressHelper($pdo);
                                $parcelId = $lpHelper->createParcel($orderId, $order['delivery_method'], $order['customer_name'], $order['customer_phone'], $order['customer_email'], $order['customer_address'], $terminalId);
                                
                                if ($parcelId && ($requestId = $lpHelper->initiateShipping($parcelId))) {
                                    sleep(2);
                                    $barcode = $lpHelper->getShippingStatus($requestId);
                                    $pdo->prepare("UPDATE orders SET lpexpress_parcel_id = ?, lpexpress_request_id = ?, tracking_number = ? WHERE id = ?")->execute([$parcelId, $requestId, $barcode, $orderId]);
                                }
                            } elseif ($order['delivery_method'] === 'omniva_terminal' && $terminalId) {
                                require_once __DIR__ . '/../omniva_helper.php';
                                $omnivaHelper = new OmnivaHelper($pdo);
                                $barcode = $omnivaHelper->createParcel($orderId, $order['customer_name'], $order['customer_phone'], $order['customer_email'], $terminalId);
                                if ($barcode) {
                                    $pdo->prepare("UPDATE orders SET tracking_number = ? WHERE id = ?")->execute([$barcode, $orderId]);
                                }
                            }
                        } catch (Throwable $e) { 
                            paysera_webhook_log('KLAIDA (Siuntų modulyje): ' . $e->getMessage());
                        }

                        // PATVIRTINAME UŽSAKYMĄ IR SIUNČIAME LAIŠKUS
                        $paymentIntentId = 'PAYSERA_' . $orderId;
                        try {
                            $result = completeOrder($pdo, $orderId, true, $paymentIntentId);
                            if ($result) paysera_webhook_log("Užsakymas #$orderId SĖKMINGAI užbaigtas.");
                        } catch (Throwable $e) {
                            paysera_webhook_log('KLAIDA užbaigiant užsakymą: ' . $e->getMessage());
                        }
                    }
                }
            }
        } elseif ($status === '0' || $status === 'pending') {
            $pdo->prepare("UPDATE orders SET status = ? WHERE id = ? AND status NOT IN ('apmokėta', 'apdorojama', 'išsiųsta', 'įvykdyta')")->execute(['laukiama apmokėjimo', $orderId]);
        } else {
            $pdo->prepare("UPDATE orders SET status = ? WHERE id = ? AND status NOT IN ('apmokėta', 'apdorojama', 'išsiųsta', 'įvykdyta')")->execute(['atmesta', $orderId]);
        }
    }
    echo 'OK';

} catch (Throwable $e) { 
    http_response_code(400);
    paysera_webhook_log('KLAIDA: ' . $e->getMessage());
    echo 'ERROR';
}