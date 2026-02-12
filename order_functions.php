<?php
// order_functions.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

function sendOrderConfirmationEmail($orderId, $pdo) {
    try {
        // 1. Gauname užsakymo informaciją
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            logMailer("Klaida: Užsakymas #$orderId nerastas DB.");
            return;
        }

        // 2. Gauname prekes
        $stmtItems = $pdo->prepare("
            SELECT oi.*, p.title, p.image_url 
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?
        ");
        $stmtItems->execute([$orderId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        // 3. Ištraukiame pristatymo informaciją
        $deliveryMethod = $order['delivery_method'] == 'locker' ? 'Paštomatas' : 
                         ($order['delivery_method'] == 'courier' ? 'Kurjeris' : 'Atsiėmimas');
        
        $deliveryDetails = json_decode($order['delivery_details'], true);
        $lockerAddress = isset($deliveryDetails['locker_address']) ? $deliveryDetails['locker_address'] : '';
        $fullAddress = $lockerAddress ? "Paštomatas: $lockerAddress" : $order['customer_address'];

        // 4. Formuojame HTML laiško turinį
        $itemsRows = '';
        foreach ($items as $item) {
            $title = htmlspecialchars($item['title'] ?? 'Prekė');
            $qty = $item['quantity'];
            $price = number_format($item['price'], 2);
            $totalItem = number_format($item['price'] * $qty, 2);
            
            $itemsRows .= "
            <tr>
                <td style='padding: 10px; border-bottom: 1px solid #eee;'>$title</td>
                <td style='padding: 10px; border-bottom: 1px solid #eee; text-align: center;'>$qty</td>
                <td style='padding: 10px; border-bottom: 1px solid #eee; text-align: right;'>$price €</td>
                <td style='padding: 10px; border-bottom: 1px solid #eee; text-align: right;'><strong>$totalItem €</strong></td>
            </tr>";
        }

        // Apskaičiuojame pristatymo kainą laiško atvaizdavimui
        $itemsTotal = 0;
        foreach($items as $i) $itemsTotal += $i['price'] * $i['quantity'];
        $shippingPrice = $order['total'] - $itemsTotal;
        $shippingRow = '';
        
        if ($shippingPrice > 0.001) {
            $shippingRow = "
            <tr>
                <td colspan='3' style='padding: 10px; text-align: right; color: #666;'>Pristatymas:</td>
                <td style='padding: 10px; text-align: right;'>" . number_format($shippingPrice, 2) . " €</td>
            </tr>";
        }

        $emailContent = "
        <html>
        <body style='font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0;'>
            <div style='max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.1);'>
                
                <div style='background-color: #e91e63; padding: 20px; text-align: center; color: white;'>
                    <h1 style='margin: 0; font-size: 24px;'>Užsakymas #$orderId patvirtintas!</h1>
                </div>

                <div style='padding: 30px;'>
                    <p>Sveiki, <strong>" . htmlspecialchars($order['customer_name']) . "</strong>,</p>
                    <p>Ačiū, kad perkate „Cukrinukas“. Gavome jūsų apmokėjimą ir pradedame ruošti užsakymą.</p>
                    
                    <h3 style='border-bottom: 2px solid #e91e63; padding-bottom: 10px; color: #333;'>Užsakymo detalės</h3>
                    <table style='width: 100%; border-collapse: collapse; margin-top: 10px;'>
                        <thead>
                            <tr style='background-color: #f9f9f9;'>
                                <th style='padding: 10px; text-align: left;'>Prekė</th>
                                <th style='padding: 10px; text-align: center;'>Kiekis</th>
                                <th style='padding: 10px; text-align: right;'>Vnt.</th>
                                <th style='padding: 10px; text-align: right;'>Suma</th>
                            </tr>
                        </thead>
                        <tbody>
                            $itemsRows
                        </tbody>
                        <tfoot>
                            $shippingRow
                            <tr>
                                <td colspan='3' style='padding: 15px; text-align: right; font-size: 18px;'><strong>VISO:</strong></td>
                                <td style='padding: 15px; text-align: right; font-size: 18px; color: #e91e63;'><strong>" . number_format($order['total'], 2) . " €</strong></td>
                            </tr>
                        </tfoot>
                    </table>

                    <div style='background-color: #f9f9f9; padding: 15px; margin-top: 20px; border-radius: 5px;'>
                        <p style='margin: 5px 0;'><strong>Pristatymo būdas:</strong> $deliveryMethod</p>
                        <p style='margin: 5px 0;'><strong>Adresas:</strong> " . htmlspecialchars($fullAddress) . "</p>
                        <p style='margin: 5px 0;'><strong>Telefonas:</strong> " . htmlspecialchars($deliveryDetails['phone'] ?? '-') . "</p>
                    </div>
                </div>

                <div style='background-color: #333; color: #aaa; padding: 20px; text-align: center; font-size: 12px;'>
                    <p>Cukrinukas © " . date('Y') . ". Visos teisės saugomos.</p>
                    <p>Kilus klausimams, kreipkitės: <a href='mailto:labas@cukrinukas.lt' style='color: #e91e63;'>labas@cukrinukas.lt</a></p>
                </div>
            </div>
        </body>
        </html>
        ";

        // 5. Siunčiame laiškus
        $subject = "Jūsų užsakymas #$orderId gautas";

        // Klientui
        $clientSent = false;
        if (!empty($order['customer_email'])) {
            $clientSent = sendEmail($order['customer_email'], $subject, $emailContent);
        }

        // Adminui
        $adminSubject = "[NAUJAS UŽSAKYMAS] #$orderId - " . number_format($order['total'], 2) . " €";
        // Pakeista: siunčiame pačiam sau, todėl 'To' ir 'From' bus tas pats - tai dažnai sumažina SPAM tikimybę
        $adminEmail = requireEnv('SMTP_USER'); 
        $adminSent = sendEmail($adminEmail, $adminSubject, $emailContent);

        // Loginame rezultatą
        logMailer("Bandymas siųsti laišką Order #$orderId. Klientui ({$order['customer_email']}): " . ($clientSent ? 'OK' : 'FAIL') . ", Adminui ($adminEmail): " . ($adminSent ? 'OK' : 'FAIL'));

    } catch (Exception $e) {
        logMailer("CRITICAL MAILER ERROR: " . $e->getMessage());
    }
}

// Papildoma logging funkcija
function logMailer($msg) {
    $logFile = __DIR__ . '/mailer_log.txt';
    $entry = date('Y-m-d H:i:s') . " - " . $msg . "\n";
    file_put_contents($logFile, $entry, FILE_APPEND);
}
?>
