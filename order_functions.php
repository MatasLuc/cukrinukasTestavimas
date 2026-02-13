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

        // Stiliai
        $styleText = "color: #475467; font-size: 14px; padding: 12px; border-bottom: 1px solid #e2e8f0;";
        $styleHeader = "color: #475467; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; padding: 12px; border-bottom: 1px solid #e2e8f0; background-color: #f8fafc; font-weight: 600;";
        
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
                <td style='$styleText'>$title</td>
                <td style='$styleText text-align: center;'>$qty</td>
                <td style='$styleText text-align: right;'>$price €</td>
                <td style='$styleText text-align: right; color: #0f172a;'><strong>$totalItem €</strong></td>
            </tr>";
        }

        $shippingPrice = $order['total'] - $itemsTotal;
        $shippingRow = '';
        if ($shippingPrice > 0.001) {
            $shippingRow = "
            <tr>
                <td colspan='3' style='padding: 12px; text-align: right; color: #64748b; font-size: 14px;'>Pristatymas:</td>
                <td style='padding: 12px; text-align: right; color: #0f172a; font-weight: 600; font-size: 14px;'>" . number_format($shippingPrice, 2) . " €</td>
            </tr>";
        }

        $emailContent = "
        <html>
        <head>
            <link href='https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap' rel='stylesheet'>
        </head>
        <body style='font-family: \"Inter\", Helvetica, Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; -webkit-font-smoothing: antialiased;'>
            <div style='max-width: 600px; margin: 40px auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);'>
                
                <div style='padding: 32px; text-align: center; border-bottom: 1px solid #f1f5f9;'>
                    <h1 style='margin: 0; color: #0f172a; font-size: 24px; font-weight: 700; letter-spacing: -0.5px;'>Užsakymas #$orderId patvirtintas! 🎉</h1>
                </div>

                <div style='padding: 32px;'>
                    <p style='color: #475467; font-size: 16px; line-height: 1.6; margin-bottom: 24px;'>Sveiki, <strong>" . htmlspecialchars($order['customer_name']) . "</strong>,</p>
                    <p style='color: #475467; font-size: 16px; line-height: 1.6; margin-bottom: 32px;'>Ačiū, kad perkate „Cukrinukas“. Gavome jūsų apmokėjimą ir pradedame ruošti užsakymą.</p>
                    
                    <table style='width: 100%; border-collapse: collapse; margin-bottom: 32px;'>
                        <thead>
                            <tr>
                                <th style='$styleHeader text-align: left;'>Prekė</th>
                                <th style='$styleHeader text-align: center;'>Kiekis</th>
                                <th style='$styleHeader text-align: right;'>Vnt.</th>
                                <th style='$styleHeader text-align: right;'>Suma</th>
                            </tr>
                        </thead>
                        <tbody>$itemsRows</tbody>
                        <tfoot>
                            $shippingRow
                            <tr>
                                <td colspan='3' style='padding: 16px 12px; text-align: right; font-size: 16px; color: #0f172a;'><strong>VISO:</strong></td>
                                <td style='padding: 16px 12px; text-align: right; font-size: 20px; color: #2563eb; font-weight: 700;'><strong>" . number_format($order['total'], 2) . " €</strong></td>
                            </tr>
                        </tfoot>
                    </table>

                    <div style='background-color: #f8fafc; padding: 24px; border-radius: 12px; border: 1px solid #e2e8f0;'>
                        <h3 style='margin: 0 0 16px 0; color: #0f172a; font-size: 16px; font-weight: 600;'>Pristatymo informacija</h3>
                        <p style='margin: 8px 0; color: #475467; font-size: 14px;'><strong>Būdas:</strong> $deliveryMethod</p>
                        <p style='margin: 8px 0; color: #475467; font-size: 14px;'><strong>Adresas:</strong> " . htmlspecialchars($fullAddress) . "</p>
                        <p style='margin: 8px 0; color: #475467; font-size: 14px;'><strong>Telefonas:</strong> " . htmlspecialchars($deliveryDetails['phone'] ?? '-') . "</p>
                    </div>
                </div>

                <div style='background-color: #f8fafc; padding: 24px; text-align: center; border-top: 1px solid #e2e8f0;'>
                    <p style='margin: 0; color: #94a3b8; font-size: 12px;'>Cukrinukas © " . date('Y') . ". Visos teisės saugomos.</p>
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
            // Dizainas kaip admin/emails.php "styleBox" ir "styleCode"
            $trackingHtml = "
            <div style='background-color: #f8fafc; padding: 24px; border-radius: 16px; border: 1px solid #e2e8f0; margin: 32px 0; text-align: center;'>
                <p style='margin: 0 0 12px 0; font-size: 14px; color: #64748b;'>Jūsų siuntos sekimo numeris:</p>
                <span style='background-color: #ffffff; border: 2px dashed #2563eb; color: #2563eb; font-size: 20px; font-weight: 700; padding: 12px 24px; display: inline-block; border-radius: 8px; letter-spacing: 1px;'>$trackingNumber</span>
            </div>";
        }

        $emailContent = "
        <html>
        <head>
            <link href='https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap' rel='stylesheet'>
        </head>
        <body style='font-family: \"Inter\", Helvetica, Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; -webkit-font-smoothing: antialiased;'>
            <div style='max-width: 600px; margin: 40px auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);'>
                
                <div style='padding: 32px; text-align: center; border-bottom: 1px solid #f1f5f9;'>
                    <h1 style='margin: 0; color: #0f172a; font-size: 24px; font-weight: 700; letter-spacing: -0.5px;'>Jūsų užsakymas #$orderId išsiųstas! 📦</h1>
                </div>

                <div style='padding: 32px;'>
                    <p style='color: #475467; font-size: 16px; line-height: 1.6; margin-bottom: 16px;'>Sveiki, <strong>" . htmlspecialchars($order['customer_name']) . "</strong>,</p>
                    <p style='color: #475467; font-size: 16px; line-height: 1.6; margin-bottom: 16px;'>Džiugios naujienos! Jūsų užsakymas buvo supakuotas ir perduotas kurjerių tarnybai.</p>
                    
                    $trackingHtml

                    <p style='color: #475467; font-size: 16px; line-height: 1.6; margin-bottom: 8px;'>Prekės jus turėtų pasiekti artimiausiu metu.</p>
                    <p style='color: #475467; font-size: 16px; line-height: 1.6;'>Jei turite klausimų, galite atsakyti į šį laišką.</p>
                    
                    <div style='text-align: center; margin-top: 32px;'>
                        <a href='https://cukrinukas.lt' style='background-color: #2563eb; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 12px; display: inline-block; font-weight: 600; font-size: 15px; box-shadow: 0 2px 4px rgba(37, 99, 235, 0.2);'>Grįžti į parduotuvę</a>
                    </div>
                </div>

                <div style='background-color: #f8fafc; padding: 24px; text-align: center; border-top: 1px solid #e2e8f0;'>
                    <p style='margin: 0; color: #94a3b8; font-size: 12px;'>Cukrinukas © " . date('Y') . ". Ačiū, kad perkate!</p>
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
