<?php
session_start();
require __DIR__ . '/../db.php';
require __DIR__ . '/WebToPay.php';

$pdo = getPdo();
ensureOrdersTables($pdo);
$config = require __DIR__ . '/config.php';

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : null;

try {
    if (!empty($_REQUEST['data']) && !empty($_REQUEST['sign'])) {
        $response = WebToPay::parseCallback($_REQUEST, $config['sign_password']);
        $orderId = isset($response['orderid']) ? (int)$response['orderid'] : $orderId;
    }
} catch (Exception $e) {
    // Ignore invalid signature on cancel, we'll still show cancel message
}

if ($orderId) {
    $stmt = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
    $stmt->execute(['atšaukta', $orderId]);
}

$_SESSION['flash_error'] = 'Apmokėjimas atšauktas.';
header('Location: /cart.php');
exit;
