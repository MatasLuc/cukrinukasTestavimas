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
// Pastaba: Jei norėsite leisti pirkti be registracijos, šią dalį reikės pakoreguoti.
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

// 8. GAUNAME PAŠTOMATUS
$lockerList = [];
try {
    $stmtLockers = $pdo->query("SELECT * FROM parcel_lockers ORDER BY title ASC");
    $lockerList = $stmtLockers->fetchAll(PDO::FETCH_ASSOC);
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
    
    // Nustatymai pagal nutylėjimą - locker, nes atsiėmimo nebėra
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

        // Sujungiame į vieną eilutę (kad nereiktų keisti DB struktūros)
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
                $address = "Paštomatas: " . $selectedLocker; // Įrašome į db address stulpelį info tikslais
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
            --gold: #f59e0b;
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

        /* Promo Banner */
        .promo-banner {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.2);
            position: relative;
            overflow: hidden;
        }
        .promo-banner::before {
            content: ''; position: absolute; top: -50px; left: -50px; width: 100px; height: 100px;
            background: rgba(255,255,255,0.1); border-radius: 50%;
        }
        .promo-banner h2 { margin: 0 0 8px 0; font-size: 22px; font-weight: 800; }
        .promo-banner p { margin: 0; font-size: 15px; opacity: 0.9; }
        .promo-code-box {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 6px 14px;
            border-radius: 8px;
            font-weight: 700;
            margin-top: 10px;
            border: 1px dashed rgba(255,255,255,0.5);
            letter-spacing: 1px;
        }

        /* Forms */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-grid-3 { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 16px; } /* Gatve, Namo, Buto */
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

        /* Custom Radio Cards for Shipping */
        .shipping-options { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
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

        /* Sidebar & Summary */
        .sidebar { position: sticky; top: 20px; }
        .summary-row { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 14px; color: var(--text-muted); }
        .summary-row.total { border-top: 1px dashed var(--border); padding-top: 16px; margin-top: 16px; font-weight: 700; font-size: 18px; color: var(--text-main); }
        .summary-row.discount { color: var(--success); font-weight: 500; }
        
        /* Buttons */
        .btn-primary { 
            width: 100%; padding: 14px; 
            background: var(--accent); color: white; 
            border: none; border-radius: 10px; 
            font-size: 16px; font-weight: 600; 
            cursor: pointer; transition: 0.2s; 
            text-align: center;
        }
        .btn-primary:hover { background: var(--accent-hover); box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2); }
        
        /* Discount Form in Sidebar */
        .discount-form { display: flex; gap: 8px; margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border); }
        .btn-apply { padding: 0 16px; background: #0f172a; color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .btn-apply:hover { background: #1e293b; }
        .active-discount { background: #ecfdf5; border: 1px solid #6ee7b7; padding: 10px; border-radius: 8px; font-size: 13px; color: #065f46; display: flex; justify-content: space-between; align-items: center; margin-top: 20px; }
        .btn-remove { background: none; border: none; color: var(--danger); font-size: 11px; font-weight: 600; cursor: pointer; text-decoration: underline; }

        /* Alerts & Notices */
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
        .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        
        .free-shipping-notice { 
            background: linear-gradient(135deg, #ecfdf5, #d1fae5); 
            color: #064e3b; padding: 12px; border-radius: 8px; 
            margin-bottom: 16px; font-size: 13px; font-weight: 500; 
            border: 1px solid #6ee7b7; display: flex; align-items: center; gap: 8px; 
        }

        .hidden-field { display: none; margin-top: 20px; padding-top: 20px; border-top: 1px dashed var(--border); }
        
        /* Search Input for Lockers */
        .search-container { position: relative; margin-bottom: 10px; }
        .search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); width: 16px; height: 16px; }
        .search-input { padding-left: 32px; }
    </style>
</head>
<body>
    <?php renderHeader($pdo, 'cart'); ?>

    <div class="page">
        
        <?php if (!empty($_SESSION['user_id'])): ?>
            <div class="promo-banner">
                <h2>AČIŪ, kad esate su mumis! 🎉</h2>
                <p>Kaip registruotam nariui, dovanojame Jums <strong>5% nuolaidą</strong> šiam krepšeliui.</p>
                <div class="promo-code-box">KODAS: ACIU</div>
            </div>
        <?php else: ?>
            <div class="promo-banner" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                <h2>Norite gauti nuolaidą? 🎁</h2>
                <p>Prisijunkite arba užsiregistruokite dabar ir gaukite išskirtinę nuolaidą savo pirmam užsakymui!</p>
                <a href="/login.php" style="display:inline-block; margin-top:10px; color:white; font-weight:700; text-decoration:underline;">Prisijungti</a>
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
                            <div class="free-shipping-notice">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
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
                            <label class="form-label">Pasirinkite paštomatą</label>
                            
                            <div class="search-container">
                                <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                <input type="text" id="locker-search" class="form-control search-input" placeholder="Ieškoti paštomato (miestas, adresas)...">
                            </div>

                            <select name="locker_select" id="locker-select" class="form-control" required>
                                <option value="">-- Pasirinkite iš sąrašo --</option>
                                <?php if (!empty($lockerList)): ?>
                                    <?php foreach($lockerList as $locker): ?>
                                        <?php 
                                            $title = htmlspecialchars($locker['title'] ?? '');
                                            $address = htmlspecialchars($locker['address'] ?? '');
                                            $valueText = "$title - $address"; 
                                        ?>
                                        <option value="<?php echo $valueText; ?>"><?php echo "$title ($address)"; ?></option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>Paštomatų sąrašas nerastas.</option>
                                <?php endif; ?>
                            </select>
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
        const totalAfterDiscount = <?php echo number_format($totalAfterDiscount, 2, '.', ''); ?>;
        
        const prices = {
            locker: <?php echo $isFree ? '0.00' : number_format($lockerCost, 2, '.', ''); ?>,
            courier: <?php echo $isFree ? '0.00' : number_format($courierCost, 2, '.', ''); ?>
        };

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
            
            // Required inputs
            const reqInputs = ['input-city', 'input-street', 'input-house', 'input-zip'];
            const lockerSelect = document.getElementById('locker-select');

            // Reset
            addrField.style.display = 'none';
            lockerField.style.display = 'none';
            lockerSelect.removeAttribute('required');
            reqInputs.forEach(id => document.getElementById(id).removeAttribute('required'));

            // Logic
            if (method === 'courier') {
                addrField.style.display = 'block';
                reqInputs.forEach(id => document.getElementById(id).setAttribute('required', 'required'));
            } else if (method === 'locker') {
                lockerField.style.display = 'block';
                lockerSelect.setAttribute('required', 'required');
            }

            // Price update
            const shipPrice = parseFloat(prices[method] || 0);
            const finalPrice = totalAfterDiscount + shipPrice;

            shipDisplay.textContent = shipPrice.toFixed(2) + ' €';
            totalDisplay.textContent = finalPrice.toFixed(2) + ' €';
        }

        // --- PAŠTOMATŲ PAIEŠKA (Filtravimas) ---
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('locker-search');
            const select = document.getElementById('locker-select');
            
            // Išsaugome visas originalias opcijas
            const originalOptions = Array.from(select.options);

            searchInput.addEventListener('input', function(e) {
                const term = e.target.value.toLowerCase();
                
                // Išvalome selectą
                select.innerHTML = '';

                // Filtruojame ir pridedame atgal tik atitinkamas
                let foundAny = false;
                originalOptions.forEach(opt => {
                    if (opt.value === "") return; // Skip placeholder logic here
                    
                    const text = opt.text.toLowerCase();
                    if (text.includes(term)) {
                        select.appendChild(opt);
                        foundAny = true;
                    }
                });

                // Jei nieko nerado arba paieška tuščia, pridedame "Pasirinkite" placeholderį viršuje
                if (!foundAny && term.length > 0) {
                    const noRes = document.createElement('option');
                    noRes.text = "Nerasta paštomatų pagal užklausą";
                    noRes.disabled = true;
                    select.prepend(noRes);
                } else {
                    // Visada pridedame default pasirinkimą viršuje, jei filtruojam
                    const def = originalOptions[0]; // "-- Pasirinkite --"
                    select.prepend(def);
                    // Jei tik vienas variantas rastas, jį ir parenkam
                    if(select.options.length === 2) {
                        select.selectedIndex = 1;
                    } else {
                        select.selectedIndex = 0;
                    }
                }
            });

            // Initial UI check
            const selected = document.querySelector('input[name="delivery_method"]:checked');
            if (selected) {
                updateUI(selected.value);
            }
        });
    </script>
</body>
</html>
