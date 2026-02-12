<?php
session_start();
require __DIR__ . '/../db.php';
require __DIR__ . '/WebToPay.php';
require __DIR__ . '/helpers.php';

$pdo = getPdo();
ensureOrdersTables($pdo);
$config = require __DIR__ . '/config.php';

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$orderStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    echo 'Užsakymas nerastas';
    exit;
}

WebToPay::redirectToPayment(buildPayseraParams($order, $config));
