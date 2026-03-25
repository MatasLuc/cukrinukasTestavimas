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

paysera_webhook_log("=================================================");
paysera_webhook_log("--- Paysera Callback gautas ---");
paysera_webhook_log("Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
paysera_webhook_log("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'UNKNOWN'));
paysera_webhook_log("Query String: " . ($_SERVER['QUERY_STRING'] ?? 'Nėra'));

$pdo = getPdo();
ensureOrdersTables($pdo);
$config = require __DIR__ . '/config.php';

try {
    paysera_webhook_log("GET parametrai: " . json_encode($_GET));
    paysera_webhook_log("POST parametrai: " . json_encode($_POST));
    
    $rawBody = file_get_contents('php://input');
    paysera_webhook_log("RAW Body turinys: " . (empty($rawBody) ? 'Tuščias' : $rawBody));

    // Sujungiame GET ir POST, kad užtikrintume duomenų gavimą bet kokiu atveju
    $requestData = array_merge($_GET, $_POST);

    // Jei serveris parametrus paslepia RAW body dalyje (retais POST serverių konfigūracijų atvejais)
    if (empty($requestData['data']) && !empty($rawBody)) {
        parse_str($rawBody, $parsedRaw);
        if (is_array($parsedRaw)) {
            $requestData = array_merge($requestData, $parsedRaw);
            paysera_webhook_log("Išgauti duomenys iš RAW Body: " . json_encode($parsedRaw));
        }
    }

    if (empty($requestData['data'])) {
        paysera_webhook_log("KLAIDA: Nėra 'data' parametro užklausoje! Nutraukiama.");
        throw new Exception("Missing Paysera data. Duomenys nepasiekė skripto.");
    }

    // Naudojame custom failo metodą
    $response = WebToPay::parseCallback($requestData, $config['sign_password']);
    paysera_webhook_log("Dekoduoti Paysera duomenys: " . json_encode($response));
    
    $orderId = isset($response['orderid']) ? (int)$response['orderid'] : 0;
    
    // Statusą būtinai paverčiame į string
    $status = (string)($response['status'] ?? '');
    $isTest = !empty($response['test']) && $response['test'] != '0';

    paysera_webhook_log("Suma: " . ($response['amount'] ?? 'Nėra') . " " . ($response['currency'] ?? ''));
    paysera_webhook_log("Callback analizė: Order ID = $orderId, Status = $status, IsTest = " . ($isTest ? 'Taip' : 'Ne'));

    if ($orderId) {
        $paidStatuses = ['1', '2', '3', 'paid', 'completed', 'paid_ok'];
        // Jei tai testinis mokėjimas, Paysera taip pat grąžina status=1
        $isPaid = in_array($status, $paidStatuses, true) || ($isTest && in_array($status, ['0', '1', 'pending'], true));

        if ($isPaid) {
            paysera_webhook_log("Statusas ($status) identifikuotas kaip APMOKĖTAS.");

            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                paysera_webhook_log("KLAIDA: Užsakymas #$orderId nerastas duomenų bazėje.");
            } else {
                $currentStatus = mb_strtolower($order['status']);
                paysera_webhook_log("Užsakymas #$orderId rastas. Dabartinis statusas DB: " . $currentStatus);

                // Tikriname ar užsakymas dar nebuvo apmokėtas
                if (!in_array($currentStatus, ['apmokėta', 'apdorojama', 'išsiųsta', 'įvykdyta'])) {
                    
                    paysera_webhook_log("Bandoma užrakinti užsakymą #$orderId (Apsauga nuo dvigubo apdorojimo).");
                    // RACE CONDITION UŽRAKTAS
                    $stmtLock = $pdo->prepare("UPDATE orders SET status = 'apdorojama' WHERE id = ? AND status = ?");
                    $stmtLock->execute([$orderId, $order['status']]);
                    
                    if ($stmtLock->rowCount() > 0) {
                        paysera_webhook_log("Užsakymas #$orderId SĖKMINGAI užrakintas. Pradedamas siuntos kūrimas.");

                        // ==========================================
                        // SIUNTŲ AUTOMATIZACIJA
                        // ==========================================
                        try {
                            $deliveryDetails = json_decode($order['delivery_details'], true);
                            $terminalId = $deliveryDetails['locker_id'] ?? null;
                            paysera_webhook_log("Siuntos būdas: " . $order['delivery_method'] . ". Terminalo ID: " . ($terminalId ?: 'Nėra'));

                            if (in_array($order['delivery_method'], ['lpexpress_terminal', 'lpexpress_courier'])) {
                                require_once __DIR__ . '/../lpexpress_helper.php';
                                $lpHelper = new LPExpressHelper($pdo);
                                
                                paysera_webhook_log("Inicijuojamas LPExpress siuntos kūrimas.");
                                $parcelId = $lpHelper->createParcel(
                                    $orderId,
                                    $order['delivery_method'],
                                    $order['customer_name'],
                                    $order['customer_phone'],
                                    $order['customer_email'],
                                    $order['customer_address'],
                                    $terminalId
                                );
                                paysera_webhook_log("LPExpress createParcel atsakymas (Parcel ID): " . ($parcelId ?: 'KLAIDA'));
                                
                                if ($parcelId) {
                                    $requestId = $lpHelper->initiateShipping($parcelId);
                                    paysera_webhook_log("LPExpress initiateShipping atsakymas (Request ID): " . ($requestId ?: 'KLAIDA'));
                                    if ($requestId) {
                                        sleep(2);
                                        $barcode = $lpHelper->getShippingStatus($requestId);
                                        paysera_webhook_log("LPExpress gautas brūkšninis kodas: " . ($barcode ?: 'KLAIDA (negautas)'));
                                        
                                        $stmtUpdate = $pdo->prepare("UPDATE orders SET lpexpress_parcel_id = ?, lpexpress_request_id = ?, tracking_number = ? WHERE id = ?");
                                        $stmtUpdate->execute([$parcelId, $requestId, $barcode, $orderId]);
                                        paysera_webhook_log("LPExpress duomenys išsaugoti į DB.");
                                    }
                                }
                            } elseif ($order['delivery_method'] === 'omniva_terminal') {
                                require_once __DIR__ . '/../omniva_helper.php';
                                $omnivaHelper = new OmnivaHelper($pdo);
                                
                                if ($terminalId) {
                                    paysera_webhook_log("Inicijuojamas Omniva siuntos kūrimas.");
                                    $barcode = $omnivaHelper->createParcel(
                                        $orderId,
                                        $order['customer_name'],
                                        $order['customer_phone'],
                                        $order['customer_email'],
                                        $terminalId
                                    );
                                    paysera_webhook_log("Omniva createParcel atsakymas (Barcode): " . ($barcode ?: 'KLAIDA'));
                                    
                                    if ($barcode) {
                                        $stmtUpdate = $pdo->prepare("UPDATE orders SET tracking_number = ? WHERE id = ?");
                                        $stmtUpdate->execute([$barcode, $orderId]);
                                        paysera_webhook_log("Omniva duomenys išsaugoti į DB.");
                                    }
                                } else {
                                    paysera_webhook_log("KLAIDA: Omniva terminalo ID nerastas užsakyme.");
                                }
                            } else {
                                paysera_webhook_log("Siuntos automatizacija nereikalinga pasirinktam pristatymo būdui.");
                            }
                        } catch (Throwable $e) { 
                            paysera_webhook_log('KLAIDA (Siuntų modulyje): ' . $e->getMessage() . " pėdsakas: " . $e->getTraceAsString());
                        }

                        // ==========================================
                        // PATVIRTINAME UŽSAKYMĄ IR SIUNČIAME LAIŠKUS
                        // ==========================================
                        $paymentIntentId = 'PAYSERA_' . $orderId;
                        
                        try {
                            paysera_webhook_log("Kviečiama completeOrder() funkcija.");
                            $result = completeOrder($pdo, $orderId, true, $paymentIntentId);
                            if ($result) {
                                paysera_webhook_log("Užsakymas #$orderId SĖKMINGAI užbaigtas (completeOrder gražino true).");
                            } else {
                                paysera_webhook_log("ĮSPĖJIMAS: Užsakymas #$orderId neužbaigtas (completeOrder gražino false).");
                            }
                        } catch (Throwable $e) {
                            paysera_webhook_log('KLAIDA užbaigiant užsakymą (completeOrder): ' . $e->getMessage() . " pėdsakas: " . $e->getTraceAsString());
                        }
                    } else {
                        paysera_webhook_log("ĮSPĖJIMAS: Užsakymas #$orderId jau apdorojamas kito proceso (užrakto nepavyko uždėti).");
                    }
                } else {
                    paysera_webhook_log("ĮSPĖJIMAS: Užsakymas #$orderId jau apmokėtas arba tolesniame statuse ($currentStatus). Tolesni veiksmai nutraukiami.");
                }
            }
        } 
        elseif ($status === '0' || $status === 'pending') 
        {
            paysera_webhook_log("Statusas ($status) - priskiriama 'laukiama apmokėjimo'.");
            $pdo->prepare("UPDATE orders SET status = ? WHERE id = ? AND status NOT IN ('apmokėta', 'apdorojama', 'išsiųsta', 'įvykdyta')")->execute(['laukiama apmokėjimo', $orderId]);
        } 
        else 
        {
            paysera_webhook_log("Statusas ($status) - priskiriama 'atmesta'.");
            $pdo->prepare("UPDATE orders SET status = ? WHERE id = ? AND status NOT IN ('apmokėta', 'apdorojama', 'išsiųsta', 'įvykdyta')")->execute(['atmesta', $orderId]);
        }
    } else {
        paysera_webhook_log("ĮSPĖJIMAS: Užsakymo ID nerastas gautuose duomenyse.");
    }
    
    paysera_webhook_log("Skriptas baigė darbą sėkmingai, grąžinama 'OK'.");
    echo 'OK';

} catch (Throwable $e) { 
    http_response_code(400);
    paysera_webhook_log('KLAIDA (Skripto lygio): ' . $e->getMessage() . " eilutėje " . $e->getLine());
    echo 'ERROR';
}