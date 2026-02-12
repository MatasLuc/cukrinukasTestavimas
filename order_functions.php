<?php
// order_functions.php
require_once __DIR__ . '/mailer.php';

function sendOrderConfirmationEmail($orderId, $pdo) {
    // Gauname užsakymo info
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) return;

    // Gauname prekes
    $stmtItems = $pdo->prepare("
        SELECT oi.*, p.title 
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
    ");
    $stmtItems->execute([$orderId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // Formuojame HTML lentelę
    $itemsHtml = '<table style="width:100%; border-collapse: collapse; margin-bottom: 20px;">
                    <tr style="background: #f8f8f8;">
                        <th style="padding: 10px; text-align: left;">Prekė</th>
                        <th style="padding: 10px; text-align: center;">Kiekis</th>
                        <th style="padding: 10px; text-align: right;">Kaina</th>
                    </tr>';
    
    foreach ($items as $item) {
        $rowTotal = number_format($item['price'] * $item['quantity'], 2);
        $itemsHtml .= "<tr>
                        <td style='padding: 10px; border-bottom: 1px solid #eee;'>{$item['title']}</td>
                        <td style='padding: 10px; border-bottom: 1px solid #eee; text-align: center;'>{$item['quantity']}</td>
                        <td style='padding: 10px; border-bottom: 1px solid #eee; text-align: right;'>{$rowTotal} €</td>
                       </tr>";
    }
    $itemsHtml .= '</table>';

    // Pagrindinis laiško tekstas
    $subject = "Užsakymo patvirtinimas #" . $orderId;
    $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333;'>
            <h1 style='color: #2c3e50;'>Ačiū už jūsų užsakymą!</h1>
            <p>Sveiki, <strong>{$order['customer_name']}</strong>,</p>
            <p>Jūsų užsakymas <strong>#{$orderId}</strong> sėkmingai gautas ir apmokėtas.</p>
            
            <h3>Užsakymo detalės:</h3>
            {$itemsHtml}
            
            <p style='text-align: right; font-size: 18px;'><strong>Iš viso: " . number_format($order['total'], 2) . " €</strong></p>
            
            <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
            <p><strong>Pristatymo informacija:</strong><br>
            {$order['delivery_method']}<br>
            {$order['customer_address']}</p>
        </div>
    ";

    // Siunčiame pirkėjui
    sendEmail($order['customer_email'], $order['customer_name'], $subject, $body);

    // Siunčiame administratoriui
    $adminSubject = "Naujas užsakymas #" . $orderId . " (" . $order['total'] . " €)";
    sendEmail('labas@cukrinukas.lt', 'Cukrinukas Admin', $adminSubject, $body);
}
