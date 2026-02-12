<?php
// order_functions.php
require_once __DIR__ . '/mailer.php'; 

function sendOrderConfirmationEmail($orderId, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) return;

    // Gauname prekes su pavadinimais
    $stmtItems = $pdo->prepare("
        SELECT oi.*, p.title 
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
    ");
    $stmtItems->execute([$orderId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // HTML lentelė
    $itemsHtml = '<table cellpadding="5" border="1" style="border-collapse:collapse; width:100%;">';
    $itemsHtml .= '<tr style="background:#eee;"><th>Prekė</th><th>Kiekis</th><th>Kaina</th></tr>';
    
    foreach ($items as $item) {
        $name = htmlspecialchars($item['title'] ?? 'Prekė');
        $qty = $item['quantity'];
        $price = number_format($item['price'], 2);
        $totalRow = number_format($item['price'] * $qty, 2);
        
        $itemsHtml .= "<tr>
            <td>$name</td>
            <td align='center'>$qty</td>
            <td align='right'>$totalRow €</td>
        </tr>";
    }
    $itemsHtml .= '</table>';

    $subject = "Užsakymo patvirtinimas #" . $orderId;
    $body = "
        <h2>Ačiū už užsakymą, {$order['customer_name']}!</h2>
        <p>Jūsų užsakymas <strong>#{$orderId}</strong> gautas ir apmokėtas.</p>
        <p>Būsena: <strong>Apmokėta</strong></p>
        <br>
        $itemsHtml
        <br>
        <p><strong>Bendra suma: {$order['total']} €</strong></p>
        <hr>
        <p>Pristatymo adresas: {$order['customer_address']}</p>
        <p>Pristatymo būdas: {$order['delivery_method']}</p>
    ";

    // Siunčiame klientui
    sendEmail($order['customer_email'], $order['customer_name'], $subject, $body);
    
    // Siunčiame administratoriui
    sendEmail('labas@cukrinukas.lt', 'Admin', "Naujas užsakymas #$orderId", $body);
}
