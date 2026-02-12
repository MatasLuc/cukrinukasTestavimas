<?php
session_start();
require __DIR__ . '/../db.php';
require __DIR__ . '/WebToPay.php';

$config = require __DIR__ . '/config.php';
$pdo = getPdo();
ensureOrdersTables($pdo);

try {
    $response = WebToPay::parseCallback($_REQUEST, $config['sign_password']);
    $orderId = isset($response['orderid']) ? (int)$response['orderid'] : 0;
    $status = $response['status'] ?? '';
    $isTest = isset($response['test']) && (string)$response['test'] !== '';
    
    // Paimame sumą ir valiutą iš Paysera atsakymo (suma būna centais)
    $amountCents = isset($response['amount']) ? (int)$response['amount'] : 0;
    $currency = isset($response['currency']) ? (string)$response['currency'] : 'EUR';

    if ($orderId) {
        $paidStatuses = ['1', '2', '3', 'paid', 'completed', 'paid_ok', 'test'];
        $isPaid = in_array($status, $paidStatuses, true) || ($isTest && in_array($status, ['0', 'pending'], true));
        
        $newStatus = $isPaid
            ? 'apmokėta'
            : ($status === '0' || $status === 'pending' ? 'laukiama apmokėjimo' : 'atmesta');
            
        $stmt = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $stmt->execute([$newStatus, $orderId]);

        // SVARBU: Jei apmokėjimas sėkmingas, išsaugome duomenis sesijoje GTM'ui
        if ($isPaid) {
            $_SESSION['gtm_purchase_event'] = [
                'transaction_id' => (string)$orderId,
                'value' => $amountCents / 100, // Konvertuojame centus į eurus
                'currency' => $currency
            ];
        }
    }
    $_SESSION['flash_success'] = 'Apmokėjimas patvirtintas. Ačiū!';
} catch (Exception $e) {
    logError('Payment confirmation failed', $e);
    $_SESSION['flash_error'] = 'Nepavyko patvirtinti mokėjimo. Bandykite dar kartą arba susisiekite su mumis.';
}

// GUEST CHECKOUT FIX: Jei vartotojas neprisijungęs, metame į viešą sėkmės puslapį
if (isset($_SESSION['user_id'])) {
    header('Location: /orders.php');
} else {
    header('Location: /order_success.php');
}
exit;
