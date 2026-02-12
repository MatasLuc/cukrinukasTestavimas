<?php
// order_functions.php
require_once __DIR__ . '/mailer.php';

function sendOrderConfirmationEmail($orderId, $pdo) {
    try {
        // 1. Gauname duomenis
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) return;

        $stmtItems = $pdo->prepare("
            SELECT oi.*, p.title 
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?
        ");
        $stmtItems->execute([$orderId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        // 2. Formuojame HTML
        $itemsHtml = '<table cellpadding="5" border="1" style="border-collapse: collapse; width: 100%;">';
        $itemsHtml .= '<tr style="background: #f0f0f0;"><th>Prekė</th><th>Kiekis</th><th>Kaina</th></tr>';
        
        foreach ($items as $item) {
            $name = htmlspecialchars($item['title'] ?? 'Prekė');
            $price = number_format($item['price'], 2);
            $total = number_format($item['price'] * $item['quantity'], 2);
            $itemsHtml .= "<tr>
                <td>{$name}</td>
                <td align='center'>{$item['quantity']}</td>
                <td align='right'>{$total} €</td>
            </tr>";
        }
        $itemsHtml .= '</table>';

        $deliveryInfo = '';
        if (!empty($order['delivery_details'])) {
             $details = json_decode($order['delivery_details'], true);
             if (isset($details['locker_address'])) {
                 $deliveryInfo = "<p><strong>Paštomatas:</strong> " . htmlspecialchars($details['locker_address']) . "</p>";
             }
        }

        $subject = "Užsakymo patvirtinimas #" . $orderId;
        $body = "
            <h2>Ačiū už užsakymą!</h2>
            <p>Sveiki, {$order['customer_name']},</p>
            <p>Jūsų užsakymas <strong>#{$orderId}</strong> apmokėtas.</p>
            <h3>Užsakymo informacija:</h3>
            {$itemsHtml}
            <p><strong>Iš viso: " . number_format($order['total'], 2) . " €</strong></p>
            <hr>
            <p><strong>Pristatymo adresas:</strong> {$order['customer_address']}</p>
            {$deliveryInfo}
        ";

        // 3. Siunčiame pirkėjui
        sendEmail($order['customer_email'], $order['customer_name'], $subject, $body);

        // 4. Siunčiame adminui
        $adminBody = "<h1>Gautas naujas užsakymas #$orderId</h1>" . $body;
        sendEmail('labas@cukrinukas.lt', 'Admin', "Naujas užsakymas #$orderId", $adminBody);

    } catch (Exception $e) {
        // Tik registruojame klaidą, bet nestabdome skripto, nes pinigai jau sumokėti
        error_log("Klaida siunčiant laišką (Order #$orderId): " . $e->getMessage());
        if (function_exists('webhook_log')) {
            webhook_log("Mailer Error: " . $e->getMessage());
        }
    }
}
