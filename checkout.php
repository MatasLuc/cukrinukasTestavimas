<?php
// Įjungiame klaidų rodymą
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// 1. Generuojame CSRF tokeną apsaugai
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/shipping_helper.php';
require_once __DIR__ . '/helpers.php'; 

$pdo = getPdo();

// 2. Patikriname, ar vartotojas prisijungęs
if (empty($_SESSION['user_id'])) {
    $_SESSION['redirect_after_login'] = 'checkout.php';
    header('Location: /login.php');
    exit;
}

// 3. Patikriname, ar krepšelis nėra tuščias
if (empty($_SESSION['cart']) || count($_SESSION['cart']) === 0) {
    header('Location: /products.php');
    exit;
}

// --- PAGALBINĖS FUNKCIJOS NUOLAIDOMS ---

function checkDiscountCode($pdo, $code, $cartTotal) {
    if (empty($code)) return ['valid' => false, 'error' => 'Įveskite kodą.'];

    try {
        $stmt = $pdo->prepare("SELECT * FROM discounts WHERE code = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$code]);
        $discount = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$discount) {
            return ['valid' => false, 'error' => 'Nuolaidos kodas nerastas arba negalioja.'];
        }

        // Tikriname datas
        $now = new DateTime();
        if (!empty($discount['valid_from']) && new DateTime($discount['valid_from']) > $now) {
            return ['valid' => false, 'error' => 'Šis kodas dar negalioja.'];
        }
        if (!empty($discount['valid_until']) && new DateTime($discount['valid_until']) < $now) {
            return ['valid' => false, 'error' => 'Šio kodo galiojimas pasibaigęs.'];
        }

        // Tikriname limitus
        if (isset($discount['usage_limit']) && $discount['usage_limit'] > 0) {
            $used = $discount['used_count'] ?? 0;
            if ($used >= $discount['usage_limit']) {
                return ['valid' => false, 'error' => 'Kodo panaudojimo limitas pasiektas.'];
            }
        }

        // Tikriname minimalią krepšelio sumą
        if (!empty($discount['min_order_amount']) && $cartTotal < $discount['min_order_amount']) {
            return ['valid' => false, 'error' => 'Minimali krepšelio suma šiam kodui: ' . number_format($discount['min_order_amount'], 2) . ' €'];
        }

        // Paskaičiuojame nuolaidos vertę
        $discountValue = 0;
        if ($discount['type'] === 'percent') {
            $discountValue = round(($cartTotal * ($discount['value'] / 100)), 2);
        } else {
            $discountValue = (float)$discount['value'];
        }

        if ($discountValue > $cartTotal) {
            $discountValue = $cartTotal;
        }

        return [
            'valid' => true,
            'data' => $discount,
            'calculated_value' => $discountValue
        ];

    } catch (Exception $e) {
        return ['valid' => false, 'error' => 'Klaida tikrinant nuolaidą.'];
    }
}

// 4. Skaičiuojame krepšelio sumą
$cartItemsTotal = 0;
$productsInCart = [];
$ids = array_keys($_SESSION['cart']);
$hasFreeShippingProduct = false; 

$fsProducts = getFreeShippingProducts($pdo);
$fsIds = array_column($fsProducts, 'product_id');

if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, title, price FROM products WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($products as $p) {
        $qty = $_SESSION['cart'][$p['id']];
        $cartItemsTotal += $p['price'] * $qty;
        
        if (in_array($p['id'], $fsIds)) {
            $hasFreeShippingProduct = true;
        }

        $productsInCart[] = [
            'id' => $p['id'],
            'price' => $p['price'],
            'qty' => $qty
        ];
    }
}

// 5. NUOLAIDOS KODO APDOROJIMAS
$discountError = '';
$discountSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['apply_discount_code'])) {
        $code = trim($_POST['discount_code'] ?? '');
        $result = checkDiscountCode($pdo, $code, $cartItemsTotal);
        
        if ($result['valid']) {
            $_SESSION['applied_discount'] = [
                'code' => $result['data']['code'],
                'value' => $result['calculated_value'],
                'type' => $result['data']['type'],
                'raw_value' => $result['data']['value'],
                'id' => $result['data']['id']
            ];
            $discountSuccess = 'Nuolaida pritaikyta!';
        } else {
            $discountError = $result['error'];
            unset($_SESSION['applied_discount']);
        }
    }
    elseif (isset($_POST['remove_discount'])) {
        unset($_SESSION['applied_discount']);
        $discountSuccess = 'Nuolaida pašalinta.';
    }
}

// 6. PERSKAIČIUOJAME GALUTINĘ SUMĄ SU NUOLAIDA
$discountAmount = 0;
$activeDiscountCode = '';

if (isset($_SESSION['applied_discount'])) {
    $check = checkDiscountCode($pdo, $_SESSION['applied_discount']['code'], $cartItemsTotal);
    if ($check['valid']) {
        $discountAmount = $check['calculated_value'];
        $activeDiscountCode = $_SESSION['applied_discount']['code'];
        $_SESSION['applied_discount']['value'] = $discountAmount;
    } else {
        unset($_SESSION['applied_discount']);
        $discountError = "Nuolaida pašalinta: " . $check['error'];
    }
}

$totalAfterDiscount = max(0, $cartItemsTotal - $discountAmount);

// 7. Gauname pristatymo nustatymus
$shippingSettings = getShippingSettings($pdo);

// 8. GAUNAME PAŠTOMATUS IR PARUOŠIAME JS
$lockersForJs = [];
try {
    // Svarbu: imame provider stulpelį
    $stmtLockers = $pdo->query("SELECT * FROM parcel_lockers ORDER BY city ASC, title ASC");
    $allLockers = $stmtLockers->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($allLockers as $l) {
        $lockersForJs[] = [
            'title' => $l['title'],
            'address' => $l['address'],
            'city' => $l['city'],
            // Normalizuojame provider reikšmę (pvz., 'omniva', 'lpexpress')
            'type' => strtolower($l['provider'] ?? 'other'), 
            'full' => $l['city'] . ' - ' . $l['title'] . ' (' . $l['address'] . ')'
        ];
    }
} catch (Exception $e) {
    error_log("Klaida gaunant paštomatus: " . $e->getMessage());
}

// 9. UŽSAKYMO PATEIKIMAS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $errors = [];
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Saugumo klaida. Pabandykite perkrauti puslapį.';
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    
    $method = $_POST['delivery_method'] ?? 'locker';
    $notes = trim($_POST['notes'] ?? '');
    $selectedLocker = trim($_POST['locker_select'] ?? '');

    // ADRESO APDOROJIMAS
    $address = "";
    if ($method === 'courier') {
        $city = trim($_POST['city'] ?? '');
        $street = trim($_POST['street'] ?? '');
        $house = trim($_POST['house'] ?? '');
        $flat = trim($_POST['flat'] ?? '');
        $zip = trim($_POST['zip'] ?? '');

        // Validacija
        if (empty($city)) $errors[] = 'Įveskite miestą.';
        if (empty($street)) $errors[] = 'Įveskite gatvę.';
        if (empty($house)) $errors[] = 'Įveskite namo numerį.';
        if (empty($zip)) $errors[] = 'Įveskite pašto kodą.';

        // Sujungiame į vieną eilutę
        $address = "$street g. $house" . (!empty($flat) ? "-$flat" : "") . ", $city, LT-$zip";
    }

    // Validacija
    if (empty($name)) $errors[] = 'Būtina įvesti vardą.';
    if (empty($phone)) $errors[] = 'Būtina įvesti telefoną.';
    if ($method === 'locker' && empty($selectedLocker)) $errors[] = 'Būtina pasirinkti paštomatą.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $shippingPrice = calculateShippingPrice($shippingSettings, $totalAfterDiscount, $method, $hasFreeShippingProduct);
            $finalTotal = $totalAfterDiscount + $shippingPrice;

            $deliveryDetailsData = [
                'method' => $method,
                'shipping_price' => $shippingPrice,
                'phone' => $phone,
                'email' => $email,
                'notes' => $notes,
                'items_total' => $cartItemsTotal,
                'discount_code' => $activeDiscountCode,
                'discount_amount' => $discountAmount
            ];

            if ($method === 'locker') {
                $deliveryDetailsData['locker_address'] = $selectedLocker;
                $address = "Paštomatas: " . $selectedLocker;
            }

            $deliveryDetails = json_encode($deliveryDetailsData);

            $stmtOrder = $pdo->prepare("
                INSERT INTO orders 
                (user_id, total, status, created_at, customer_name, customer_address, delivery_method, delivery_details, customer_email) 
                VALUES (?, ?, 'Laukiama apmokėjimo', NOW(), ?, ?, ?, ?, ?)
            ");
            
            $stmtOrder->execute([
                $_SESSION['user_id'],
                $finalTotal,
                $name,
                $address,
                $method,
                $deliveryDetails,
                $email
            ]);
            
            $orderId = $pdo->lastInsertId();

            $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
            foreach ($productsInCart as $item) {
                $stmtItem->execute([$orderId, $item['id'], $item['qty'], $item['price']]);
            }

            if (!empty($activeDiscountCode)) {
                $stmtUpdateDiscount = $pdo->prepare("UPDATE discounts SET used_count = used_count + 1 WHERE code = ?");
                $stmtUpdateDiscount->execute([$activeDiscountCode]);
            }

            $pdo->commit();
            
            unset($_SESSION['cart']);
            unset($_SESSION['applied_discount']);

            header("Location: /stripe_checkout.php?order_id=" . $orderId);
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = "Klaida apdorojant užsakymą: " . $e->getMessage();
        }
    }
}

// User info autofill
$userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$userStmt->execute([$_SESSION['user_id']]);
$user = $userStmt->fetch();

// UI Calculation
$courierCost = calculateShippingPrice($shippingSettings, $totalAfterDiscount, 'courier', $hasFreeShippingProduct);
$lockerCost = calculateShippingPrice($shippingSettings, $totalAfterDiscount, 'locker', $hasFreeShippingProduct);
$isFree = ($hasFreeShippingProduct || ($shippingSettings['free_over'] > 0 && $totalAfterDiscount >= $shippingSettings['free_over']));

?>
<!doctype html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Užsakymas | Cukrinukas.lt</title>
    <?php echo headerStyles(); ?>
    <style>
         :root {
            --bg: #f7f7fb;
            --card: #ffffff;
            --border: #e4e7ec;
            --text-main: #0f172a;
            --text-muted: #475467;
            --accent: #2563eb;
            --accent-hover: #1d4ed8;
            --success: #10b981;
            --danger: #ef4444;
        }
        body { margin:0; background: var(--bg); color: var(--text-main); font-family: 'Inter', sans-serif; }
        
        .page { max-width: 1200px; margin:0 auto; padding:32px 20px 72px; }
        .page-title { margin: 0 0 24px 0; font-size: 24px; font-weight: 700; color: var(--text-main); }
        
        /* Grid Layout */
        .layout { display:grid; grid-template-columns: 1fr 380px; gap:24px; align-items:start; }
        @media(max-width: 900px){ .layout { grid-template-columns:1fr; } }

        /* Cards */
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card h3 { margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: var(--text-main); display: flex; align-items: center; gap: 8px; }
        .card h3 svg { color: var(--accent); }

        /* Promo Banner - NEW STYLE (About.php Hero) */
        .promo-banner {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border: 1px solid #dbeafe;
            border-radius: 20px;
            padding: 32px;
            margin-bottom: 32px;
            text-align: left;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
        }
        .promo-content { max-width: 600px; flex: 1; }
        .promo-banner h2 { margin: 0 0 12px 0; font-size: 24px; font-weight: 700; color: #1e3a8a; letter-spacing: -0.5px; }
        .promo-banner p { margin: 0; color: #1e40af; line-height: 1.6; font-size: 15px; }
        
        .pill { 
            display:inline-flex; align-items:center; gap:8px; 
            padding:6px 12px; border-radius:999px; 
            background:#fff; border:1px solid #bfdbfe; 
            font-weight:600; font-size:13px; color:#1e40af; 
            margin-bottom: 12px;
        }

        .promo-code-box {
            display: inline-block;
            background: #ffffff;
            padding: 10px 18px;
            border-radius: 10px;
            font-weight: 700;
            color: #1e40af;
            border: 1px dashed #3b82f6;
            letter-spacing: 1px;
            font-size: 14px;
            white-space: nowrap;
        }

        /* Forms */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-grid-3 { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 16px; } 
        @media(max-width: 600px) { .form-grid-3 { grid-template-columns: 1fr; } }

        .form-group { margin-bottom: 16px; }
        .form-group:last-child { margin-bottom: 0; }
        .form-label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: var(--text-muted); }
        .form-control { 
            width: 100%; padding: 10px 12px; 
            border: 1px solid var(--border); border-radius: 8px; 
            font-size: 14px; color: var(--text-main);
            transition: all 0.2s;
            box-sizing: border-box;
            background: #fff;
        }
        .form-control:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }
        textarea.form-control { resize: vertical; min-height: 80px; }

        /* Shipping & Locker Providers */
        .shipping-options { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; }
        @media(max-width: 600px) { .shipping-options { grid-template-columns: 1fr; } }

        .radio-card { 
            position: relative;
            border: 1px solid var(--border); 
            border-radius: 10px; 
            padding: 16px; 
            cursor: pointer; 
            transition: all 0.2s; 
            display: flex; 
            flex-direction: column;
            gap: 8px;
            align-items: flex-start;
        }
        .radio-card:hover { background: #f8fafc; border-color: #cbd5e1; }
        .radio-card.active { border-color: var(--accent); background: #eff6ff; box-shadow: 0 0 0 1px var(--accent); }
        .radio-card input { position: absolute; opacity: 0; cursor: pointer; height: 0; width: 0; }
        
        .radio-header { display: flex; justify-content: space-between; width: 100%; align-items: center; }
        .radio-circle { 
            width: 18px; height: 18px; border-radius: 50%; border: 2px solid #cbd5e1; 
            display: flex; align-items: center; justify-content: center; margin-right: 10px;
        }
        .radio-card.active .radio-circle { border-color: var(--accent); }
        .radio-card.active .radio-circle::after { content: ''; width: 8px; height: 8px; background: var(--accent); border-radius: 50%; }
        
        .radio-label { font-weight: 600; font-size: 15px; display: flex; align-items: center; }
        .radio-price { font-weight: 600; font-size: 14px; color: var(--text-main); background: #fff; padding: 4px 8px; border-radius: 6px; border: 1px solid var(--border); }
        .radio-price.free { color: var(--success); border-color: #bbf7d0; background: #f0fdf4; }

        /* Provider Selection Buttons */
        .provider-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
        .provider-btn {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px;
            text-align: center;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
            background: #fff;
        }
        .provider-btn:hover { background: #f8fafc; }
        .provider-btn.active {
            border-color: var(--accent);
            background: #eff6ff;
            color: var(--accent);
            box-shadow: 0 0 0 1px var(--accent);
        }

        /* CUSTOM SELECT - SEARCHABLE DROPDOWN */
        .custom-select-wrapper { position: relative; user-select: none; width: 100%; }
        .custom-select-trigger {
            position: relative;
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 12px;
            font-size: 14px; font-weight: 400; color: var(--text-main);
            background: #fff; border: 1px solid var(--border); border-radius: 8px;
            cursor: pointer; transition: all 0.2s;
        }
        .custom-select-trigger:hover { border-color: #cbd5e1; }
        .custom-select-wrapper.open .custom-select-trigger { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }
        .custom-select-trigger span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        
        .custom-options-container {
            position: absolute; display: none; top: 100%; left: 0; right: 0;
            background: #fff; border: 1px solid var(--border); border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            z-index: 100; margin-top: 4px;
            max-height: 300px; overflow: hidden;
            flex-direction: column;
        }
        .custom-select-wrapper.open .custom-options-container { display: flex; }
        
        .sticky-search { padding: 8px; background: #fff; border-bottom: 1px solid var(--border); }
        .sticky-search input {
            width: 100%; padding: 8px;
            border: 1px solid var(--border); border-radius: 6px;
            font-size: 13px; box-sizing: border-box;
        }
        .sticky-search input:focus { outline: none; border-color: var(--accent); }
        
        .options-list { overflow-y: auto; flex: 1; }
        .custom-option {
            padding: 10px 12px; font-size: 13px; color: var(--text-main);
            cursor: pointer; transition: background 0.1s;
            border-bottom: 1px solid #f1f5f9;
        }
        .custom-option:last-child { border-bottom: none; }
        .custom-option:hover { background: #f1f5f9; }
        .custom-option.selected { background: #eff6ff; color: var(--accent); font-weight: 500; }
        .custom-option.no-results { text-align: center; color: var(--text-muted); cursor: default; }

        /* Sidebar & Summary */
        .sidebar { position: sticky; top: 20px; }
        .summary-row { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 14px; color: var(--text-muted); }
        .summary-row.total { border-top: 1px dashed var(--border); padding-top: 16px; margin-top: 16px; font-weight: 700; font-size: 18px; color: var(--text-main); }
        .summary-row.discount { color: var(--success); font-weight: 500; }
        
        .btn-primary { 
            width: 100%; padding: 14px; 
            background: var(--accent); color: white; 
            border: none; border-radius: 10px; 
            font-size: 16px; font-weight: 600; 
            cursor: pointer; transition: 0.2s; 
            text-align: center;
        }
        .btn-primary:hover { background: var(--accent-hover); box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2); }
        
        /* Discount Form */
        .discount-form { display: flex; gap: 8px; margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border); }
        .btn-apply { padding: 0 16px; background: #0f172a; color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .active-discount { background: #ecfdf5; border: 1px solid #6ee7b7; padding: 10px; border-radius: 8px; font-size: 13px; color: #065f46; display: flex; justify-content: space-between; align-items: center; margin-top: 20px; }
        .btn-remove { background: none; border: none; color: var(--danger); font-size: 11px; font-weight: 600; cursor: pointer; text-decoration: underline; }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
        .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        
        .hidden-field { display: none; margin-top: 20px; padding-top: 20px; border-top: 1px dashed var(--border); }
    </style>
</head>
<body>
    <?php renderHeader($pdo, 'cart'); ?>

    <div class="page">
        
        <?php if (!empty($_SESSION['user_id'])): ?>
            <div class="promo-banner">
                <div class="promo-content">
                    <div class="pill">🎉 Jūs esate klubo narys</div>
                    <h2>AČIŪ, kad esate su mumis!</h2>
                    <p>Kaip registruotam nariui, dovanojame Jums išskirtinę <strong>5% nuolaidą</strong> šiam krepšeliui.</p>
                </div>
                <div class="promo-code-box">KODAS: ACIU</div>
            </div>
        <?php endif; ?>

        <h1 class="page-title">Užsakymo formavimas</h1>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php echo implode('<br>', $errors); ?>
            </div>
        <?php endif; ?>

        <?php if ($discountError): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($discountError); ?></div>
        <?php endif; ?>
        
        <?php if ($discountSuccess): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($discountSuccess); ?></div>
        <?php endif; ?>

        <div class="layout">
            <div class="main-content">
                <form id="checkout-form" method="POST">
                    <input type="hidden" name="place_order" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                    <div class="card">
                        <h3>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            Kontaktinė informacija
                        </h3>
                        <div class="form-group">
                            <label class="form-label">Vardas, Pavardė</label>
                            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" required>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">El. paštas</label>
                                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Telefonas</label>
                                <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h3>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 18 1 18 1 3"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
                            Pristatymas
                        </h3>
                        
                        <?php if($isFree): ?>
                            <div class="alert alert-success" style="padding: 10px; margin-bottom: 16px; font-weight: 500;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: text-bottom; margin-right: 5px;"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                Jums taikomas nemokamas pristatymas!
                            </div>
                        <?php elseif($shippingSettings['free_over'] > 0): ?>
                             <div class="alert alert-success" style="font-size:12px; margin-bottom: 16px; padding: 8px;">
                                Trūksta tik <strong><?php echo number_format($shippingSettings['free_over'] - $totalAfterDiscount, 2); ?> €</strong> iki nemokamo pristatymo.
                            </div>
                        <?php endif; ?>

                        <div class="shipping-options">
                            <label class="radio-card active" onclick="selectShipping(this, 'locker')">
                                <div class="radio-header">
                                    <span class="radio-label">
                                        <div class="radio-circle"></div>
                                        Paštomatas
                                    </span>
                                    <input type="radio" name="delivery_method" value="locker" checked>
                                    <span class="radio-price <?php echo $isFree ? 'free' : ''; ?>">
                                        <?php echo $isFree ? '0.00' : number_format($lockerCost, 2); ?> €
                                    </span>
                                </div>
                            </label>

                            <label class="radio-card" onclick="selectShipping(this, 'courier')">
                                <div class="radio-header">
                                    <span class="radio-label">
                                        <div class="radio-circle"></div>
                                        Kurjeris į namus
                                    </span>
                                    <input type="radio" name="delivery_method" value="courier">
                                    <span class="radio-price <?php echo $isFree ? 'free' : ''; ?>">
                                        <?php echo $isFree ? '0.00' : number_format($courierCost, 2); ?> €
                                    </span>
                                </div>
                            </label>
                        </div>

                        <div class="hidden-field" id="locker-field" style="display:block;">
                            <label class="form-label" style="margin-bottom:10px;">1. Pasirinkite tiekėją:</label>
                            
                            <div class="provider-grid">
                                <div class="provider-btn" onclick="filterLockers('lpexpress', this)">
                                    LP EXPRESS
                                </div>
                                <div class="provider-btn" onclick="filterLockers('omniva', this)">
                                    OMNIVA
                                </div>
                            </div>

                            <div id="locker-selection-area" style="display:none;">
                                <label class="form-label">2. Pasirinkite paštomatą:</label>
                                
                                <input type="hidden" name="locker_select" id="locker-select-input">
                                
                                <div class="custom-select-wrapper" id="custom-select-wrapper">
                                    <div class="custom-select-trigger" onclick="toggleDropdown()">
                                        <span id="selected-locker-text">-- Pasirinkite iš sąrašo --</span>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                    </div>
                                    <div class="custom-options-container" id="custom-options-container">
                                        <div class="sticky-search">
                                            <input type="text" id="locker-search-input" placeholder="Rašykite miestą arba adresą..." onkeyup="filterCustomOptions()" autocomplete="off">
                                        </div>
                                        <div class="options-list" id="options-list">
                                            </div>
                                    </div>
                                </div>
                                </div>
                        </div>

                        <div class="hidden-field" id="address-field">
                            <h4 style="margin: 0 0 16px 0; font-size:15px;">Pristatymo adresas</h4>
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Miestas</label>
                                    <input type="text" name="city" id="input-city" class="form-control" placeholder="pvz. Vilnius">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Pašto kodas</label>
                                    <input type="text" name="zip" id="input-zip" class="form-control" placeholder="pvz. 00000">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Gatvė</label>
                                <input type="text" name="street" id="input-street" class="form-control" placeholder="pvz. Gedimino pr.">
                            </div>

                            <div class="form-grid-3">
                                <div class="form-group" style="grid-column: span 1;">
                                    <label class="form-label">Namo nr.</label>
                                    <input type="text" name="house" id="input-house" class="form-control" placeholder="pvz. 1A">
                                </div>
                                <div class="form-group" style="grid-column: span 2;">
                                    <label class="form-label">Buto nr. (neprivaloma)</label>
                                    <input type="text" name="flat" class="form-control" placeholder="pvz. 15">
                                </div>
                            </div>
                        </div>

                    </div>

                    <div class="card">
                        <h3>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            Papildoma informacija
                        </h3>
                        <div class="form-group">
                            <label class="form-label">Pastabos kurjeriui arba mums (nebūtina)</label>
                            <textarea name="notes" class="form-control" placeholder="pvz. Durų kodas, palikti prie durų..."></textarea>
                        </div>
                    </div>

                </form>
            </div>

            <div class="sidebar">
                <div class="card">
                    <h3>Užsakymo santrauka</h3>
                    
                    <div class="summary-row">
                        <span>Prekių krepšelis</span>
                        <span><?php echo number_format($cartItemsTotal, 2); ?> €</span>
                    </div>

                    <?php if ($discountAmount > 0): ?>
                        <div class="summary-row discount">
                            <span>Nuolaida (<?php echo htmlspecialchars($activeDiscountCode); ?>)</span>
                            <span>-<?php echo number_format($discountAmount, 2); ?> €</span>
                        </div>
                    <?php endif; ?>

                    <div class="summary-row">
                        <span>Pristatymas</span>
                        <span id="shipping-display"><?php echo $isFree ? '0.00' : number_format($lockerCost, 2); ?> €</span>
                    </div>
                    
                    <div class="summary-row total">
                        <span>VISO MOKĖTI</span>
                        <span id="total-display"><?php echo number_format($totalAfterDiscount + ($isFree ? 0 : $lockerCost), 2); ?> €</span>
                    </div>

                    <button type="submit" form="checkout-form" class="btn-primary" style="margin-top: 24px;">
                        Apmokėti užsakymą
                    </button>
                    
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        
                        <?php if ($activeDiscountCode): ?>
                            <div class="active-discount">
                                <span>Pritaikytas: <strong><?php echo htmlspecialchars($activeDiscountCode); ?></strong></span>
                                <button type="submit" name="remove_discount" class="btn-remove">Pašalinti</button>
                            </div>
                        <?php else: ?>
                            <div class="discount-form">
                                <input type="text" name="discount_code" class="form-control" placeholder="Nuolaidos kodas" required>
                                <button type="submit" name="apply_discount_code" class="btn-apply">Taikyti</button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
                
                <div style="text-align: center; color: var(--text-muted); font-size: 12px; margin-top: 10px;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    Saugus atsiskaitymas per Stripe
                </div>
            </div>
        </div>
    </div>

    <?php renderFooter($pdo); ?>

    <script>
        // Data from PHP
        const totalAfterDiscount = <?php echo number_format($totalAfterDiscount, 2, '.', ''); ?>;
        const prices = {
            locker: <?php echo $isFree ? '0.00' : number_format($lockerCost, 2, '.', ''); ?>,
            courier: <?php echo $isFree ? '0.00' : number_format($courierCost, 2, '.', ''); ?>
        };
        const allLockers = <?php echo json_encode($lockersForJs); ?>;

        // UI State
        let currentProvider = '';
        let currentFilteredLockers = [];

        function selectShipping(element, method) {
            // Visual Update
            document.querySelectorAll('.radio-card').forEach(el => el.classList.remove('active'));
            element.classList.add('active');
            
            // Check hidden radio
            const radio = element.querySelector('input[type="radio"]');
            if(radio) radio.checked = true;

            updateUI(method);
        }

        function updateUI(method) {
            const addrField = document.getElementById('address-field');
            const lockerField = document.getElementById('locker-field');
            const shipDisplay = document.getElementById('shipping-display');
            const totalDisplay = document.getElementById('total-display');
            
            // Required inputs IDs
            const reqInputs = ['input-city', 'input-street', 'input-house', 'input-zip'];
            const lockerInput = document.getElementById('locker-select-input');

            // Reset visibility & requirements
            addrField.style.display = 'none';
            lockerField.style.display = 'none';
            lockerInput.removeAttribute('required');
            reqInputs.forEach(id => document.getElementById(id).removeAttribute('required'));

            // Logic
            if (method === 'courier') {
                addrField.style.display = 'block';
                reqInputs.forEach(id => document.getElementById(id).setAttribute('required', 'required'));
            } else if (method === 'locker') {
                lockerField.style.display = 'block';
                lockerInput.setAttribute('required', 'required');
            }

            // Price update
            const shipPrice = parseFloat(prices[method] || 0);
            const finalPrice = totalAfterDiscount + shipPrice;

            shipDisplay.textContent = shipPrice.toFixed(2) + ' €';
            totalDisplay.textContent = finalPrice.toFixed(2) + ' €';
        }

        // --- PAŠTOMATŲ LOGIKA (CUSTOM SELECT) ---

        function filterLockers(provider, btnElement) {
            currentProvider = provider;
            
            // Update buttons visual state
            document.querySelectorAll('.provider-btn').forEach(btn => btn.classList.remove('active'));
            if(btnElement) btnElement.classList.add('active');

            // Show selection area
            document.getElementById('locker-selection-area').style.display = 'block';

            // Reset selection
            document.getElementById('selected-locker-text').textContent = '-- Pasirinkite paštomatą --';
            document.getElementById('locker-select-input').value = '';
            document.getElementById('locker-search-input').value = '';

            // Generate list
            generateCustomOptions(provider);
        }

        function generateCustomOptions(provider) {
            const list = document.getElementById('options-list');
            list.innerHTML = '';
            
            // Filter by provider only
            currentFilteredLockers = allLockers.filter(l => l.type === provider);

            if(currentFilteredLockers.length === 0) {
                const div = document.createElement('div');
                div.className = 'custom-option no-results';
                div.textContent = 'Šio tiekėjo paštomatų nerasta.';
                list.appendChild(div);
                return;
            }

            currentFilteredLockers.forEach(locker => {
                createOptionElement(locker);
            });
        }

        function createOptionElement(locker) {
            const list = document.getElementById('options-list');
            const div = document.createElement('div');
            div.className = 'custom-option';
            div.textContent = locker.full;
            div.dataset.value = locker.full; // What gets sent to DB
            
            div.onclick = function() {
                selectOption(locker.full);
            };
            
            list.appendChild(div);
        }

        function toggleDropdown() {
            const wrapper = document.getElementById('custom-select-wrapper');
            wrapper.classList.toggle('open');
            
            // If opening, focus search
            if (wrapper.classList.contains('open')) {
                setTimeout(() => document.getElementById('locker-search-input').focus(), 100);
            }
        }

        function selectOption(value) {
            document.getElementById('locker-select-input').value = value;
            document.getElementById('selected-locker-text').textContent = value;
            document.getElementById('custom-select-wrapper').classList.remove('open');
        }

        function filterCustomOptions() {
            const term = document.getElementById('locker-search-input').value.toLowerCase();
            const list = document.getElementById('options-list');
            list.innerHTML = '';

            const filtered = currentFilteredLockers.filter(locker => 
                locker.full.toLowerCase().includes(term)
            );

            if (filtered.length === 0) {
                const div = document.createElement('div');
                div.className = 'custom-option no-results';
                div.textContent = 'Nerasta atitikmenų';
                list.appendChild(div);
            } else {
                filtered.forEach(locker => createOptionElement(locker));
            }
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const wrapper = document.getElementById('custom-select-wrapper');
            if (!wrapper.contains(e.target)) {
                wrapper.classList.remove('open');
            }
        });

        // Initialization
        document.addEventListener('DOMContentLoaded', function() {
            const selected = document.querySelector('input[name="delivery_method"]:checked');
            if (selected) {
                updateUI(selected.value);
            }
        });
    </script>
</body>
</html>
