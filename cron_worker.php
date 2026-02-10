<?php
// cron_worker.php
// Nustatyti CRON JOB vykdymą kas 10-15 min.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

$pdo = getPdo();
$log = [];

// --- PAGALBINĖ FUNKCIJA KODŲ GENERAVIMUI ---
function generateUniqueDiscountCode(PDO $pdo, string $prefix): string {
    $exists = true;
    $code = '';
    while ($exists) {
        $random = strtoupper(substr(md5(uniqid()), 0, 5));
        $code = $prefix . '-' . $random;
        $stmt = $pdo->prepare("SELECT id FROM discount_codes WHERE code = ?");
        $stmt->execute([$code]);
        if (!$stmt->fetch()) {
            $exists = false;
        }
    }
    return $code;
}

// =================================================================
// 1. PREKĖS IŠSIUNTIMO PATVIRTINIMAS
// (Statusas = 'išsiųsta', Dar nesiųsta)
// =================================================================

$stmtShipped = $pdo->prepare("
    SELECT * FROM orders 
    WHERE status = 'išsiųsta' 
    AND email_shipped_sent = 0
    LIMIT 10
");
$stmtShipped->execute();
$ordersShipped = $stmtShipped->fetchAll();

foreach ($ordersShipped as $order) {
    // Bandome rasti sekimo informaciją delivery_details lauke
    $details = json_decode($order['delivery_details'] ?? '{}', true);
    $trackingHtml = '';
    
    // Jei adminas įrašė tracking kodą į delivery_details (reikia tai numatyti admin dalyje)
    // Arba jei tiesiog yra pristatymo būdas
    if (!empty($details['tracking_code'])) {
        $trackingHtml = "<p style='background: #f0fdf4; padding: 15px; border-radius: 8px; border: 1px solid #bbf7d0; color: #166534;'>
                            Jūsų siuntos sekimo numeris: <strong>{$details['tracking_code']}</strong>
                         </p>";
    } elseif (!empty($details['method']) && $details['method'] === 'locker') {
        $locTitle = $details['title'] ?? 'Paštomatas';
        $trackingHtml = "<p>Siunta keliauja į pasirinktą paštomatą: <strong>{$locTitle}</strong>.</p>";
    }

    $subject = "Jūsų užsakymas #{$order['id']} išsiųstas! 🚀";
    $content = "<p>Sveiki, {$order['customer_name']},</p>
                <p>Gerios žinios – jūsų užsakymas jau supakuotas ir perduotas kurjerių tarnybai.</p>
                {$trackingHtml}
                <p>Prekės jus pasieks artimiausiu metu (dažniausiai per 1-2 d.d.).</p>";
    
    $html = getEmailTemplate("Siunta jau kelyje", $content, "https://cukrinukas.lt/account.php", "Mano užsakymai");
    
    if (sendEmail($order['customer_email'], $subject, $html)) {
        $pdo->prepare("UPDATE orders SET email_shipped_sent = 1 WHERE id = ?")->execute([$order['id']]);
        $log[] = "[SHIPPED] Išsiųstas patvirtinimas užsakymui #{$order['id']}";
    }
}

// =================================================================
// 2. ATSILIEPIMO PRAŠYMAS
// (Statusas = 'įvykdyta', Praėjo 3 dienos nuo atnaujinimo)
// =================================================================

$stmtReview = $pdo->prepare("
    SELECT * FROM orders 
    WHERE status = 'įvykdyta' 
    AND updated_at < DATE_SUB(NOW(), INTERVAL 3 DAY)
    AND email_review_sent = 0
    LIMIT 5
");
$stmtReview->execute();
$ordersReview = $stmtReview->fetchAll();

foreach ($ordersReview as $order) {
    // Gauname prekes
    $stmtItems = $pdo->prepare("
        SELECT oi.*, p.title, p.image_url, p.id as product_id 
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
    ");
    $stmtItems->execute([$order['id']]);
    $items = $stmtItems->fetchAll();

    if (count($items) > 0) {
        $itemListHtml = '<table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top:20px;">';
        foreach ($items as $item) {
            $prodUrl = "https://cukrinukas.lt/product.php?id=" . $item['product_id'] . "#reviews"; // #reviews anchor jei yra
            $img = htmlspecialchars($item['image_url']);
            $title = htmlspecialchars($item['title']);
            
            $itemListHtml .= "
            <tr>
                <td style='padding: 10px; border-bottom: 1px solid #eee; width: 60px;'>
                    <img src='{$img}' width='50' style='border-radius:6px;' alt='Prekė'>
                </td>
                <td style='padding: 10px; border-bottom: 1px solid #eee;'>
                    <div style='font-weight:600; font-size:14px; color:#333;'>{$title}</div>
                </td>
                <td style='padding: 10px; border-bottom: 1px solid #eee; text-align:right;'>
                    <a href='{$prodUrl}' style='color:#2563eb; text-decoration:none; font-size:13px; font-weight:600;'>Palikti atsiliepimą &rarr;</a>
                </td>
            </tr>";
        }
        $itemListHtml .= '</table>';

        $subject = "Kaip vertinate savo pirkinius? ⭐";
        $content = "<p>Sveiki, {$order['customer_name']},</p>
                    <p>Tikimės, kad siuntą gavote sėkmingai ir jau spėjote išbandyti prekes.</p>
                    <p>Jūsų nuomonė labai svarbi mums ir kitiems bendruomenės nariams. Būtume labai dėkingi, jei skirtumėte minutę įvertinimui:</p>
                    {$itemListHtml}";

        $html = getEmailTemplate("Jūsų nuomonė svarbi", $content); // Be pagrindinio mygtuko, nes nuorodos lentelėje
        
        if (sendEmail($order['customer_email'], $subject, $html)) {
            $pdo->prepare("UPDATE orders SET email_review_sent = 1 WHERE id = ?")->execute([$order['id']]);
            $log[] = "[REVIEW] Išsiųstas prašymas įvertinti užsakymui #{$order['id']}";
        }
    }
}

// =================================================================
// 3. GIMTADIENIO STAIGMENA
// (Yra gimimo data, šiandien gimtadienis, šiemet dar nesiųsta)
// =================================================================

$stmtBday = $pdo->prepare("
    SELECT id, name, email, birthdate 
    FROM users 
    WHERE birthdate IS NOT NULL 
    AND MONTH(birthdate) = MONTH(NOW()) 
    AND DAY(birthdate) = DAY(NOW())
    AND (last_birthday_promo IS NULL OR last_birthday_promo < YEAR(NOW()))
    LIMIT 5
");
$stmtBday->execute();
$usersBday = $stmtBday->fetchAll();

foreach ($usersBday as $user) {
    // Generuojame kodą: GIMTADIENIS-XXXXX
    $code = generateUniqueDiscountCode($pdo, 'GIMTADIENIS');
    
    // Įrašome nuolaidą (pvz. 15% viskam, galioja 7 d.)
    $stmtCode = $pdo->prepare("INSERT INTO discount_codes (code, type, value, usage_limit, active, created_at) VALUES (?, 'percent', 15.00, 1, 1, NOW())");
    $stmtCode->execute([$code]);

    $subject = "Su gimtadieniu, {$user['name']}! 🎂";
    $content = "<p>Sveikiname Jus gražiausios metų šventės proga!</p>
                <p>Linkime saldžių akimirkų (bet su geru cukraus kiekiu 😉) ir dovanojame <strong>15% nuolaidą</strong> visam krepšeliui.</p>
                <div style='text-align:center; margin: 24px 0;'>
                    <span style='background:#fef3c7; border:1px dashed #d97706; color:#b45309; padding:12px 24px; font-size:18px; font-weight:700; border-radius:8px;'>{$code}</span>
                </div>
                <p>Kodas galioja 7 dienas.</p>";

    $html = getEmailTemplate("Šventinė dovana Jums", $content, "https://cukrinukas.lt/products.php", "Apsipirkti");

    if (sendEmail($user['email'], $subject, $html)) {
        // Pažymime, kad šiais metais (pvz. 2025) jau pasveikinome
        $pdo->prepare("UPDATE users SET last_birthday_promo = YEAR(NOW()) WHERE id = ?")->execute([$user['id']]);
        $log[] = "[BDAY] Išsiųstas sveikinimas vartotojui ID {$user['id']}";
    }
}

// =================================================================
// 4. "WIN-BACK" (ILGESIO LAIŠKAS)
// (Paskutinis užsakymas > 90 d., Paskutinis win-back > 180 d. arba niekada)
// =================================================================

// Randame vartotojus, kurių paskutinis užsakymas senesnis nei 3 mėn
$stmtWinback = $pdo->prepare("
    SELECT u.id, u.name, u.email, MAX(o.created_at) as last_order_date
    FROM users u
    JOIN orders o ON u.id = o.user_id
    WHERE (u.last_winback_promo IS NULL OR u.last_winback_promo < DATE_SUB(NOW(), INTERVAL 6 MONTH))
    GROUP BY u.id
    HAVING last_order_date < DATE_SUB(NOW(), INTERVAL 90 DAY)
    LIMIT 5
");
$stmtWinback->execute();
$usersWinback = $stmtWinback->fetchAll();

foreach ($usersWinback as $user) {
    // Generuojame kodą: SUGRIZK-XXXXX
    $code = generateUniqueDiscountCode($pdo, 'SUGRIZK');
    
    // Įrašome nuolaidą (pvz. 10% viskam)
    $stmtCode = $pdo->prepare("INSERT INTO discount_codes (code, type, value, usage_limit, active, created_at) VALUES (?, 'percent', 10.00, 1, 1, NOW())");
    $stmtCode->execute([$code]);

    $subject = "Mes Jūsų pasigedome! 👋";
    $content = "<p>Sveiki, {$user['name']},</p>
                <p>Senokai bematėme Jus Cukrinukas.lt parduotuvėje. Per tą laiką pasipildėme naujais mažo GI užkandžiais ir diabeto priežiūros priemonėmis.</p>
                <p>Kviečiame sugrįžti su <strong>10% nuolaida</strong> kitam užsakymui:</p>
                <div style='text-align:center; margin: 24px 0;'>
                    <span style='background:#eff6ff; border:1px dashed #2563eb; color:#1e40af; padding:12px 24px; font-size:18px; font-weight:700; border-radius:8px;'>{$code}</span>
                </div>";

    $html = getEmailTemplate("Laukiame sugrįžtant", $content, "https://cukrinukas.lt/products.php", "Peržiūrėti naujienas");

    if (sendEmail($user['email'], $subject, $html)) {
        // Pažymime laiką, kada siuntėme win-back
        $pdo->prepare("UPDATE users SET last_winback_promo = NOW() WHERE id = ?")->execute([$user['id']]);
        $log[] = "[WINBACK] Išsiųstas kvietimas sugrįžti vartotojui ID {$user['id']}";
    }
}

// =================================================================
// 5. ANKSTESNI SCENARIJAI (Neapmokėti krepšeliai ir Padėka)
// =================================================================

// 1h priminimas
$stmt1h = $pdo->prepare("SELECT * FROM orders WHERE status = 'laukiama apmokėjimo' AND created_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR) AND created_at > DATE_SUB(NOW(), INTERVAL 6 HOUR) AND email_rem_1h = 0 LIMIT 5");
$stmt1h->execute();
foreach ($stmt1h->fetchAll() as $o) {
    $html = getEmailTemplate("Jūsų krepšelis laukia", "<p>Sveiki, {$o['customer_name']}, nepamirškite savo prekių krepšelyje.</p>", "https://cukrinukas.lt/account.php", "Tęsti");
    if (sendEmail($o['customer_email'], "Nepavyko apmokėti?", $html)) {
        $pdo->prepare("UPDATE orders SET email_rem_1h = 1 WHERE id = ?")->execute([$o['id']]);
        $log[] = "[1H] Priminimas užsakymui #{$o['id']}";
    }
}

// 24h priminimas
$stmt24h = $pdo->prepare("SELECT * FROM orders WHERE status = 'laukiama apmokėjimo' AND created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND created_at > DATE_SUB(NOW(), INTERVAL 48 HOUR) AND email_rem_24h = 0 LIMIT 5");
$stmt24h->execute();
foreach ($stmt24h->fetchAll() as $o) {
    $html = getEmailTemplate("Ar dar domina prekės?", "<p>Sveiki, {$o['customer_name']}, praėjo para, o užsakymas dar neapmokėtas.</p>", "https://cukrinukas.lt/contact.php", "Susisiekti");
    if (sendEmail($o['customer_email'], "Krepšelio priminimas", $html)) {
        $pdo->prepare("UPDATE orders SET email_rem_24h = 1 WHERE id = ?")->execute([$o['id']]);
        $log[] = "[24H] Priminimas užsakymui #{$o['id']}";
    }
}

// Padėka po 1 val. (Sėkmingi)
$stmtThx = $pdo->prepare("SELECT * FROM orders WHERE (status = 'patvirtinta' OR status = 'įvykdyta') AND created_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR) AND created_at > DATE_SUB(NOW(), INTERVAL 48 HOUR) AND email_thankyou = 0 LIMIT 5");
$stmtThx->execute();
foreach ($stmtThx->fetchAll() as $o) {
    $code = generateUniqueDiscountCode($pdo, 'ACIU');
    $pdo->prepare("INSERT INTO discount_codes (code, type, value, usage_limit, active, created_at) VALUES (?, 'percent', 5.00, 1, 1, NOW())")->execute([$code]);
    
    $content = "<p>Ačiū, kad perkate! Dovanojame 5% nuolaidą kitam kartui: <strong>{$code}</strong></p>";
    $html = getEmailTemplate("Dovana Jums", $content, "https://cukrinukas.lt/products.php", "Panaudoti");
    
    if (sendEmail($o['customer_email'], "Ačiū už užsakymą! 🎁", $html)) {
        $pdo->prepare("UPDATE orders SET email_thankyou = 1 WHERE id = ?")->execute([$o['id']]);
        $log[] = "[THANKYOU] Padėka užsakymui #{$o['id']}";
    }
}

// --- LOGO IŠVEDIMAS ---
if (!empty($log)) {
    echo implode("<br>", $log);
} else {
    echo "Nėra naujų laiškų siuntimui.";
}
?>
