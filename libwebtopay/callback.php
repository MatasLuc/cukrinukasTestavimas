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
            // Naudojame naują funkciją iš helpers.php
            approveOrder($pdo, $orderId);
        } 
        elseif ($status === '0' || $status === 'pending') 
        {
            // Jei dar laukiama (pending), bet ne testinis - atnaujinam statusą, bet atsargų nemažinam
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
