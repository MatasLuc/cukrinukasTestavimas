<?php
session_start();
require __DIR__ . '/../db.php';
require __DIR__ . '/WebToPay.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../order_functions.php';

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
    $requestData = array_merge($_GET, $_POST);
    
    // PATAISYMAS: Paysera siunčia parašą 'ss1' kintamajame, bet jūsų WebToPay klasė ieško 'sign'.
    if (empty($requestData['sign']) && !empty($requestData['ss1'])) {
        $requestData['sign'] = $requestData['ss1'];
    }

    // Jeigu nėra duomenų - vadinasi klientas tiesiog grįžo iš Payseros nepateikęs callback kintamųjų URL adrese
    if (empty($requestData['data'])) {
        throw new Exception("Nėra 'data' parametro užklausoje. Galbūt tai tiesioginis perėjimas.");
    }

    $response = WebToPay::parseCallback($requestData, $config['sign_password']);
    $orderId = isset($response['orderid']) ? (int)$response['orderid'] : 0;
    $status = (string)($response['status'] ?? '');
    $isTest = !empty($response['test']) && $response['test'] != '0';
    $amountCents = isset($response['amount']) ? (int)$response['amount'] : 0;
    $currency = isset($response['currency']) ? (string)$response['currency'] : 'EUR';

    paysera_accept_log("Accept užklausa: Order ID = $orderId, Status = $status");

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

            if ($order && !in_array(mb_strtolower($order['status']), ['apmokėta', 'apdorojama', 'išsiųsta', 'įvykdyta'])) {
                $stmtLock = $pdo->prepare("UPDATE orders SET status = 'apdorojama' WHERE id = ? AND status = ?");
                $stmtLock->execute([$orderId, $order['status']]);
                
                if ($stmtLock->rowCount() > 0) {
                    paysera_accept_log("Užsakymas #$orderId užrakintas accept failo.");
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
                    } catch (Throwable $e) {}

                    $paymentIntentId = 'PAYSERA_' . $orderId;
                    completeOrder($pdo, $orderId, true, $paymentIntentId);
                }
            }

            $_SESSION['flash_success'] = 'Apmokėjimas patvirtintas. Ačiū!';
            unset($_SESSION['cart'], $_SESSION['cart_community'], $_SESSION['checkout_delivery'], $_SESSION['cart_variations']);
            
        } else {
            $newStatus = ($status === '0' || $status === 'pending') ? 'laukiama apmokėjimo' : 'atmesta';
            $pdo->prepare("UPDATE orders SET status = ? WHERE id = ? AND status NOT IN ('apmokėta', 'apdorojama', 'išsiųsta', 'įvykdyta')")->execute([$newStatus, $orderId]);
            $_SESSION['flash_error'] = 'Mokėjimas nebuvo įvykdytas. Bandykite dar kartą arba susisiekite su mumis.';
        }
    }
} catch (Throwable $e) {
    paysera_accept_log('Klaida accept.php bloke: ' . $e->getMessage());
    
    // Pabandome atkurti orderId iš GET kintamųjų
    $orderId = $orderId ?: ((int)($_GET['order_id'] ?? $_GET['orderid'] ?? 0));

    if ($orderId) {
        $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $statusDB = mb_strtolower((string)$stmt->fetchColumn());
        
        if (in_array($statusDB, ['apmokėta', 'apdorojama', 'išsiųsta', 'įvykdyta'])) {
            $_SESSION['flash_success'] = 'Apmokėjimas patvirtintas. Ačiū!';
            unset($_SESSION['cart'], $_SESSION['cart_community'], $_SESSION['checkout_delivery'], $_SESSION['cart_variations']);
        } else {
            $_SESSION['flash_error'] = 'Nepavyko patvirtinti mokėjimo. Susisiekite su mumis.';
        }
    } else {
        $_SESSION['flash_error'] = 'Nepavyko patvirtinti mokėjimo. Bandykite dar kartą.';
    }
}

if (isset($_SESSION['user_id'])) {
    header('Location: /orders.php');
} else {
    header('Location: /order_success.php?order_id=' . urlencode((string)$orderId));
}
exit;