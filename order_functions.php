<?php
// order_functions.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

/**
 * Sukuria bendruomenės užsakymus su statusu "laukiama". 
 * Kviečiama prieš nukreipiant į Stripe.
 */
function createPendingCommunityOrders($pdo, $orderId, $buyerId, $orderData = null) {
    try {
        $pdo->exec("ALTER TABLE community_orders MODIFY status VARCHAR(50) NOT NULL DEFAULT 'laukiama'");
    } catch (Exception $e) {
        // Ignoruojame
    }

    if (empty($_SESSION['cart_community'])) return;

    $stmtComm = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    $stmtComm->execute(['community_commission']);
    $commissionRate = $stmtComm->fetchColumn() ?: 0;

    $cIds = array_keys($_SESSION['cart_community']);
    if (empty($cIds)) return;

    $tempIntent = 'ORDER_' . $orderId;
    $stmtCheck = $pdo->prepare("SELECT id FROM community_orders WHERE stripe_payment_intent_id = ? LIMIT 1");
    $stmtCheck->execute([$tempIntent]);
    if ($stmtCheck->fetchColumn()) return; 

    // Ištraukiame pristatymo duomenis iš pagrindinio užsakymo
    $customerName = $orderData['customer_name'] ?? null;
    $customerPhone = $orderData['customer_phone'] ?? null;
    $customerAddress = $orderData['customer_address'] ?? null;
    $deliveryMethod = $orderData['delivery_method'] ?? null;
    $deliveryDetails = $orderData['delivery_details'] ?? null;

    $placeholders = implode(',', array_fill(0, count($cIds), '?'));
    $stmtListings = $pdo->prepare("SELECT id, user_id as seller_id, title, price FROM community_listings WHERE id IN ($placeholders)");
    $stmtListings->execute($cIds);
    $listings = $stmtListings->fetchAll(PDO::FETCH_ASSOC);

    foreach ($listings as $listing) {
        $sellerId = $listing['seller_id'];
        $itemId = $listing['id'];
        $qty = (int)$_SESSION['cart_community'][$itemId];
        $unitPrice = $listing['price'];
        $itemTotalPrice = $unitPrice * $qty;
        
        $shippingPrice = 0; 
        $totalAmount = $itemTotalPrice + $shippingPrice;
        
        $adminCommissionAmount = ($itemTotalPrice * $commissionRate) / 100;
        $sellerPayoutAmount = $totalAmount - $adminCommissionAmount;

        $stmtIns = $pdo->prepare("
            INSERT INTO community_orders 
            (buyer_id, seller_id, item_id, item_price, shipping_price, 
             total_amount, admin_commission_rate, admin_commission_amount, seller_payout_amount, 
             stripe_payment_intent_id, status, payout_status, 
             customer_name, customer_phone, customer_address, delivery_method, delivery_details, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'laukiama', 'hold', ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmtIns->execute([
            $buyerId, 
            $sellerId,
            $itemId,
            $itemTotalPrice,
            $shippingPrice,
            $totalAmount,
            $commissionRate,
            $adminCommissionAmount,
            $sellerPayoutAmount,
            $tempIntent,
            $customerName,
            $customerPhone,
            $customerAddress,
            $deliveryMethod,
            $deliveryDetails
        ]);
    }
}

/**
 * Pagrindinė funkcija užsakymo užbaigimui (apmokėjimo patvirtinimas).
 */
function completeOrder($pdo, $orderId, $sendEmail = true, $realPaymentIntentId = null) {
    try {
        try {
            $pdo->exec("ALTER TABLE orders MODIFY status VARCHAR(50)");
            $pdo->exec("ALTER TABLE community_listings MODIFY status VARCHAR(50)");
        } catch (Exception $e) {
            // Ignoruojame
        }

        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $errorMsg = "completeOrder: Užsakymas #$orderId nerastas.";
            logMailer($errorMsg);
            throw new Exception($errorMsg); 
        }
        
        if (mb_strtolower($order['status']) === 'apmokėta') {
            return false;
        }

        $pdo->beginTransaction();

        $stmtUpdate = $pdo->prepare("UPDATE orders SET status = 'apmokėta' WHERE id = ?");
        $stmtUpdate->execute([$orderId]);

        $stmtItems = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
        $stmtItems->execute([$orderId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        if ($items) {
            $stmtStock = $pdo->prepare("UPDATE products SET quantity = GREATEST(0, quantity - ?) WHERE id = ?");
            foreach ($items as $item) {
                $stmtStock->execute([$item['quantity'], $item['product_id']]);
            }
        }

        $tempIntent = 'ORDER_' . $orderId;
        $stmtCommOrders = $pdo->prepare("SELECT * FROM community_orders WHERE stripe_payment_intent_id = ? AND status = 'laukiama'");
        $stmtCommOrders->execute([$tempIntent]);
        $communityOrders = $stmtCommOrders->fetchAll(PDO::FETCH_ASSOC);

        $itemsBySellerForEmail = [];

        if ($communityOrders) {
            $updateComm = $pdo->prepare("UPDATE community_orders SET status = 'apmokėta', stripe_payment_intent_id = ? WHERE id = ?");
            $updateListing = $pdo->prepare("UPDATE community_listings SET status = 'sold' WHERE id = ?");
            
            foreach ($communityOrders as $co) {
                $actualIntentId = $realPaymentIntentId ?: $tempIntent;
                $updateComm->execute([$actualIntentId, $co['id']]);
                $updateListing->execute([$co['item_id']]);

                $stmtTitle = $pdo->prepare("SELECT title FROM community_listings WHERE id = ?");
                $stmtTitle->execute([$co['item_id']]);
                $title = $stmtTitle->fetchColumn() ?: 'Prekė';

                $sellerId = $co['seller_id'];
                if (!isset($itemsBySellerForEmail[$sellerId])) {
                    $itemsBySellerForEmail[$sellerId] = [
                        'total_paid' => 0, 
                        'items' => [],
                        'customer_name' => $co['customer_name'],
                        'customer_phone' => $co['customer_phone'],
                        'customer_address' => $co['customer_address'],
                        'delivery_method' => $co['delivery_method'],
                        'delivery_details' => $co['delivery_details']
                    ];
                }
                $itemsBySellerForEmail[$sellerId]['total_paid'] += $co['total_amount'];
                
                $qty = ($co['total_amount'] > 0 && $co['item_price'] > 0) ? round($co['total_amount'] / $co['item_price']) : 1;
                $itemsBySellerForEmail[$sellerId]['items'][] = ['title' => $title, 'qty' => $qty];
            }
        }

        $pdo->commit();

        // ------------------------------------------------------------------
        // Laiškų siuntimas 
        // ------------------------------------------------------------------
        
        if ($communityOrders) {
            foreach ($itemsBySellerForEmail as $sellerId => $data) {
                $stmtGetSeller = $pdo->prepare("SELECT email, name FROM users WHERE id = ?");
                $stmtGetSeller->execute([$sellerId]);
                $seller = $stmtGetSeller->fetch();

                if ($seller) {
                    $sSubject = "Naujas užsakymas! (Cukrinukas Turgelis)";
                    
                    $sellerItemsRows = '';
                    foreach ($data['items'] as $item) {
                        $itemTitle = htmlspecialchars($item['title']);
                        $itemQty = $item['qty'];
                        $sellerItemsRows .= "
                        <tr>
                            <td style='color: #475467; font-size: 14px; padding: 12px; border-bottom: 1px solid #e2e8f0;'>$itemTitle</td>
                            <td style='color: #475467; font-size: 14px; padding: 12px; border-bottom: 1px solid #e2e8f0; text-align: center;'>$itemQty</td>
                        </tr>";
                    }

                    $delDetails = json_decode($data['delivery_details'] ?? '{}', true) ?: [];
                    $provKey = strtolower($delDetails['locker_provider'] ?? '');
                    $provMap = ['lpexpress' => 'LP EXPRESS', 'omniva' => 'OMNIVA'];
                    $provName = $provMap[$provKey] ?? strtoupper($provKey);

                    $deliveryMethodName = $data['delivery_method'] === 'locker' ? 'Paštomatas' . ($provName ? " ($provName)" : '') : 
                                         ($data['delivery_method'] === 'courier' ? 'Kurjeris' : 'Atsiėmimas');
                    
                    $lockerAddress = $delDetails['locker_name'] ?? ($delDetails['locker_address'] ?? '');
                    $fullAddress = $lockerAddress ? "Paštomatas: $lockerAddress" : $data['customer_address'];

                    $deliveryHtml = "
                    <div style='background-color: #f8fafc; padding: 24px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 32px;'>
                        <h3 style='margin: 0 0 16px 0; color: #0f172a; font-size: 16px; font-weight: 600;'>Pristatymo informacija</h3>
                        <p style='margin: 8px 0; color: #475467; font-size: 14px;'><strong>Pirkėjas:</strong> " . htmlspecialchars($data['customer_name'] ?? '-') . "</p>
                        <p style='margin: 8px 0; color: #475467; font-size: 14px;'><strong>Telefonas:</strong> " . htmlspecialchars($data['customer_phone'] ?? '-') . "</p>
                        <p style='margin: 8px 0; color: #475467; font-size: 14px;'><strong>Būdas:</strong> $deliveryMethodName</p>
                        <p style='margin: 8px 0; color: #475467; font-size: 14px;'><strong>Adresas:</strong> " . htmlspecialchars($fullAddress ?? '-') . "</p>
                    </div>";

                    $sBody = "
                    <html>
                    <head>
                        <link href='https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap' rel='stylesheet'>
                    </head>
                    <body style='font-family: \"Inter\", Helvetica, Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; -webkit-font-smoothing: antialiased;'>
                        <div style='max-width: 600px; margin: 40px auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);'>
                            
                            <div style='padding: 32px; text-align: center; border-bottom: 1px solid #f1f5f9;'>
                                <h1 style='margin: 0; color: #0f172a; font-size: 24px; font-weight: 700; letter-spacing: -0.5px;'>Naujas užsakymas! 🎉</h1>
                            </div>

                            <div style='padding: 32px;'>
                                <p style='color: #475467; font-size: 16px; line-height: 1.6; margin-bottom: 24px;'>Sveiki, <strong>" . htmlspecialchars($seller['name']) . "</strong>,</p>
                                <p style='color: #475467; font-size: 16px; line-height: 1.6; margin-bottom: 32px;'>Turite naują užsakymą Cukrinukas turgelyje. Prašome paruošti šias prekes išsiuntimui:</p>
                                
                                <table style='width: 100%; border-collapse: collapse; margin-bottom: 32px;'>
                                    <thead>
                                        <tr>
                                            <th style='color: #475467; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; padding: 12px; border-bottom: 1px solid #e2e8f0; background-color: #f8fafc; font-weight: 600; text-align: left;'>Prekė</th>
                                            <th style='color: #475467; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; padding: 12px; border-bottom: 1px solid #e2e8f0; background-color: #f8fafc; font-weight: 600; text-align: center;'>Kiekis</th>
                                        </tr>
                                    </thead>
                                    <tbody>$sellerItemsRows</tbody>
                                    <tfoot>
                                        <tr>
                                            <td style='padding: 16px 12px; text-align: right; font-size: 16px; color: #0f172a;'><strong>Gauta suma:</strong></td>
                                            <td style='padding: 16px 12px; text-align: center; font-size: 20px; color: #2563eb; font-weight: 700;'><strong>" . number_format($data['total_paid'], 2) . " €</strong></td>
                                        </tr>
                                    </tfoot>
                                </table>

                                $deliveryHtml

                            </div>

                            <div style='background-color: #f8fafc; padding: 24px; text-align: center; border-top: 1px solid #e2e8f0;'>
                                <p style='margin: 0; color: #94a3b8; font-size: 12px;'>Cukrinukas © " . date('Y') . ". Visos teisės saugomos.</p>
                            </div>
                        </div>
                    </body>
                    </html>";
                    
                    try {
                        sendEmail($seller['email'], $sSubject, $sBody);
                    } catch (Exception $eMail) {
                        logMailer("Nepavyko išsiųsti laiško pardavėjui {$seller['email']}: " . $eMail->getMessage());
                    }
                }
            }
        }
        
        if ($sendEmail) {
            try {
                sendOrderConfirmationEmail($orderId, $pdo, $communityOrders);
            } catch (Exception $eMail) {
                logMailer("Nepavyko išsiųsti laiško pirkėjui: " . $eMail->getMessage());
            }
        }
        
        logMailer("completeOrder: Užsakymas #$orderId sėkmingai užbaigtas.");
        return true;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        logMailer("completeOrder CRITICAL ERROR: " . $e->getMessage());
        throw new Exception("Duomenų bazės klaida patvirtinant užsakymą: " . $e->getMessage()); 
    }
}

/**
 * Siunčia laišką apie gautą užsakymą (adminui ir klientui).
 */
function sendOrderConfirmationEmail($orderId, $pdo, $communityOrders = []) {
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

        $deliveryDetails = json_decode($order['delivery_details'], true) ?: [];
        $provKey = strtolower($deliveryDetails['locker_provider'] ?? '');
        $provMap = ['lpexpress' => 'LP EXPRESS', 'omniva' => 'OMNIVA'];
        $provName = $provMap[$provKey] ?? strtoupper($provKey);

        $deliveryMethod = $order['delivery_method'] == 'locker' ? 'Paštomatas' . ($provName ? " ($provName)" : '') : 
                         ($order['delivery_method'] == 'courier' ? 'Kurjeris' : 'Atsiėmimas');
        
        $lockerAddress = isset($deliveryDetails['locker_name']) ? $deliveryDetails['locker_name'] : (isset($deliveryDetails['locker_address']) ? $deliveryDetails['locker_address'] : '');
        $fullAddress = $lockerAddress ? "Paštomatas: $lockerAddress" : $order['customer_address'];

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

        if (!empty($communityOrders)) {
            foreach ($communityOrders as $co) {
                $stmtTitle = $pdo->prepare("SELECT title FROM community_listings WHERE id = ?");
                $stmtTitle->execute([$co['item_id']]);
                $title = htmlspecialchars($stmtTitle->fetchColumn() ?: 'Prekė');
                
                $qty = ($co['total_amount'] > 0 && $co['item_price'] > 0) ? round($co['total_amount'] / $co['item_price']) : 1;
                $price = number_format($co['item_price'], 2);
                $totalItem = number_format($co['total_amount'], 2);
                $itemsTotal += $co['total_amount'];

                $itemsRows .= "
                <tr>
                    <td style='$styleText'>$title <span style='font-size:11px; color:#64748b;'><br>(Iš Turgelio)</span></td>
                    <td style='$styleText text-align: center;'>$qty</td>
                    <td style='$styleText text-align: right;'>$price €</td>
                    <td style='$styleText text-align: right; color: #0f172a;'><strong>$totalItem €</strong></td>
                </tr>";
            }
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

        // ==========================================
        // 1. LAIŠKAS PIRKĖJUI
        // ==========================================
        $trackingHtmlBuyer = '';
        if (!empty($order['tracking_number'])) {
            $trackingHtmlBuyer = "
            <div style='margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2e8f0;'>
                <p style='margin: 0 0 8px 0; color: #64748b; font-size: 14px;'>Jūsų siuntos sekimo numeris:</p>
                <p style='margin: 0; color: #2563eb; font-size: 18px; font-weight: 700; letter-spacing: 0.5px;'>{$order['tracking_number']}</p>
            </div>";
        }

        $emailContentBuyer = "
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
                        <p style='margin: 8px 0; color: #475467; font-size: 14px;'><strong>Telefonas:</strong> " . htmlspecialchars($deliveryDetails['phone'] ?? ($order['customer_phone'] ?? '-')) . "</p>
                        $trackingHtmlBuyer
                    </div>
                </div>

                <div style='background-color: #f8fafc; padding: 24px; text-align: center; border-top: 1px solid #e2e8f0;'>
                    <p style='margin: 0; color: #94a3b8; font-size: 12px;'>Cukrinukas © " . date('Y') . ". Visos teisės saugomos.</p>
                </div>
            </div>
        </body>
        </html>";

        // ==========================================
        // 2. LAIŠKAS ADMINISTRATORIUI (PARDAVĖJUI)
        // ==========================================
        $trackingHtmlAdmin = '';
        if (!empty($order['tracking_number'])) {
            $trackingHtmlAdmin = "<p style='margin: 8px 0; color: #475467; font-size: 14px;'><strong>Sekimo numeris:</strong> {$order['tracking_number']}</p>";
        }

        $emailContentAdmin = "
        <html>
        <head>
            <link href='https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap' rel='stylesheet'>
        </head>
        <body style='font-family: \"Inter\", Helvetica, Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; -webkit-font-smoothing: antialiased;'>
            <div style='max-width: 600px; margin: 40px auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);'>
                
                <div style='padding: 32px; background-color: #1e293b; color: #ffffff; text-align: center;'>
                    <h1 style='margin: 0; font-size: 24px; font-weight: 700;'>Naujas užsakymas #$orderId</h1>
                </div>

                <div style='padding: 32px;'>
                    <div style='margin-bottom: 32px;'>
                        <h3 style='margin: 0 0 16px 0; color: #0f172a; font-size: 16px; font-weight: 600; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px;'>Pirkėjo informacija</h3>
                        <p style='margin: 8px 0; color: #475467; font-size: 14px;'><strong>Vardas:</strong> " . htmlspecialchars($order['customer_name']) . "</p>
                        <p style='margin: 8px 0; color: #475467; font-size: 14px;'><strong>El. paštas:</strong> <a href='mailto:" . htmlspecialchars($order['customer_email']) . "' style='color: #2563eb; text-decoration: none;'>" . htmlspecialchars($order['customer_email']) . "</a></p>
                        <p style='margin: 8px 0; color: #475467; font-size: 14px;'><strong>Telefonas:</strong> " . htmlspecialchars($order['customer_phone']) . "</p>
                    </div>

                    <div style='margin-bottom: 32px;'>
                        <h3 style='margin: 0 0 16px 0; color: #0f172a; font-size: 16px; font-weight: 600; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px;'>Užsakytos prekės</h3>
                        <table style='width: 100%; border-collapse: collapse;'>
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
                                    <td colspan='3' style='padding: 16px 12px; text-align: right; font-size: 16px; color: #0f172a;'><strong>VISO BENDRA SUMA:</strong></td>
                                    <td style='padding: 16px 12px; text-align: right; font-size: 18px; color: #2563eb; font-weight: 700;'><strong>" . number_format($order['total'], 2) . " €</strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div style='background-color: #f8fafc; padding: 24px; border-radius: 12px; border: 1px solid #e2e8f0;'>
                        <h3 style='margin: 0 0 16px 0; color: #0f172a; font-size: 16px; font-weight: 600;'>Pristatymo informacija</h3>
                        <p style='margin: 8px 0; color: #475467; font-size: 14px;'><strong>Būdas:</strong> $deliveryMethod</p>
                        <p style='margin: 8px 0; color: #475467; font-size: 14px;'><strong>Adresas:</strong> " . htmlspecialchars($fullAddress) . "</p>
                        $trackingHtmlAdmin
                    </div>
                </div>
            </div>
        </body>
        </html>";

        // ==========================================
        // LAIŠKŲ SIUNTIMAS
        // ==========================================
        if (!empty($order['customer_email'])) {
            sendEmail($order['customer_email'], "Jūsų užsakymas #$orderId gautas", $emailContentBuyer);
        }
        
        $adminEmail = requireEnv('SMTP_USER'); 
        if($adminEmail) {
            sendEmail($adminEmail, "[NAUJAS UŽSAKYMAS] #$orderId", $emailContentAdmin);
        }

    } catch (Exception $e) {
        logMailer("CRITICAL MAILER ERROR: " . $e->getMessage());
    }
}

/**
 * Siunčia laišką, kai užsakymo statusas pakeičiamas į "išsiųsta".
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