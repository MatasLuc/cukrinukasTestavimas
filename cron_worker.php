<?php
// cron_worker.php
// Nustatyti CRON JOB vykdymą kas 10-15 min.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/env.php';
loadEnvFile(__DIR__ . '/.env');


$pdo = getPdo();
$log = [];

// --- PAGALBINĖ FUNKCIJA KODŲ GENERAVIMUI ---
if (!function_exists('generateUniqueDiscountCode')) {
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
}

// --- MAC TOKEN AUTENTIFIKACIJA ---
if (!function_exists('buildMacAuthHeader')) {
    function buildMacAuthHeader(string $macId, string $macSecret, string $method, string $url): string {
        $parsed = parse_url($url);
        $ts     = (string) time();
        $nonce  = bin2hex(random_bytes(8));
        $host   = $parsed['host'];
        $port   = $parsed['port'] ?? (($parsed['scheme'] === 'https') ? 443 : 80);
        $path   = $parsed['path'] ?? '/';
        if (!empty($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }

        $requestString = $ts    . "\n"
                       . $nonce . "\n"
                       . strtoupper($method) . "\n"
                       . $path  . "\n"
                       . $host  . "\n"
                       . (string)$port . "\n"
                       . "\n";

        $mac = base64_encode(hash_hmac('sha256', $requestString, $macSecret, true));

        return sprintf('MAC id="%s", ts="%s", nonce="%s", mac="%s"',
            $macId, $ts, $nonce, $mac);
    }
}

// =================================================================
// PAŠTOMATAI
// =================================================================
try {
    $siteContent    = getSiteContent($pdo);
    $lastLockerSync = (int)($siteContent['last_locker_sync'] ?? 0);
    $forceSync      = $forceSync ?? false;

    if ($forceSync || (time() - $lastLockerSync > 604800)) {

        $projectId = getenv('PAYSERA_PROJECTID') ?: ($_ENV['PAYSERA_PROJECTID'] ?? '');
        $password  = getenv('PAYSERA_PASSWORD')  ?: ($_ENV['PAYSERA_PASSWORD']  ?? '');
        $baseUrl   = rtrim(getenv('PAYSERA_DELIVERY_API_URL') ?: ($_ENV['PAYSERA_DELIVERY_API_URL'] ?? 'https://delivery-api.paysera.com/rest/v1'), '/');

        $allItems = [];
        $limit    = 200;
        $offset   = 0;
        $hasMore  = true;
        $apiError = false;
        $page     = 1;

        while ($hasMore) {

            $apiUrl = $baseUrl . "/parcel-machines?country=LT&limit={$limit}&offset={$offset}";
            $ch     = curl_init($apiUrl);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Authorization: ' . buildMacAuthHeader($projectId, $password, 'GET', $apiUrl),
                ],
                CURLOPT_TIMEOUT => 60,
            ]);

            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode !== 200 || !$response) {
                $log[] = "[LOCKERS] ❌ API klaida (puslapis {$page}). HTTP: {$httpCode}. CURL: {$curlError}. Atsakas: " . substr($response, 0, 300);
                $apiError = true;
                break;
            }

            $data = json_decode($response, true);

            if ($page === 1) {
                $log[] = "[LOCKERS] Metadata: " . json_encode($data['_metadata'] ?? 'nėra');
            }

            $items = $data['list'] ?? $data['items'] ?? null;

            if ($items === null) {
                $log[] = "[LOCKERS] ❌ Nežinoma API struktūra. Raktai: " . json_encode(array_keys($data));
                $apiError = true;
                break;
            }

            $fetchedCount = count($items);
            if ($fetchedCount > 0) {
                $allItems = array_merge($allItems, $items);
            }

            $log[] = "[LOCKERS] Puslapis {$page}: gauta {$fetchedCount} (iš viso surinkta: " . count($allItems) . ")";

            $metadata = $data['_metadata'] ?? [];

            if (isset($metadata['has_next'])) {
                $hasMore = filter_var($metadata['has_next'], FILTER_VALIDATE_BOOLEAN);
            } elseif (isset($metadata['total']) || isset($metadata['total_count'])) {
                $total   = (int)($metadata['total'] ?? $metadata['total_count']);
                $hasMore = ($offset + $limit) < $total;
            } else {
                $hasMore = ($fetchedCount === $limit);
            }

            if ($hasMore) {
                $offset += $limit;
                $page++;

                if ($page > 100) {
                    $log[] = "[LOCKERS] ⚠️ Pasiektas 100 puslapių limitas. Stabdoma.";
                    break;
                }
            }
        }

        if (!$apiError && !empty($allItems)) {

            // --- DIAGNOSTIKA: suskaičiuojame kas yra prieš filtravimą ---
            $providerStats  = [];
            $skippedDisabled = 0;
            $skippedNoId     = 0;
            foreach ($allItems as $item) {
                $raw = strtolower($item['shipment_gateway_code'] ?? 'unknown');
                $providerStats[$raw] = ($providerStats[$raw] ?? 0) + 1;

                // Tikriname enabled tipą — gali būti bool, int arba null
                $enabledRaw = $item['enabled'] ?? null;
                if ($enabledRaw === null || $enabledRaw === false || $enabledRaw === 0 || $enabledRaw === '0' || $enabledRaw === '') {
                    $skippedDisabled++;
                } elseif (empty($item['id'])) {
                    $skippedNoId++;
                }
            }
            $log[] = "[LOCKERS] Visi iš API pagal tiekėją: " . json_encode($providerStats);
            $log[] = "[LOCKERS] Praleista (disabled): {$skippedDisabled}, praleista (be ID): {$skippedNoId}";

            $pdo->exec("TRUNCATE TABLE parcel_lockers");

            $stmt = $pdo->prepare("
                INSERT INTO parcel_lockers (provider, terminal_id, title, address, note)
                VALUES (?, ?, ?, ?, ?)
            ");

            $inserted        = 0;
            $insertedByProv  = [];

            foreach ($allItems as $item) {

                // Enabled patikrinimas — palaikome bool, int, string
                $enabledRaw = $item['enabled'] ?? null;
                if ($enabledRaw === null || $enabledRaw === false || $enabledRaw === 0 || $enabledRaw === '0' || $enabledRaw === '') {
                    continue;
                }

                $terminalId = $item['id'] ?? '';
                if (empty($terminalId)) {
                    continue;
                }

                // Normalizuojame provider pavadinimą
                // lp_express → lpexpress, omniva → omniva, dpd → dpd, venipak → venipak
                $providerRaw = $item['shipment_gateway_code'] ?? 'unknown';
                $provider    = str_replace(['_', '-', ' '], '', strtolower($providerRaw));

                $locationName = $item['location_name'] ?? ($item['name'] ?? '');
                $code         = $item['code'] ?? '';
                $title        = trim($locationName . ($code ? ' (' . $code . ')' : ''));

                $addressObj   = $item['address'] ?? [];
                $addressParts = [];

                if (!empty($addressObj['street'])) {
                    $street = $addressObj['street'];
                    if (!empty($addressObj['house_number'])) {
                        $street .= ' ' . $addressObj['house_number'];
                    }
                    $addressParts[] = $street;
                }
                if (!empty($addressObj['city']))        { $addressParts[] = $addressObj['city']; }
                if (!empty($addressObj['postal_code'])) { $addressParts[] = $addressObj['postal_code']; }

                $address = implode(', ', $addressParts);

                $stmt->execute([$provider, $terminalId, $title, $address, '']);
                $inserted++;
                $insertedByProv[$provider] = ($insertedByProv[$provider] ?? 0) + 1;
            }

            saveSiteContent($pdo, ['last_locker_sync' => (string)time()]);
            $log[] = "[LOCKERS] ✅ Sėkmingai atnaujinta. Įrašyta: {$inserted} vnt. pagal tiekėją: " . json_encode($insertedByProv);

        } elseif (!$apiError) {
            $log[] = "[LOCKERS] ⚠️ API grąžino tuščią sąrašą.";
        }

    } else {
        $secondsLeft = 604800 - (time() - $lastLockerSync);
        $log[] = "[LOCKERS] Atnaujinimas praleistas (nepraėjo 7 dienos, liko ~" . round($secondsLeft / 3600) . " val.).";
    }

} catch (Exception $e) {
    $log[] = "[LOCKERS] ❌ Klaida: " . $e->getMessage();
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
    $details      = json_decode($order['delivery_details'] ?? '{}', true);
    $trackingHtml = '';

    if (!empty($details['tracking_code'])) {
        $trackingHtml = "<p style='background: #f0fdf4; padding: 15px; border-radius: 8px; border: 1px solid #bbf7d0; color: #166534;'>
                            Jūsų siuntos sekimo numeris: <strong>{$details['tracking_code']}</strong>
                         </p>";
    } elseif (!empty($details['method']) && $details['method'] === 'locker') {
        $locTitle     = $details['title'] ?? 'Paštomatas';
        $trackingHtml = "<p>Siunta keliauja į pasirinktą paštomatą: <strong>{$locTitle}</strong>.</p>";
    }

    $subject = "Jūsų užsakymas #{$order['id']} išsiųstas! 🚀";
    $content = "<p>Sveiki, {$order['customer_name']},</p>
                <p>Geros žinios – jūsų užsakymas jau supakuotas ir perduotas kurjerių tarnybai.</p>
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
            $prodUrl = "https://cukrinukas.lt/product.php?id=" . $item['product_id'] . "#reviews";
            $img     = htmlspecialchars($item['image_url']);
            $title   = htmlspecialchars($item['title']);

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

        $html = getEmailTemplate("Jūsų nuomonė svarbi", $content);

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
    $code = generateUniqueDiscountCode($pdo, 'GIMTADIENIS');

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
        $pdo->prepare("UPDATE users SET last_birthday_promo = YEAR(NOW()) WHERE id = ?")->execute([$user['id']]);
        $log[] = "[BDAY] Išsiųstas sveikinimas vartotojui ID {$user['id']}";
    }
}

// =================================================================
// 4. "WIN-BACK" (ILGESIO LAIŠKAS)
// (Paskutinis užsakymas > 90 d., Paskutinis win-back > 180 d. arba niekada)
// =================================================================

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
    $code = generateUniqueDiscountCode($pdo, 'SUGRIZK');

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
        $pdo->prepare("UPDATE users SET last_winback_promo = NOW() WHERE id = ?")->execute([$user['id']]);
        $log[] = "[WINBACK] Išsiųstas kvietimas sugrįžti vartotojui ID {$user['id']}";
    }
}

// =================================================================
// 5. NEAPMOKĖTI KREPŠELIAI IR PADĖKA
// =================================================================

// 1h priminimas
$stmt1h = $pdo->prepare("
    SELECT * FROM orders 
    WHERE status = 'laukiama apmokėjimo' 
    AND created_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR) 
    AND created_at > DATE_SUB(NOW(), INTERVAL 6 HOUR) 
    AND email_rem_1h = 0 
    LIMIT 5
");
$stmt1h->execute();
foreach ($stmt1h->fetchAll() as $o) {
    $html = getEmailTemplate(
        "Jūsų krepšelis laukia",
        "<p>Sveiki, {$o['customer_name']}, nepamirškite savo prekių krepšelyje.</p>",
        "https://cukrinukas.lt/account.php",
        "Tęsti"
    );
    if (sendEmail($o['customer_email'], "Nepavyko apmokėti?", $html)) {
        $pdo->prepare("UPDATE orders SET email_rem_1h = 1 WHERE id = ?")->execute([$o['id']]);
        $log[] = "[1H] Priminimas užsakymui #{$o['id']}";
    }
}

// 24h priminimas
$stmt24h = $pdo->prepare("
    SELECT * FROM orders 
    WHERE status = 'laukiama apmokėjimo' 
    AND created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR) 
    AND created_at > DATE_SUB(NOW(), INTERVAL 48 HOUR) 
    AND email_rem_24h = 0 
    LIMIT 5
");
$stmt24h->execute();
foreach ($stmt24h->fetchAll() as $o) {
    $html = getEmailTemplate(
        "Ar dar domina prekės?",
        "<p>Sveiki, {$o['customer_name']}, praėjo para, o užsakymas dar neapmokėtas.</p>",
        "https://cukrinukas.lt/contact.php",
        "Susisiekti"
    );
    if (sendEmail($o['customer_email'], "Krepšelio priminimas", $html)) {
        $pdo->prepare("UPDATE orders SET email_rem_24h = 1 WHERE id = ?")->execute([$o['id']]);
        $log[] = "[24H] Priminimas užsakymui #{$o['id']}";
    }
}

// Padėka po 1 val. (Sėkmingi užsakymai)
$stmtThx = $pdo->prepare("
    SELECT * FROM orders 
    WHERE (status = 'patvirtinta' OR status = 'įvykdyta') 
    AND created_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR) 
    AND created_at > DATE_SUB(NOW(), INTERVAL 48 HOUR) 
    AND email_thankyou = 0 
    LIMIT 5
");
$stmtThx->execute();
foreach ($stmtThx->fetchAll() as $o) {
    $code = generateUniqueDiscountCode($pdo, 'ACIU');
    $pdo->prepare("INSERT INTO discount_codes (code, type, value, usage_limit, active, created_at) VALUES (?, 'percent', 5.00, 1, 1, NOW())")->execute([$code]);

    $content = "<p>Ačiū, kad perkate! Dovanojame 5% nuolaidą kitam kartui: <strong>{$code}</strong></p>";
    $html    = getEmailTemplate("Dovana Jums", $content, "https://cukrinukas.lt/products.php", "Panaudoti");

    if (sendEmail($o['customer_email'], "Ačiū už užsakymą! 🎁", $html)) {
        $pdo->prepare("UPDATE orders SET email_thankyou = 1 WHERE id = ?")->execute([$o['id']]);
        $log[] = "[THANKYOU] Padėka užsakymui #{$o['id']}";
    }
}

// =================================================================
// REZULTATAI
// =================================================================
if (!empty($log)) {
    echo implode("<br>", $log);
} else {
    echo "Nėra naujų laiškų siuntimui ar veiksmų atlikimui.";
}
?>
