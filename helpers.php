<?php

function imageMimeMap(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
}

function uploadImageWithValidation(array $file, string $prefix, array &$errors, ?string $missingMessage = null, bool $collectErrors = true): ?string
{
    $hasFile = !empty($file['name']);
    if (!$hasFile) {
        if ($missingMessage !== null) {
            $errors[] = $missingMessage;
        }
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        if ($collectErrors) {
            $errors[] = 'Nepavyko įkelti nuotraukos.';
        }
        return null;
    }

    $uploaded = saveUploadedFile($file, imageMimeMap(), $prefix);
    if ($uploaded !== null) {
        return $uploaded;
    }

    if ($collectErrors) {
        $errors[] = 'Leidžiami formatai: jpg, jpeg, png, webp, gif.';
    }

    return null;
}

/**
 * Paverčia tekstą į URL draugišką formatą (slug).
 */
function slugify(string $text): string
{
    $map = [
        'ą' => 'a', 'č' => 'c', 'ę' => 'e', 'ė' => 'e', 'į' => 'i', 'š' => 's', 'ų' => 'u', 'ū' => 'u', 'ž' => 'z',
        'Ą' => 'A', 'Č' => 'C', 'Ę' => 'E', 'Ė' => 'E', 'Į' => 'I', 'Š' => 'S', 'Ų' => 'U', 'Ū' => 'U', 'Ž' => 'Z'
    ];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-zA-Z0-9\s-]/', '', $text);
    $text = preg_replace('/\s+/', '-', $text);
    $text = strtolower($text);
    $text = trim($text, '-');
    return $text ?: 'item';
}

/**
 * Patvirtina užsakymą: atnaujina statusą, nurašo likučius (ir variacijų), išsiunčia laiškus.
 */
function approveOrder($pdo, $orderId)
{
    try {
        // 1. Gauname užsakymo informaciją
        $stmt = $pdo->prepare("SELECT status, customer_email, customer_name, total FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return false;
        }

        // Apsauga: Jei jau apmokėta, nieko nedarome
        $paidStatuses = ['apmokėta', 'įvykdyta', 'completed', 'paid'];
        if (in_array(strtolower($order['status']), $paidStatuses)) {
            return true; 
        }

        // 2. Atnaujiname statusą į 'apmokėta'
        $pdo->prepare("UPDATE orders SET status = 'apmokėta' WHERE id = ?")->execute([$orderId]);

        // 3. Likučių atnaujinimas
        // Paimame 'variation_info' vietoj 'variation_id', nes checkout.php saugo tekstą
        $itemsStmt = $pdo->prepare("SELECT product_id, quantity, variation_info FROM order_items WHERE order_id = ?");
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $updateProductSql = "UPDATE products SET quantity = quantity - ? WHERE id = ? AND quantity >= ?";
        
        // Užklausa variacijos suradimui pagal grupę ir pavadinimą
        $findVarSql = "SELECT id FROM product_variations WHERE product_id = ? AND group_name = ? AND name = ? LIMIT 1";
        // Užklausa variacijos suradimui, jei grupė nenurodyta
        $findVarSqlNoGroup = "SELECT id FROM product_variations WHERE product_id = ? AND (group_name = '' OR group_name IS NULL) AND name = ? LIMIT 1";
        
        $updateVarSql = "UPDATE product_variations SET quantity = quantity - ? WHERE id = ? AND track_stock = 1 AND quantity >= ?";

        foreach ($items as $item) {
            $qty = (int)$item['quantity'];
            $pid = (int)$item['product_id'];
            $varInfo = $item['variation_info'] ?? '';

            // Sumažiname pagrindinės prekės likutį
            $pdo->prepare($updateProductSql)->execute([$qty, $pid, $qty]);

            // Bandome surasti ir sumažinti variacijų likučius
            if ($varInfo) {
                // Skaldome tekstą: "Spalva: Raudona, Dydis: XL" -> ["Spalva: Raudona", "Dydis: XL"]
                $parts = explode(',', $varInfo);
                foreach ($parts as $part) {
                    $part = trim($part);
                    if (!$part) continue;

                    $varId = null;
                    
                    // Tikriname ar yra dvitaškis (pvz. "Spalva: Raudona")
                    $colonPos = strpos($part, ':');
                    
                    if ($colonPos !== false) {
                        $group = trim(substr($part, 0, $colonPos));
                        $name = trim(substr($part, $colonPos + 1));
                        
                        $fStmt = $pdo->prepare($findVarSql);
                        $fStmt->execute([$pid, $group, $name]);
                        $varId = $fStmt->fetchColumn();
                    } else {
                        // Jei nėra grupės pavadinimo, ieškome tik pagal reikšmę
                        $name = $part;
                        $fStmt = $pdo->prepare($findVarSqlNoGroup);
                        $fStmt->execute([$pid, $name]);
                        $varId = $fStmt->fetchColumn();
                    }

                    // Jei radome variaciją, nurašome kiekį
                    if ($varId) {
                        $pdo->prepare($updateVarSql)->execute([$qty, $varId, $qty]);
                    }
                }
            }
        }

        // 4. Laiškų siuntimas
        if (!function_exists('sendEmail')) {
            require_once __DIR__ . '/mailer.php';
        }

        // Pirkėjui
        $content = "<p>Sveiki, <strong>{$order['customer_name']}</strong>,</p>
                    <p>Jūsų užsakymas <strong>#{$orderId}</strong> sėkmingai apmokėtas ir patvirtintas.</p>
                    <p>Bendra suma: <strong>{$order['total']} EUR</strong></p>
                    <p>Informuosime jus, kai siunta bus išsiųsta.</p>";
        
        $html = getEmailTemplate('Užsakymas patvirtintas! ✅', $content, 'https://cukrinukas.lt/orders.php', 'Mano užsakymai');
        
        try {
            sendEmail($order['customer_email'], "Užsakymo patvirtinimas #{$orderId}", $html);
        } catch (Throwable $e) {
            // Ignoruojame laiško klaidą, kad nesugadintume užsakymo proceso
            if (function_exists('logError')) logError('Email send failed', $e);
        }

        // Adminui
        $adminContent = "<p>Gautas naujas užsakymas #{$orderId}.</p><p>Klientas: {$order['customer_name']}</p><p>Suma: {$order['total']} EUR</p>";
        $adminHtml = getEmailTemplate('Naujas užsakymas 💰', $adminContent);
        $adminEmail = getenv('ADMIN_EMAIL') ?: 'labas@cukrinukas.lt';
        
        try {
            sendEmail($adminEmail, "Naujas užsakymas #{$orderId}", $adminHtml);
        } catch (Throwable $e) {
            if (function_exists('logError')) logError('Admin email send failed', $e);
        }

        return true;

    } catch (Exception $e) {
        // Jei įvyksta klaida, ją registruojame, bet neleidžiame puslapiui nulūžti (500 error)
        if (function_exists('logError')) {
            logError('Order approval failed for order ' . $orderId, $e);
        } else {
            error_log('Order approval failed: ' . $e->getMessage());
        }
        return false;
    }
}
?>
