<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php'; 

function completeOrder(PDO $pdo, int $orderId, string $paymentMethod = 'Stripe'): bool {
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if (!$order) return false;

    // Apsauga nuo dubliavimo
    if (stripos($order['status'], 'apmokėta') !== false || stripos($order['status'], 'patvirtintas') !== false) {
        return true;
    }

    try {
        $pdo->beginTransaction();

        // Atnaujiname statusą
        $updateStmt = $pdo->prepare("UPDATE orders SET status = 'Apmokėta', payment_method = ?, updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$paymentMethod, $orderId]);

        // Sumažiname likučius
        $itemsStmt = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll();

        $deductStmt = $pdo->prepare("UPDATE products SET quantity = GREATEST(0, quantity - ?) WHERE id = ?");
        foreach ($items as $item) {
            $deductStmt->execute([(int)$item['quantity'], (int)$item['product_id']]);
        }

        // Siunčiame laišką
        $userEmail = !empty($order['customer_email']) ? $order['customer_email'] : '';
        if (!$userEmail) {
             $uStmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
             $uStmt->execute([$order['user_id']]);
             $userEmail = $uStmt->fetchColumn();
        }

        if ($userEmail && function_exists('sendEmail')) {
            $subject = "Užsakymas #{$orderId} patvirtintas";
            $body = "<h1>Ačiū!</h1><p>Jūsų užsakymas #{$orderId} gautas. Suma: {$order['total']} EUR. Statusas: Apmokėta.</p>";
            sendEmail($userEmail, $subject, $body);
        }

        $pdo->commit();
        return true;

    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}
?>
