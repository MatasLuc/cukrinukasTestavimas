<?php
// admin/orders.php
// Pilnas administracinis užsakymų valdymo failas su Paysera Integration

// --- MAC TOKEN AUTENTIFIKACIJA ---
if (!function_exists('buildMacAuthHeader')) {
    function buildMacAuthHeader(string $macId, string $macSecret, string $method, string $url, string $body = ''): string {
        $parsed = parse_url($url);
        $ts     = (string) time();
        $nonce  = bin2hex(random_bytes(8));
        $host   = $parsed['host'];
        $port   = (int)($parsed['port'] ?? (($parsed['scheme'] === 'https') ? 443 : 80));

        $path   = $parsed['path'] ?? '/';
        if (!empty($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }

        // Apskaičiuojame body hash
        // Paysera reikalauja "body_hash=<hash>" tiek request string viduje,
        // tiek Authorization header ext lauke (pilnas tekstas, ne tik hash)
        $ext = '';
        if ($body !== '') {
            $ext = 'body_hash=' . base64_encode(hash('sha256', $body, true));
        }

        // MAC parašo formatas — tiksliai pagal OAuth MAC spec
        $requestString = $ts    . "\n"
                       . $nonce . "\n"
                       . strtoupper($method) . "\n"
                       . $path  . "\n"
                       . $host  . "\n"
                       . (string)$port . "\n"
                       . $ext   . "\n";

        $mac = base64_encode(hash_hmac('sha256', $requestString, $macSecret, true));

        if ($ext !== '') {
            return sprintf('MAC id="%s", ts="%s", nonce="%s", mac="%s", ext="%s"',
                $macId, $ts, $nonce, $mac, $ext);
        }

        return sprintf('MAC id="%s", ts="%s", nonce="%s", mac="%s"',
            $macId, $ts, $nonce, $mac);
    }
}

// 0. IŠTRYNIMO LOGIKA
if (isset($_POST['delete_id'])) {
    if (function_exists('validateCsrf')) {
        validateCsrf();
    }

    $deleteId = (int)$_POST['delete_id'];
    try {
        $stmtDel = $pdo->prepare("DELETE FROM orders WHERE id = ?");
        $stmtDel->execute([$deleteId]);
        
        $stmtDelItems = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
        $stmtDelItems->execute([$deleteId]);

        echo "<script>window.location.href='index.php?page=orders';</script>";
        exit;
    } catch (PDOException $e) {
        echo "<div class='alert alert-danger'>Klaida trinant: " . $e->getMessage() . "</div>";
    }
}

// 0.1 PAYSERA SIUNTOS KŪRIMO LOGIKA
if (isset($_POST['create_paysera_shipment'])) {
    if (function_exists('validateCsrf')) {
        validateCsrf();
    }

    $orderId        = (int)$_POST['order_id'];
    $senderLockerId = $_POST['sender_locker_id'];

    try {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($order) {
            // Įkrauname .env
            $envFile = __DIR__ . '/../.env';
            if (file_exists($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (strpos(trim($line), '#') === 0) continue;
                    if (strpos($line, '=') === false) continue;
                    list($name, $value) = explode('=', $line, 2);
                    $_ENV[trim($name)] = trim($value);
                }
            }

            $projectId = "248259";

            // PATAISYMAS: pašaliname \r, \n, kabutes ir tarpus
            $rawPassword = $_ENV['PAYSERA_PASSWORD'] ?? getenv('PAYSERA_PASSWORD') ?? '';
            $password    = trim(str_replace(['"', "'", "\r", "\n"], '', $rawPassword));

            $apiUrl = $_ENV['PAYSERA_DELIVERY_API_URL'] ?? 'https://delivery-api.paysera.com/rest/v1';

            if (empty($password)) {
                throw new Exception("Nepavyko nuskaityti PAYSERA_PASSWORD. Patikrinkite savo .env failą.");
            }

            $delDetails = json_decode($order['delivery_details'], true);

            $gatewayCode = (stripos($senderLockerId, 'OMN') !== false || stripos($senderLockerId, 'omniva') !== false)
                ? 'omniva'
                : 'lp_express';

            $methodCode = ($order['delivery_method'] === 'courier')
                ? 'parcel-machine2courier'
                : 'parcel-machine2parcel-machine';

            $payload = [
                'project_id'             => $projectId,
                'shipment_gateway_code'  => $gatewayCode,
                'shipment_method_code'   => $methodCode,

                'sender' => [
                    'type'             => 'sender',
                    'project_id'       => $projectId,
                    'parcel_machine_id'=> $senderLockerId,
                    'saved'            => false,
                    'default_contact'  => false,
                    'contact'          => [
                        'party'   => [
                            'title' => 'Cukrinukas.lt',
                            'email' => 'labas@cukrinukas.lt',
                            'phone' => '+37064477724',
                        ],
                        'address' => [
                            'country'     => 'LT',
                            'city'        => 'Vilnius',
                            'street'      => 'Miglos g. 65',
                            'postal_code' => '01103',
                        ],
                    ],
                ],

                'receiver' => [
                    'type'             => 'receiver',
                    'project_id'       => $projectId,
                    'parcel_machine_id'=> $delDetails['locker_id'] ?? '',
                    'saved'            => false,
                    'default_contact'  => false,
                    'contact'          => [
                        'party'   => [
                            'title' => $order['customer_name'],
                            'email' => $order['customer_email'],
                            'phone' => $order['customer_phone'] ?? '+37060000000',
                        ],
                        'address' => [
                            'country'     => 'LT',
                            'city'        => 'Vilnius',
                            'street'      => $order['customer_address'] ?? 'Nenurodyta',
                            'postal_code' => '00000',
                        ],
                    ],
                ],

                'shipments' => [
                    [
                        'weight'       => 1000,
                        'width'        => 380,
                        'length'       => 640,
                        'height'       => 190,
                        'package_size' => 'M',
                    ],
                ],
            ];

            if ($order['delivery_method'] === 'courier') {
                $addr  = $order['customer_address'];
                $parts = explode(',', $addr);
                if (count($parts) >= 3) {
                    $payload['receiver']['contact']['address']['street']      = trim($parts[0]);
                    $payload['receiver']['contact']['address']['city']        = trim($parts[1]);
                    $payload['receiver']['contact']['address']['postal_code'] = str_replace(['LT-', ' '], '', trim($parts[2]));
                } else {
                    $payload['receiver']['contact']['address']['city']   = 'Lietuva';
                    $payload['receiver']['contact']['address']['street'] = $addr;
                }
                unset($payload['receiver']['parcel_machine_id']);
            }

            $endpoint    = rtrim($apiUrl, '/') . '/orders';
            $payloadJson = json_encode($payload);
            $macAuth     = buildMacAuthHeader($projectId, $password, 'POST', $endpoint, $payloadJson);

            // Debug log prieš siuntimą
            error_log("[PAYSERA] Endpoint: " . $endpoint);
            error_log("[PAYSERA] MAC Auth: " . $macAuth);
            error_log("[PAYSERA] Payload: " . $payloadJson);

            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: ' . $macAuth,
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            error_log("[PAYSERA] HTTP $httpCode | Response: " . substr($response, 0, 500));

            if ($httpCode >= 200 && $httpCode < 300 && $response) {
                $resData = json_decode($response, true);

                $payseraId = $resData['id'] ?? '';
                $shipments = $resData['shipments'] ?? [];
                $tracking  = '';
                $labelUrl  = '';

                if (!empty($shipments) && is_array($shipments)) {
                    $firstShipment = $shipments[0];
                    $tracking      = $firstShipment['tracking_code'] ?? '';
                    $labelUrl      = $firstShipment['label_url'] ?? (rtrim($apiUrl, '/') . "/shipments/{$firstShipment['id']}/label");
                }

                $updateStmt = $pdo->prepare("
                    UPDATE orders 
                    SET paysera_shipment_id = ?, paysera_label_url = ?, tracking_number = ?, status = 'išsiųsta' 
                    WHERE id = ?
                ");
                $updateStmt->execute([$payseraId, $labelUrl, $tracking, $orderId]);

                $mailerPath = __DIR__ . '/../mailer.php';
                if (file_exists($mailerPath)) {
                    require_once $mailerPath;
                    if (function_exists('sendEmail')) {
                        $subject = "Jūsų užsakymas paruoštas siuntimui!";
                        $body    = "
                            <div style='font-family: sans-serif; color: #333;'>
                                <h2>Sveiki, " . htmlspecialchars($order['customer_name']) . ",</h2>
                                <p>Norime pranešti, kad Jūsų užsakymas <strong>#" . (int)$order['id'] . "</strong> yra sėkmingai sugeneruotas sistemoje ir netrukus pajudės pas Jus.</p>";
                        if (!empty($tracking)) {
                            $body .= "<p>Siuntos sekimo numeris: <strong>{$tracking}</strong></p>";
                        }
                        $body .= "
                                <p>Prekės jus pasieks artimiausiu metu (dažniausiai per 1-2 d.d.).</p>
                            </div>
                        ";
                        sendEmail($order['customer_email'], $subject, $body);
                    }
                }

                echo "<script>window.location.href='index.php?page=orders';</script>";
                exit;
            } else {
                $err      = json_decode($response, true);
                $errorMsg = $err['message'] ?? ($err['error_description'] ?? ($err['error'] ?? 'Nežinoma klaida'));

                $passLen   = strlen($password);
                $passFirst = substr($password, 0, 4) . '...';

                $debugInfo = "
                    <br><br><strong>Techninė informacija:</strong><br>
                    Project_ID: <code>{$projectId}</code> (Tipas: " . gettype($projectId) . ")<br>
                    Password ilgis: <code>{$passLen}</code> simbolių | Pradžia: <code>{$passFirst}</code><br>
                    Endpoint: <code>{$endpoint}</code><br>
                    MAC Auth: <code>" . htmlspecialchars($macAuth) . "</code><br>
                    cURL klaida: <code>" . htmlspecialchars($curlError ?: 'nėra') . "</code>
                ";

                echo "<div class='alert alert-danger'>";
                echo "<strong>Paysera API Klaida:</strong> " . htmlspecialchars($errorMsg) . " (HTTP: $httpCode)";
                echo $debugInfo;
                echo "<br><br><small><strong>Pilnas atsakymas:</strong> " . htmlspecialchars(substr($response, 0, 1500)) . "</small>";
                echo "</div>";
            }
        }
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>Sistemos klaida: " . $e->getMessage() . "</div>";
    }
}

// 1. Surenkame duomenis
try {
    $allOrders = $pdo->query('
        SELECT o.*, 
               u.name  AS user_name, 
               u.email AS user_email
        FROM orders o 
        LEFT JOIN users u ON u.id = o.user_id 
        ORDER BY o.created_at DESC
    ')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Duomenų bazės klaida (užsakymai): " . $e->getMessage() . "</div>";
    $allOrders = [];
}

// 1.1 Gauname paštomatus
try {
    $lockers = $pdo->query("SELECT * FROM parcel_lockers ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $lockers = [];
}

$orderItemsStmt = $pdo->prepare('
    SELECT oi.*, p.title, p.image_url 
    FROM order_items oi 
    JOIN products p ON p.id = oi.product_id 
    WHERE order_id = ?
');

foreach ($allOrders as &$order) {
    $orderItemsStmt->execute([$order['id']]);
    $order['items'] = $orderItemsStmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($order);

// Paruošiame paštomatus JS
$lockersForJs = [];
try {
    foreach ($lockers as $l) {
        $addressParts = explode(',', $l['address'] ?? '');
        $derivedCity  = trim($addressParts[0] ?? '');

        $lockersForJs[] = [
            'title'       => $l['title'],
            'address'     => $l['address'],
            'city'        => $derivedCity,
            'type'        => strtolower(trim($l['provider'] ?? 'other')),
            'full'        => ($derivedCity ? $derivedCity . ' - ' : '') . $l['title'] . ' (' . $l['address'] . ')',
            'terminal_id' => $l['terminal_id'] ?? '',
        ];
    }
    usort($lockersForJs, function ($a, $b) {
        return strcmp($a['city'], $b['city']) ?: strcmp($a['title'], $b['title']);
    });
} catch (Exception $e) {}
?>

<style>
    .status-badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        display: inline-block;
    }
    .status-laukiama.apmokėjimo { background: #fff7ed; color: #c2410c; border: 1px solid #ffedd5; }
    .status-apmokėta  { background: #ecfdf5; color: #047857; border: 1px solid #d1fae5; }
    .status-apdorojama{ background: #eff6ff; color: #1d4ed8; border: 1px solid #dbeafe; }
    .status-išsiųsta  { background: #f0fdf4; color: #15803d; border: 1px solid #dcfce7; }
    .status-įvykdyta  { background: #ecfdf5; color: #047857; border: 1px solid #d1fae5; }
    .status-atšaukta  { background: #fef2f2; color: #b91c1c; border: 1px solid #fee2e2; }
    .status-atmesta   { background: #fef2f2; color: #b91c1c; border: 1px solid #fee2e2; }

    .modal-overlay {
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.5);
        backdrop-filter: blur(4px);
        z-index: 1000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
        opacity: 0;
        transition: opacity 0.2s ease;
    }
    .modal-overlay.open { display: flex; opacity: 1; }

    .modal-window {
        background: #fff;
        width: 100%;
        max-width: 900px;
        max-height: 90vh;
        border-radius: 16px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.2);
        overflow-y: auto;
        position: relative;
        display: flex;
        flex-direction: column;
    }

    .modal-header {
        padding: 15px 24px;
        border-bottom: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #fcfcfc;
        position: sticky; top: 0;
        z-index: 10;
    }
    .modal-title  { font-size: 18px; font-weight: 700; margin: 0; }
    .modal-close  { background: none; border: none; font-size: 24px; cursor: pointer; color: #888; line-height: 1; padding: 0; }
    .modal-body   { padding: 24px; }

    .modal-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
        margin-bottom: 24px;
    }

    .info-section { background: #f9f9f9; border-radius: 8px; padding: 15px; border: 1px solid #eee; }
    .info-group h4 { margin: 0 0 10px 0; font-size: 12px; text-transform: uppercase; color: #888; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
    .info-row { display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 13px; }
    .info-row label { font-weight: 600; color: #555; }
    .info-row span  { text-align: right; color: #111; max-width: 60%; word-break: break-word; }

    .item-list { border: 1px solid #eee; border-radius: 12px; overflow: hidden; margin-top: 20px; }
    .item-row  { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-bottom: 1px solid #eee; }
    .item-row:last-child { border-bottom: none; }
    .item-img     { width: 40px; height: 40px; border-radius: 6px; object-fit: cover; background: #eee; }
    .item-details { flex: 1; }
    .item-title   { font-weight: 600; font-size: 14px; }
    .item-meta    { font-size: 12px; color: #777; margin-top: 2px; }
    .item-price   { font-weight: 700; font-size: 14px; }

    .modal-footer {
        padding: 16px 24px;
        background: #f9f9ff;
        border-top: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .form-control { padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; width: 100%; }

    .btn-delete { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; padding: 6px 10px; font-weight: bold; margin-left: 5px; cursor: pointer; border-radius: 4px; }
    .btn-delete:hover { background: #fecaca; }

    .badge-bool { padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; }
    .badge-yes  { background: #dcfce7; color: #166534; }
    .badge-no   { background: #f3f4f6; color: #6b7280; }

    .btn-shipment { background: #2563eb; color: #fff; font-size: 11px; padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; display: inline-block; font-weight: 600; text-decoration: none; }
    .btn-shipment:hover { background: #1d4ed8; }
    .btn-label { background: #f3f4f6; color: #111; border: 1px solid #ccc; font-size: 11px; padding: 6px 12px; border-radius: 4px; text-decoration: none; display: inline-block; font-weight: 600; }
    .btn-label:hover { background: #e5e7eb; }

    .custom-select-wrapper       { position: relative; user-select: none; width: 100%; }
    .custom-select-trigger       { position: relative; display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; font-size: 14px; font-weight: 400; color: #0f172a; background: #fff; border: 1px solid #e4e7ec; border-radius: 8px; cursor: pointer; transition: all 0.2s; }
    .custom-select-wrapper.open .custom-select-trigger { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
    .custom-select-trigger span  { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .custom-options-container    { position: absolute; display: none; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #e4e7ec; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); z-index: 100; margin-top: 4px; max-height: 300px; overflow: hidden; flex-direction: column; }
    .custom-select-wrapper.open .custom-options-container { display: flex; }

    .sticky-search       { padding: 8px; background: #fff; border-bottom: 1px solid #e4e7ec; }
    .sticky-search input { width: 100%; padding: 8px; border: 1px solid #e4e7ec; border-radius: 6px; font-size: 13px; box-sizing: border-box; }
    .sticky-search input:focus { outline: none; border-color: #2563eb; }

    .options-list           { overflow-y: auto; flex: 1; }
    .custom-option          { padding: 10px 12px; font-size: 13px; color: #0f172a; cursor: pointer; transition: background 0.1s; border-bottom: 1px solid #f1f5f9; }
    .custom-option:last-child { border-bottom: none; }
    .custom-option:hover    { background: #f1f5f9; }
    .custom-option.selected { background: #eff6ff; color: #2563eb; font-weight: 500; }
    .custom-option.no-results { text-align: center; color: #475467; cursor: default; }

    .provider-btn       { border: 1px solid #e4e7ec; border-radius: 8px; padding: 12px; text-align: center; font-weight: 600; color: #475467; cursor: pointer; transition: all 0.2s; background: #fff; }
    .provider-btn:hover { background: #f8fafc; }
    .provider-btn.active{ border-color: #2563eb; background: #eff6ff; color: #2563eb; box-shadow: 0 0 0 1px #2563eb; }

    @media (max-width: 768px) {
        .modal-grid { grid-template-columns: 1fr; }
        .modal-footer { flex-direction: column; gap: 10px; }
        .modal-footer form { flex-direction: column; align-items: stretch; }
        .modal-footer .btn { width: 100%; }
    }
</style>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h3>Visi užsakymai</h3>
        <span class="muted" style="font-size:13px;">Viso: <?php echo count($allOrders); ?></span>
    </div>

    <div style="overflow-x:auto;">
        <table style="width:100%; min-width:800px;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Klientas</th>
                    <th>Data</th>
                    <th>Suma</th>
                    <th>Statusas</th>
                    <th style="text-align:right;">Veiksmai</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($allOrders as $order):
                $statusClass = 'status-' . str_replace(' ', '.', mb_strtolower($order['status']));
                $isPhysical  = in_array($order['delivery_method'], ['locker', 'courier']);
                $hasPaysera  = !empty($order['paysera_shipment_id']);
            ?>
                <tr>
                    <td><strong>#<?php echo (int)$order['id']; ?></strong></td>
                    <td>
                        <div style="font-weight:600;"><?php echo htmlspecialchars($order['customer_name']); ?></div>
                        <div class="muted" style="font-size:12px;"><?php echo htmlspecialchars($order['customer_email']); ?></div>
                        <?php if (!empty($order['customer_phone'])): ?>
                            <div class="muted" style="font-size:11px; color:#666;">Tel: <?php echo htmlspecialchars($order['customer_phone']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('Y-m-d H:i', strtotime($order['created_at'])); ?></td>
                    <td><strong><?php echo number_format((float)$order['total'], 2); ?> €</strong></td>
                    <td>
                        <span class="status-badge <?php echo $statusClass; ?>">
                            <?php echo ucfirst($order['status']); ?>
                        </span>
                        <?php if ($hasPaysera): ?>
                            <div style="font-size:11px; color:#15803d; font-weight:bold; margin-top:4px;">
                                📦 <?php echo htmlspecialchars($order['tracking_number']); ?>
                            </div>
                        <?php elseif (!empty($order['tracking_number'])): ?>
                            <div style="font-size:10px; color:#666; margin-top:2px;">📦 <?php echo htmlspecialchars($order['tracking_number']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <?php if ($isPhysical && !$hasPaysera): ?>
                            <button class="btn-shipment open-paysera-modal" type="button"
                                    data-order-id="<?php echo $order['id']; ?>"
                                    data-receiver-locker="<?php
                                        $dd   = json_decode($order['delivery_details'], true);
                                        $name = $dd['locker_name'] ?? 'Kurjeris/Nenurodyta';
                                        echo htmlspecialchars($name);
                                    ?>"
                                    style="margin-bottom:5px;">
                                Kurti Paysera siuntą
                            </button><br>
                        <?php elseif ($hasPaysera && !empty($order['paysera_label_url'])): ?>
                            <a href="<?php echo htmlspecialchars($order['paysera_label_url']); ?>" target="_blank" class="btn-label" style="margin-bottom:5px;">
                                Lipdukas (PDF)
                            </a><br>
                        <?php endif; ?>

                        <button class="btn secondary open-order-modal"
                                type="button"
                                data-order='<?php echo htmlspecialchars(json_encode($order, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>'>
                            Peržiūrėti
                        </button>

                        <form method="POST" onsubmit="return confirm('Ar tikrai norite negrįžtamai ištrinti šį užsakymą?');" style="display:inline;">
                            <?php if (function_exists('csrfField')) echo csrfField(); ?>
                            <input type="hidden" name="delete_id" value="<?php echo $order['id']; ?>">
                            <button type="submit" class="btn-delete" title="Ištrinti užsakymą">X</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$allOrders): ?>
                <tr><td colspan="6" class="muted" style="text-align:center; padding:20px;">Užsakymų nerasta.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===== UŽSAKYMO PERŽIŪROS MODALAS ===== -->
<div id="orderModal" class="modal-overlay">
    <div class="modal-window">
        <div class="modal-header">
            <h3 class="modal-title">Užsakymas #<span id="m_orderId"></span></h3>
            <div style="margin-left:10px; font-size:12px; color:#888;">
                Sukurtas: <span id="m_createdAt"></span>
            </div>
            <button type="button" class="modal-close" onclick="closeModal()" style="margin-left:auto;">&times;</button>
        </div>

        <form method="post" style="display:contents;">
            <div class="modal-body">
                <div class="modal-grid">

                    <div style="display:flex; flex-direction:column; gap:20px;">
                        <div class="info-section">
                            <div class="info-group">
                                <h4>Pirkėjo informacija</h4>
                                <div class="info-row"><label>Vardas:</label>    <span id="m_customerName"></span></div>
                                <div class="info-row"><label>El. paštas:</label><span id="m_customerEmail"></span></div>
                                <div class="info-row"><label>Telefonas:</label> <span id="m_customerPhone"></span></div>
                                <div class="info-row"><label>User ID:</label>   <span id="m_userId"></span></div>
                            </div>
                        </div>

                        <div class="info-section">
                            <div class="info-group">
                                <h4>Pristatymo duomenys</h4>
                                <div class="info-row"><label>Būdas:</label>  <span id="m_deliveryMethod"></span></div>
                                <div class="info-row"><label>Adresas:</label><span id="m_address"></span></div>

                                <div style="margin-top:10px; font-size:12px; background:#fff; padding:8px; border:1px dashed #ccc; display:none;" id="m_deliveryDetailsBox">
                                    <strong style="display:block; margin-bottom:4px;">Papildoma info (JSON):</strong>
                                    <span id="m_deliveryDetailsText" style="word-break:break-all; color:#555;"></span>
                                </div>

                                <div style="margin-top:15px; padding-top:10px; border-top:1px solid #eee;">
                                    <label style="font-size:12px; font-weight:700; color:#444; display:block; margin-bottom:4px;">Siuntos sekimo numeris:</label>
                                    <input type="text" name="tracking_number" id="m_trackingInput" class="form-control" placeholder="Įveskite kodą...">
                                    <div style="font-size:11px; color:#888; margin-top:2px;">Atnaujinus į "Išsiųsta", kodas bus išsiųstas klientui.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="display:flex; flex-direction:column; gap:20px;">
                        <div class="info-section">
                            <div class="info-group">
                                <h4>Finansinė informacija</h4>
                                <div class="info-row"><label>Nuolaidos kodas:</label> <span id="m_discountCode"></span></div>
                                <div class="info-row"><label>Nuolaidos suma:</label>  <span id="m_discountAmount"></span></div>
                                <div class="info-row"><label>Pristatymo kaina:</label><span id="m_shippingAmount"></span></div>
                                <div class="info-row" style="font-size:16px; border-top:1px solid #ddd; padding-top:5px; margin-top:5px;">
                                    <label>VISO:</label><strong id="m_total" style="color:#000;"></strong>
                                </div>
                                <div style="margin-top:10px; font-size:11px; color:#666;">
                                    Stripe Session ID:<br>
                                    <span id="m_stripeSession" style="font-family:monospace; word-break:break-all;"></span>
                                </div>
                            </div>
                        </div>

                        <div class="info-section">
                            <div class="info-group">
                                <h4>Sistemos būsenos (Email & Logs)</h4>
                                <div class="info-row"><label>Atnaujinta:</label>           <span id="m_updatedAt"></span></div>
                                <div class="info-row"><label>Laiškas (Thank You):</label>  <span id="m_emailThankYou"></span></div>
                                <div class="info-row"><label>Laiškas (Išsiųsta):</label>   <span id="m_emailShipped"></span></div>
                                <div class="info-row"><label>Laiškas (Review):</label>     <span id="m_emailReview"></span></div>
                                <div class="info-row"><label>Prim. (1h):</label>           <span id="m_emailRem1h"></span></div>
                                <div class="info-row"><label>Prim. (24h):</label>          <span id="m_emailRem24h"></span></div>
                            </div>
                        </div>
                    </div>

                </div>

                <h4 style="margin-bottom:10px; color:#666; font-size:13px; text-transform:uppercase;">Užsakytos prekės</h4>
                <div id="m_itemsList" class="item-list"></div>
            </div>

            <div class="modal-footer">
                <?php if (function_exists('csrfField')) echo csrfField(); ?>
                <input type="hidden" name="action" value="order_status">
                <input type="hidden" name="order_id" id="m_formOrderId">

                <div style="display:flex; gap:8px; align-items:center; flex:1;">
                    <label style="font-weight:600; font-size:14px;">Būsena:</label>
                    <select name="status" id="m_statusSelect" style="margin:0; width:auto; flex:1; max-width:200px;">
                        <option value="laukiama apmokėjimo">Laukiama apmokėjimo</option>
                        <option value="apmokėta">Apmokėta</option>
                        <option value="apdorojama">Apdorojama</option>
                        <option value="išsiųsta">Išsiųsta</option>
                        <option value="įvykdyta">Įvykdyta</option>
                        <option value="atšaukta">Atšaukta</option>
                        <option value="atmesta">Atmesta</option>
                    </select>
                    <button class="btn" type="submit">Atnaujinti</button>
                </div>
                <button type="button" class="btn secondary" onclick="closeModal()">Uždaryti</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== PAYSERA SIUNTOS KŪRIMO MODALAS ===== -->
<div id="payseraModal" class="modal-overlay">
    <div class="modal-window" style="max-width:600px;">
        <div class="modal-header">
            <h3 class="modal-title">Kurti Paysera siuntą</h3>
            <button type="button" class="modal-close" onclick="closePayseraModal()">&times;</button>
        </div>
        <form method="POST">
            <?php if (function_exists('csrfField')) echo csrfField(); ?>
            <input type="hidden" name="create_paysera_shipment" value="1">
            <input type="hidden" name="order_id" id="p_orderId">

            <div class="modal-body">
                <div style="background:#eff6ff; padding:12px; border-radius:8px; margin-bottom:15px; font-size:13px; color:#1e40af; border:1px solid #bfdbfe;">
                    <strong style="display:block; margin-bottom:4px;">Gavėjo paštomatas (Pirkėjo pasirinkimas):</strong>
                    <span id="p_receiverLocker" style="font-weight:600;"></span>
                    <div style="font-size:11px; margin-top:2px;">(Jau automatiškai priskirtas ir bus išsiųstas kurjeriui)</div>
                </div>

                <p style="font-size:14px; margin-bottom:10px; color:#555;">Pasirinkite <strong>savo (siuntėjo)</strong> paštomatą, iš kurio išsiųsite prekes klientui (Dydis M, 1kg):</p>

                <label class="form-label" style="margin-bottom:10px;">1. Pasirinkite tiekėją:</label>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:20px;">
                    <div class="provider-btn" onclick="filterLockers('lp_express', this)">LP EXPRESS</div>
                    <div class="provider-btn" onclick="filterLockers('omniva', this)">OMNIVA</div>
                </div>

                <div id="locker-selection-area" style="display:none;">
                    <label class="form-label">2. Pasirinkite paštomatą:</label>
                    <input type="hidden" name="sender_locker_id" id="locker-select-input" required>

                    <div class="custom-select-wrapper" id="custom-select-wrapper">
                        <div class="custom-select-trigger" onclick="toggleDropdown()">
                            <span id="selected-locker-text">-- Pasirinkite iš sąrašo --</span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </div>
                        <div class="custom-options-container" id="custom-options-container">
                            <div class="sticky-search">
                                <input type="text" id="locker-search-input" placeholder="Rašykite miestą arba adresą..." onkeyup="filterCustomOptions()" autocomplete="off">
                            </div>
                            <div class="options-list" id="options-list"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer" style="justify-content:flex-end; gap:10px;">
                <button type="button" class="btn secondary" onclick="closePayseraModal()">Atšaukti</button>
                <button type="submit" class="btn" style="background:#2563eb; color:#fff;">Patvirtinti ir sukurti</button>
            </div>
        </form>
    </div>
</div>

<script>
// ===== UŽSAKYMO MODALAS =====
const modal = document.getElementById('orderModal');

function boolBadge(val) {
    return val == 1
        ? '<span class="badge-bool badge-yes">TAIP</span>'
        : '<span class="badge-bool badge-no">NE</span>';
}

document.querySelectorAll('.open-order-modal').forEach(btn => {
    btn.addEventListener('click', function () {
        let data = {};
        try { data = JSON.parse(this.getAttribute('data-order')); }
        catch (e) { console.error('JSON Error', e); return; }

        document.getElementById('m_orderId').innerText    = data.id;
        document.getElementById('m_createdAt').innerText  = data.created_at;
        document.getElementById('m_formOrderId').value    = data.id;

        document.getElementById('m_customerName').innerText  = data.customer_name;
        document.getElementById('m_customerEmail').innerText = data.customer_email;
        document.getElementById('m_customerPhone').innerText = data.customer_phone || '-';
        document.getElementById('m_userId').innerText        = data.user_id ? ('#' + data.user_id) : 'Svečias';

        const methodMap = { courier: 'Kurjeris', locker: 'Paštomatas', pickup: 'Atsiėmimas' };
        document.getElementById('m_deliveryMethod').innerText = methodMap[data.delivery_method] || data.delivery_method;
        document.getElementById('m_address').innerText        = data.customer_address || '-';
        document.getElementById('m_trackingInput').value      = data.tracking_number  || '';

        const detailsBox  = document.getElementById('m_deliveryDetailsBox');
        const detailsText = document.getElementById('m_deliveryDetailsText');
        if (data.delivery_details) {
            detailsBox.style.display = 'block';
            try { detailsText.innerText = JSON.stringify(JSON.parse(data.delivery_details), null, 2); }
            catch (e) { detailsText.innerText = data.delivery_details; }
        } else {
            detailsBox.style.display = 'none';
        }

        document.getElementById('m_discountCode').innerText   = data.discount_code  || '-';
        document.getElementById('m_discountAmount').innerText = parseFloat(data.discount_amount  || 0).toFixed(2) + ' €';
        document.getElementById('m_shippingAmount').innerText = parseFloat(data.shipping_amount  || 0).toFixed(2) + ' €';
        document.getElementById('m_total').innerText          = parseFloat(data.total            || 0).toFixed(2) + ' €';
        document.getElementById('m_stripeSession').innerText  = data.stripe_session_id || '-';

        document.getElementById('m_updatedAt').innerText         = data.updated_at || '-';
        document.getElementById('m_emailThankYou').innerHTML     = boolBadge(data.email_thankyou);
        document.getElementById('m_emailShipped').innerHTML      = boolBadge(data.email_shipped_sent);
        document.getElementById('m_emailReview').innerHTML       = boolBadge(data.email_review_sent);
        document.getElementById('m_emailRem1h').innerHTML        = boolBadge(data.email_rem_1h);
        document.getElementById('m_emailRem24h').innerHTML       = boolBadge(data.email_rem_24h);

        document.getElementById('m_statusSelect').value = data.status;

        const itemsContainer = document.getElementById('m_itemsList');
        itemsContainer.innerHTML = '';
        if (data.items && data.items.length > 0) {
            data.items.forEach(item => {
                const total  = (item.price * item.quantity).toFixed(2);
                const imgUrl = item.image_url || 'https://placehold.co/100?text=Foto';
                const varInfo = item.variation_info
                    ? `<div style="font-size:12px;color:#2563eb;margin-top:2px;">${item.variation_info}</div>` : '';
                itemsContainer.insertAdjacentHTML('beforeend', `
                    <div class="item-row">
                        <img src="${imgUrl}" class="item-img" alt="">
                        <div class="item-details">
                            <div class="item-title">${item.title || 'Prekė ištrinta'}</div>
                            ${varInfo}
                            <div class="item-meta">Kiekis: ${item.quantity} vnt.</div>
                        </div>
                        <div class="item-price">${total} €</div>
                    </div>`);
            });
        } else {
            itemsContainer.innerHTML = '<div style="padding:12px;text-align:center;color:#999;">Prekių sąrašas tuščias</div>';
        }

        modal.style.display = 'flex';
        setTimeout(() => modal.classList.add('open'), 10);
    });
});

function closeModal() {
    modal.classList.remove('open');
    setTimeout(() => { modal.style.display = 'none'; }, 200);
}
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });


// ===== PAYSERA MODALAS =====
const payseraModal = document.getElementById('payseraModal');
let currentProvider       = '';
let currentFilteredLockers = [];
const allLockers = <?php echo json_encode($lockersForJs); ?>;

document.querySelectorAll('.open-paysera-modal').forEach(btn => {
    btn.addEventListener('click', function () {
        document.getElementById('p_orderId').value             = this.getAttribute('data-order-id');
        document.getElementById('p_receiverLocker').textContent = this.getAttribute('data-receiver-locker');

        currentProvider = '';
        document.querySelectorAll('.provider-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('locker-selection-area').style.display = 'none';
        document.getElementById('selected-locker-text').textContent    = '-- Pasirinkite iš sąrašo --';
        document.getElementById('locker-select-input').value           = '';
        document.getElementById('locker-search-input').value           = '';

        payseraModal.style.display = 'flex';
        setTimeout(() => payseraModal.classList.add('open'), 10);
    });
});

function closePayseraModal() {
    payseraModal.classList.remove('open');
    setTimeout(() => { payseraModal.style.display = 'none'; }, 200);
}
payseraModal.addEventListener('click', e => { if (e.target === payseraModal) closePayseraModal(); });

function filterLockers(provider, btnElement) {
    currentProvider = provider;
    document.querySelectorAll('.provider-btn').forEach(b => b.classList.remove('active'));
    if (btnElement) btnElement.classList.add('active');

    document.getElementById('locker-selection-area').style.display = 'block';
    document.getElementById('selected-locker-text').textContent    = '-- Pasirinkite paštomatą --';
    document.getElementById('locker-select-input').value           = '';
    document.getElementById('locker-search-input').value           = '';

    generateCustomOptions(provider);
}

function generateCustomOptions(provider) {
    const list = document.getElementById('options-list');
    list.innerHTML = '';

    currentFilteredLockers = allLockers.filter(l => l.type === provider);

    if (currentFilteredLockers.length === 0) {
        const div = document.createElement('div');
        div.className   = 'custom-option no-results';
        div.textContent = 'Šio tiekėjo paštomatų nerasta.';
        list.appendChild(div);
        return;
    }
    currentFilteredLockers.forEach(locker => createOptionElement(locker));
}

function createOptionElement(locker) {
    const list = document.getElementById('options-list');
    const div  = document.createElement('div');
    div.className   = 'custom-option';
    div.textContent = locker.full;
    div.dataset.value = locker.terminal_id;
    div.onclick = () => selectOption(locker.terminal_id, locker.full);
    list.appendChild(div);
}

function toggleDropdown() {
    const wrapper = document.getElementById('custom-select-wrapper');
    wrapper.classList.toggle('open');
    if (wrapper.classList.contains('open')) {
        setTimeout(() => document.getElementById('locker-search-input').focus(), 100);
    }
}

function selectOption(terminalId, fullName) {
    document.getElementById('locker-select-input').value      = terminalId;
    document.getElementById('selected-locker-text').textContent = fullName;
    document.getElementById('custom-select-wrapper').classList.remove('open');
}

function filterCustomOptions() {
    const term = document.getElementById('locker-search-input').value.toLowerCase();
    const list = document.getElementById('options-list');
    list.innerHTML = '';

    const filtered = currentFilteredLockers.filter(l => l.full.toLowerCase().includes(term));
    if (filtered.length === 0) {
        const div = document.createElement('div');
        div.className   = 'custom-option no-results';
        div.textContent = 'Nerasta atitikmenų';
        list.appendChild(div);
    } else {
        filtered.forEach(locker => createOptionElement(locker));
    }
}

document.addEventListener('click', function (e) {
    const wrapper = document.getElementById('custom-select-wrapper');
    if (wrapper && !wrapper.contains(e.target)) wrapper.classList.remove('open');
});
</script>
