<?php
// order_functions.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php'; 

function completeOrder(PDO $pdo, int $orderId, string $paymentMethod = 'Stripe'): bool {
    // 1. Gauname užsakymo informaciją
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        return false;
    }

    // Apsauga: jei jau apmokėta, nieko nedarome, kad nesumažintume prekių antrą kartą
    $status = mb_strtolower($order['status']);
    if (strpos($status, 'apmokėta') !== false || strpos($status, 'patvirtintas') !== false || strpos($status, 'įvykdytas') !== false) {
        return true;
    }

    try {
        $pdo->beginTransaction();

        // 2. Atnaujiname statusą
        $updateStmt = $pdo->prepare("UPDATE orders SET status = 'Apmokėta', payment_method = ?, updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$paymentMethod, $orderId]);

        // 3. Sumažiname prekių likučius
        $itemsStmt = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll();

        $deductStmt = $pdo->prepare("UPDATE products SET quantity = GREATEST(0, quantity - ?) WHERE id = ?");
        foreach ($items as $item) {
            $deductStmt->execute([(int)$item['quantity'], (int)$item['product_id']]);
        }

        // 4. Siunčiame el. laišką
        // Paimame vartotojo email
        $userEmail = '';
        if (!empty($order['customer_email'])) {
             $userEmail = $order['customer_email'];
        } else {
             $uStmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
             $uStmt->execute([$order['user_id']]);
             $userEmail = $uStmt->fetchColumn();
        }

        if ($userEmail) {
            $subject = "Užsakymas #{$orderId} patvirtintas";
            $body = "
                <h1>Ačiū už jūsų užsakymą!</h1>
                <p>Jūsų užsakymas #{$orderId} sėkmingai apmokėtas.</p>
                <p>Suma: " . number_format($order['total'], 2) . " EUR</p>
                <p>Statusas: Apmokėta</p>
                <br>
                <p>Pagarbiai,<br>Cukrinukas.lt komanda</p>
            ";
            
            // Naudojame tavo mailer.php funkciją
            // (Darau prielaidą, kad tavo mailer.php turi funkciją sendEmail. 
            // Jei ji vadinasi kitaip, pvz. send_mail, pakoreguok čia)
            if (function_exists('sendEmail')) {
                sendEmail($userEmail, $subject, $body);
            }
        }

        $pdo->commit();
        return true;

    } catch (Exception $e) {
        $pdo->rollBack();
        // Galima įrašyti į logus: error_log($e->getMessage());
        return false;
    }
}
?>
