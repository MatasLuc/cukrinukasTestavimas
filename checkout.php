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

/**
 * Patikrina nuolaidos kodą DB
 */
function checkDiscountCode($pdo, $code, $cartTotal) {
    if (empty($code)) return ['valid' => false, 'error' => 'Įveskite kodą.'];

    try {
        // Tikimės, kad lentelė vadinasi 'discounts' ir turi atitinkamus stulpelius
        // Jei jūsų stulpelių pavadinimai skiriasi (pvz. 'amount' vietoj 'value'), pakoreguokite čia
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

        // Tikriname limitus (jei yra 'usage_limit' ir 'used_count' stulpeliai)
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
            // Fiksuota suma (fixed)
            $discountValue = (float)$discount['value'];
        }

        // Nuolaida negali būti didesnė nei krepšelis
        if ($discountValue > $cartTotal) {
            $discountValue = $cartTotal;
        }

        return [
            'valid' => true,
            'data' => $discount,
            'calculated_value' => $discountValue
        ];

    } catch (Exception $e) {
        // Jei lentelės nėra, grąžiname klaidą
        return ['valid' => false, 'error' => 'Klaida tikrinant nuolaidą.'];
    }
}

// 4. Skaičiuojame krepšelio sumą (Be nuolaidų)
$cartItemsTotal = 0;
$productsInCart = [];
$ids = array_keys($_SESSION['cart']);
$hasFreeShippingProduct = false; 

// Gauname produktus, kurie suteikia nemokamą pristatymą
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

// 5. NUOLAIDOS KODO APDOROJIMAS (POST)
$discountError = '';
$discountSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Jei vartotojas bando pritaikyti nuolaidą
    if (isset($_POST['apply_discount_code'])) {
        $code = trim($_POST['discount_code'] ?? '');
        $result = checkDiscountCode($pdo, $code, $cartItemsTotal);
        
        if ($result['valid']) {
            $_SESSION['applied_discount'] = [
                'code' => $result['data']['code'],
                'value' => $result['calculated_value'],
                'type' => $result['data']['type'],
                'raw_value' => $result['data']['value'], // originali reikšmė iš DB
                'id' => $result['data']['id']
            ];
            $discountSuccess = 'Nuolaida pritaikyta!';
        } else {
            $discountError = $result['error'];
            unset($_SESSION['applied_discount']);
        }
    }
    // Jei vartotojas pašalina nuolaidą
    elseif (isset($_POST['remove_discount'])) {
        unset($_SESSION['applied_discount']);
        $discountSuccess = 'Nuolaida pašalinta.';
    }
}

// 6. PERSKAIČIUOJAME GALUTINĘ SUMĄ SU NUOLAIDA
$discountAmount = 0;
$activeDiscountCode = '';

if (isset($_SESSION['applied_discount'])) {
    // Dar kartą patikriname, ar nuolaida vis dar galioja (pvz. jei krepšelio suma pasikeitė)
    $check = checkDiscountCode($pdo, $_SESSION['applied_discount']['code'], $cartItemsTotal);
    if ($check['valid']) {
        $discountAmount = $check['calculated_value'];
        $activeDiscountCode = $_SESSION['applied_discount']['code'];
        // Atnaujiname sesiją su tikslia suma
        $_SESSION['applied_discount']['value'] = $discountAmount;
    } else {
        // Jei nebegalioja (pvz. suma sumažėjo žemiau limito)
        unset($_SESSION['applied_discount']);
        $discountError = "Nuolaida pašalinta: " . $check['error'];
    }
}

// Suma po nuolaidos (ši suma naudojama pristatymo ribos tikrinimui)
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

// 9. UŽSAKYMO PATEIKIMAS (Pagrindinė forma)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $errors = [];
    
    // CSRF VALIDACIJA
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Saugumo klaida. Pabandykite perkrauti puslapį.';
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $method = $_POST['delivery_method'] ?? 'pickup';
    $notes = trim($_POST['notes'] ?? '');
    $selectedLocker = trim($_POST['locker_select'] ?? '');

    // Validacija
    if (empty($name)) $errors[] = 'Būtina įvesti vardą.';
    if (empty($phone)) $errors[] = 'Būtina įvesti telefoną.';
    if ($method === 'courier' && empty($address)) $errors[] = 'Pasirinkus kurjerį, būtina nurodyti adresą.';
    if ($method === 'locker' && empty($selectedLocker)) $errors[] = 'Būtina pasirinkti paštomatą.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Perskaičiuojame pristatymo kainą pagal sumą PO nuolaidos
            $shippingPrice = calculateShippingPrice($shippingSettings, $totalAfterDiscount, $method, $hasFreeShippingProduct);
            
            // Galutinė suma
            $finalTotal = $totalAfterDiscount + $shippingPrice;

            // Formuojame delivery details JSON
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

            // Įrašome užsakymą
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

            // Įrašome prekes
            $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
            foreach ($productsInCart as $item) {
                $stmtItem->execute([$orderId, $item['id'], $item['qty'], $item['price']]);
            }

            // Atnaujiname nuolaidos panaudojimų skaičių (jei reikia)
            if (!empty($activeDiscountCode)) {
                $stmtUpdateDiscount = $pdo->prepare("UPDATE discounts SET used_count = used_count + 1 WHERE code = ?");
                $stmtUpdateDiscount->execute([$activeDiscountCode]);
            }

            $pdo->commit();
            
            unset($_SESSION['cart']);
            unset($_SESSION['applied_discount']); // Išvalome nuolaidą po užsakymo

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
?>
<!doctype html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <title>Užsakymas</title>
    <?php echo headerStyles(); ?>
    <style>
        .checkout-box { max-width: 800px; margin: 40px auto; background: #fff; padding: 30px; border-radius: 12px; border: 1px solid #e2e8f0; }
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 600; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; }
        .radio-option { display: flex; align-items: center; gap: 10px; padding: 15px; border: 1px solid #eee; border-radius: 8px; margin-bottom: 10px; cursor: pointer; transition: 0.2s; }
        .radio-option:hover { background: #f9f9f9; border-color: #ccc; }
        .radio-option input { width: 18px; height: 18px; }
        .price-badge { margin-left: auto; font-weight: bold; color: #2563eb; }
        .summary { margin-top: 30px; padding-top: 20px; border-top: 2px dashed #e2e8f0; }
        .row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 15px; }
        .row.discount { color: #16a34a; font-weight: 500; }
        .total-row { font-size: 20px; font-weight: 800; color: #0f172a; margin-top: 15px; }
        .btn-pay { width: 100%; padding: 15px; background: #10b981; color: white; border: none; border-radius: 8px; font-size: 18px; font-weight: 600; cursor: pointer; }
        .btn-pay:hover { background: #059669; }
        .free-shipping-notice { background: #ecfdf5; color: #065f46; padding: 10px; border-radius: 6px; margin-bottom: 20px; text-align: center; border: 1px solid #a7f3d0; }
        .hidden-field { display: none; }
        
        /* Discount form styles */
        .discount-wrapper { display: flex; gap: 10px; margin-bottom: 20px; align-items: flex-end; }
        .discount-wrapper .form-group { margin-bottom: 0; flex-grow: 1; }
        .btn-apply { padding: 10px 20px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; height: 38px; margin-top: auto; }
        .btn-apply:hover { background: #1d4ed8; }
        .btn-remove { background: #dc2626; margin-left: 10px; color:white; border:none; border-radius:4px; padding: 2px 8px; cursor: pointer; font-size: 12px; }
        .msg-success { color: #16a34a; font-size: 14px; margin-bottom: 10px; }
        .msg-error { color: #dc2626; font-size: 14px; margin-bottom: 10px; }
    </style>
</head>
<body>
    <?php renderHeader($pdo, 'cart'); ?>

    <div class="checkout-box">
        <h1 style="margin-top:0;">Užsakymo formavimas</h1>

        <?php if (!empty($errors)): ?>
            <div style="background:#fee2e2; color:#991b1b; padding:15px; margin-bottom:20px; border-radius:8px;">
                <?php echo implode('<br>', $errors); ?>
            </div>
        <?php endif; ?>

        <?php if ($discountError): ?>
            <div class="msg-error"><?php echo htmlspecialchars($discountError); ?></div>
        <?php endif; ?>
        <?php if ($discountSuccess): ?>
            <div class="msg-success"><?php echo htmlspecialchars($discountSuccess); ?></div>
        <?php endif; ?>

        <?php 
            // Skaičiuojame pristatymo kainas pagal sumą PO nuolaidos ($totalAfterDiscount)
            $courierCost = calculateShippingPrice($shippingSettings, $totalAfterDiscount, 'courier', $hasFreeShippingProduct);
            $lockerCost = calculateShippingPrice($shippingSettings, $totalAfterDiscount, 'locker', $hasFreeShippingProduct);
            
            $isFree = ($hasFreeShippingProduct || ($shippingSettings['free_over'] > 0 && $totalAfterDiscount >= $shippingSettings['free_over']));
        ?>

        <?php if($hasFreeShippingProduct): ?>
             <div class="free-shipping-notice">🎉 Jums taikomas nemokamas pristatymas (dovanos prekė)!</div>
        <?php elseif($isFree): ?>
            <div class="free-shipping-notice">🎉 Jums taikomas nemokamas pristatymas!</div>
        <?php elseif($shippingSettings['free_over'] > 0): ?>
            <div style="text-align:center; font-size:13px; color:#64748b; margin-bottom:20px;">
                Trūksta <?php echo number_format($shippingSettings['free_over'] - $totalAfterDiscount, 2); ?> € iki nemokamo pristatymo.
            </div>
        <?php endif; ?>

        <form method="POST" style="border-bottom: 1px solid #eee; padding-bottom: 20px; margin-bottom: 20px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            
            <?php if ($activeDiscountCode): ?>
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 10px; border-radius: 6px; display: flex; align-items: center; justify-content: space-between;">
                    <span style="color: #166534;">
                        Pritaikytas kodas: <strong><?php echo htmlspecialchars($activeDiscountCode); ?></strong> (-<?php echo number_format($discountAmount, 2); ?> €)
                    </span>
                    <button type="submit" name="remove_discount" class="btn-remove">Pašalinti</button>
                </div>
            <?php else: ?>
                <label class="form-label">Nuolaidos kodas</label>
                <div class="discount-wrapper">
                    <div class="form-group" style="margin-bottom:0;">
                        <input type="text" name="discount_code" class="form-control" placeholder="Įveskite kodą">
                    </div>
                    <button type="submit" name="apply_discount_code" class="btn-apply">Taikyti</button>
                </div>
            <?php endif; ?>
        </form>

        <form method="POST">
            <input type="hidden" name="place_order" value="1">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="form-group">
                <label class="form-label">Vardas, Pavardė</label>
                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" required>
            </div>
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                <div class="form-group">
                    <label class="form-label">El. paštas</label>
                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Telefonas</label>
                    <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Pristatymo būdas</label>
                
                <label class="radio-option">
                    <input type="radio" name="delivery_method" value="pickup" checked onchange="updateUI('pickup')">
                    <span>Atsiimti vietoje</span>
                    <span class="price-badge">0.00 €</span>
                </label>

                <label class="radio-option">
                    <input type="radio" name="delivery_method" value="locker" onchange="updateUI('locker')">
                    <span>Paštomatas</span>
                    <span class="price-badge"><?php echo $isFree ? '0.00' : number_format($lockerCost, 2); ?> €</span>
                </label>

                <label class="radio-option">
                    <input type="radio" name="delivery_method" value="courier" onchange="updateUI('courier')">
                    <span>Kurjeris į namus</span>
                    <span class="price-badge"><?php echo $isFree ? '0.00' : number_format($courierCost, 2); ?> €</span>
                </label>
            </div>

            <div class="form-group hidden-field" id="address-field">
                <label class="form-label">Pristatymo adresas</label>
                <input type="text" name="address" id="address-input" class="form-control" placeholder="Gatvė, namo nr., miestas, pašto kodas" value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>">
            </div>

            <div class="form-group hidden-field" id="locker-field">
                <label class="form-label">Pasirinkite paštomatą</label>
                <select name="locker_select" id="locker-select" class="form-control">
                    <option value="">-- Pasirinkite paštomatą --</option>
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
                        <option value="" disabled>Paštomatų sąrašas nerastas arba tuščias.</option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Pastabos (nebūtina)</label>
                <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>

            <div class="summary">
                <div class="row">
                    <span>Prekių krepšelis:</span>
                    <span><?php echo number_format($cartItemsTotal, 2); ?> €</span>
                </div>
                
                <?php if ($discountAmount > 0): ?>
                <div class="row discount">
                    <span>Nuolaida (<?php echo htmlspecialchars($activeDiscountCode); ?>):</span>
                    <span>-<?php echo number_format($discountAmount, 2); ?> €</span>
                </div>
                <?php endif; ?>

                <div class="row">
                    <span>Pristatymas:</span>
                    <span id="shipping-display">0.00 €</span>
                </div>
                <div class="row total-row">
                    <span>VISO MOKĖTI:</span>
                    <span id="total-display"><?php echo number_format($totalAfterDiscount, 2); ?> €</span>
                </div>
            </div>
            
            <br>
            <button type="submit" class="btn-pay">Apmokėti (Stripe)</button>
        </form>
    </div>

    <?php renderFooter($pdo); ?>

    <script>
        // Bazinė suma po nuolaidos (PHP paskaičiuota)
        const totalAfterDiscount = <?php echo number_format($totalAfterDiscount, 2, '.', ''); ?>;
        
        // Pristatymo kainos (PHP paskaičiuotos)
        const prices = {
            pickup: 0.00,
            locker: <?php echo $isFree ? '0.00' : number_format($lockerCost, 2, '.', ''); ?>,
            courier: <?php echo $isFree ? '0.00' : number_format($courierCost, 2, '.', ''); ?>
        };

        function updateUI(method) {
            const addrField = document.getElementById('address-field');
            const lockerField = document.getElementById('locker-field');
            const shipDisplay = document.getElementById('shipping-display');
            const totalDisplay = document.getElementById('total-display');
            
            const addrInput = document.getElementById('address-input');
            const lockerSelect = document.getElementById('locker-select');

            // 1. Reset
            addrField.style.display = 'none';
            lockerField.style.display = 'none';
            addrInput.removeAttribute('required');
            lockerSelect.removeAttribute('required');

            // 2. Logic
            if (method === 'courier') {
                addrField.style.display = 'block';
                addrInput.setAttribute('required', 'required');
            } else if (method === 'locker') {
                lockerField.style.display = 'block';
                lockerSelect.setAttribute('required', 'required');
            }

            // 3. Price update
            const shipPrice = parseFloat(prices[method] || 0);
            const finalPrice = totalAfterDiscount + shipPrice;

            shipDisplay.textContent = shipPrice.toFixed(2) + ' €';
            totalDisplay.textContent = finalPrice.toFixed(2) + ' €';
        }

        document.addEventListener('DOMContentLoaded', function() {
            const selected = document.querySelector('input[name="delivery_method"]:checked');
            if (selected) {
                updateUI(selected.value);
            }
        });
    </script>
</body>
</html>
