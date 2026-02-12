<?php
// order_functions.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

/**
 * Pagrindinė funkcija užsakymo užbaigimui (apmokėjimo patvirtinimas).
 */
function completeOrder($pdo, $orderId) {
    try {
        // 1. Patikriname dabartinį statusą
        $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        // Jei užsakymas nerastas arba jau apmokėtas, nieko nedarome
        if (!$order) {
            logMailer("completeOrder: Užsakymas #$orderId nerastas.");
            return false;
        }
        if ($order['status'] === 'Apmokėta') {
            return false;
        }

        // 2. Pradedame transakciją
        $pdo->beginTransaction();

        // Atnaujiname statusą
        $stmtUpdate = $pdo->prepare("UPDATE orders SET status = 'Apmokėta', updated_at = NOW() WHERE id = ?");
        $stmtUpdate->execute([$orderId]);

        // Sumažiname likučius
        $stmtItems = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
        $stmtItems->execute([$orderId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        if ($items) {
            $stmtStock = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
            foreach ($items as $item) {
                $stmtStock->execute([$item['quantity'], $item['product_id']]);
            }
        }

        $pdo->commit();
        
        // 3. Siunčiame patvirtinimo laišką
        sendOrderConfirmationEmail($orderId, $pdo);
        
        logMailer("completeOrder: Užsakymas #$orderId sėkmingai užbaigtas ir laiškai išsiųsti.");
        return true;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        logMailer("completeOrder CRITICAL ERROR: " . $e->getMessage());
        return false;
    }
}

/**
 * Siunčia laišką apie gautą užsakymą (adminui ir klientui).
 */
function sendOrderConfirmationEmail($orderId, $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) return;

        $stmtItems = $pdo->prepare("
            SELECT oi.*, p.title, p.image_url 
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?
        ");
        $stmtItems->execute([$orderId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $deliveryMethod = $order['delivery_method'] == 'locker' ? 'Paštomatas' : 
                         ($order['delivery_method'] == 'courier' ? 'Kurjeris' : 'Atsiėmimas');
        
        $deliveryDetails = json_decode($order['delivery_details'], true);
        $lockerAddress = isset($deliveryDetails['locker_address']) ? $deliveryDetails['locker_address'] : '';
        $fullAddress = $lockerAddress ? "Paštomatas: $lockerAddress" : $order['customer_address'];

        $itemsRows = '';
        $itemsTotal = 0;
        foreach ($items as $item) {
            $title = htmlspecialchars($item['title'] ?? 'Prekė');
            $qty = $item['quantity'];
            $price = number_format($item['price'], 2);
            $totalItem = number_format($item['price'] * $qty, 2);
            $itemsTotal += $item['price'] * $item['quantity'];
            
            $itemsRows .= "
            <tr>
                <td style='padding: 10px; border-bottom: 1px solid #eee;'>$title</td>
                <td style='padding: 10px; border-bottom: 1px solid #eee; text-align: center;'>$qty</td>
                <td style='padding: 10px; border-bottom: 1px solid #eee; text-align: right;'>$price €</td>
                <td style='padding: 10px; border-bottom: 1px solid #eee; text-align: right;'><strong>$totalItem €</strong></td>
            </tr>";
        }

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
                    
                    <table style='width: 100%; border-collapse: collapse; margin-top: 10px;'>
                        <thead>
                            <tr style='background-color: #f9f9f9;'>
                                <th style='padding: 10px; text-align: left;'>Prekė</th>
                                <th style='padding: 10px; text-align: center;'>Kiekis</th>
                                <th style='padding: 10px; text-align: right;'>Vnt.</th>
                                <th style='padding: 10px; text-align: right;'>Suma</th>
                            </tr>
                        </thead>
                        <tbody>$itemsRows</tbody>
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
                </div>
            </div>
        </body>
        </html>";

        // Siunčiame
        if (!empty($order['customer_email'])) {
            sendEmail($order['customer_email'], "Jūsų užsakymas #$orderId gautas", $emailContent);
        }
        
        $adminEmail = requireEnv('SMTP_USER'); 
        sendEmail($adminEmail, "[NAUJAS UŽSAKYMAS] #$orderId", $emailContent);

    } catch (Exception $e) {
        logMailer("CRITICAL MAILER ERROR: " . $e->getMessage());
    }
}

/**
 * Siunčia laišką, kai užsakymo statusas pakeičiamas į "išsiųsta".
 * Įtraukia sekimo numerį.
 */
function sendShippingConfirmationEmail($orderId, $trackingNumber, $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order || empty($order['customer_email'])) return;

        $trackingHtml = '';
        if (!empty($trackingNumber)) {
            $trackingHtml = "
            <div style='background-color: #ecfdf5; border: 1px solid #d1fae5; color: #065f46; padding: 15px; margin: 20px 0; border-radius: 6px; text-align: center;'>
                <p style='margin: 0; font-size: 14px;'>Jūsų siuntos sekimo numeris:</p>
                <p style='margin: 5px 0 0 0; font-size: 20px; font-weight: bold; letter-spacing: 1px;'>$trackingNumber</p>
            </div>";
        }

        $emailContent = "
        <html>
        <body style='font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0;'>
            <div style='max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.1);'>
                
                <div style='background-color: #4caf50; padding: 20px; text-align: center; color: white;'>
                    <h1 style='margin: 0; font-size: 24px;'>Jūsų užsakymas #$orderId išsiųstas!</h1>
                </div>

                <div style='padding: 30px;'>
                    <p>Sveiki, <strong>" . htmlspecialchars($order['customer_name']) . "</strong>,</p>
                    <p>Džiugios naujienos! Jūsų užsakymas buvo supakuotas ir perduotas kurjerių tarnybai.</p>
                    
                    $trackingHtml

                    <p>Prekės jus turėtų pasiekti artimiausiu metu.</p>
                    <p>Jei turite klausimų, galite atsakyti į šį laišką.</p>
                </div>

                <div style='background-color: #333; color: #aaa; padding: 20px; text-align: center; font-size: 12px;'>
                    <p>Cukrinukas © " . date('Y') . ". Ačiū, kad perkate!</p>
                </div>
            </div>
        </body>
        </html>
        ";

        sendEmail($order['customer_email'], "Jūsų užsakymas #$orderId išsiųstas!", $emailContent);
        logMailer("Išsiųstas 'Shipped' laiškas užsakymui #$orderId (Tracking: $trackingNumber)");

    } catch (Exception $e) {
        logMailer("Shipping Email Error: " . $e->getMessage());
    }
}

function logMailer($msg) {
    $logFile = __DIR__ . '/mailer_log.txt';
    $entry = date('Y-m-d H:i:s') . " - " . $msg . "\n";
    file_put_contents($logFile, $entry, FILE_APPEND);
}
?>
