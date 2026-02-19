<?php
// checkout.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// 1. Apsauga ir DB prisijungimas
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/helpers.php'; 

$pdo = getPdo();

// 2. Patikriname, ar krepšelis nėra visiškai tuščias
if ((empty($_SESSION['cart']) || count($_SESSION['cart']) === 0) && (empty($_SESSION['cart_community']) || count($_SESSION['cart_community']) === 0)) {
    header('Location: products.php');
    exit;
}

// Patikriname ar krepšelyje TIK bendruomenės prekės
$isCommunityOnly = empty($_SESSION['cart']) && !empty($_SESSION['cart_community']);

// --- DUOMENŲ GAVIMAS IŠ DB (SETTINGS) ---
$stmtSettings = $pdo->query("SELECT * FROM shipping_settings LIMIT 1");
$shippingSettings = $stmtSettings->fetch(PDO::FETCH_ASSOC);

if (!$shippingSettings) {
    $shippingSettings = [
        'base_price' => 3.99,
        'courier_price' => 3.99,
        'locker_price' => 2.49,
        'free_over' => 50.00
    ];
}

// Globalios parduotuvės nuolaidos
$stmtDiscSettings = $pdo->query("SELECT * FROM discount_settings LIMIT 1");
$globalDiscountSettings = $stmtDiscSettings->fetch(PDO::FETCH_ASSOC);

// Gauname produktus, kuriems taikomas nemokamas siuntimas
$stmtFreeProd = $pdo->query("SELECT product_id FROM shipping_free_products");
$freeShippingProductIds = $stmtFreeProd->fetchAll(PDO::FETCH_COLUMN);

// --- FUNKCIJOS ---

function checkDiscountCode($pdo, $code, $cartTotal) {
    if (empty($code)) return ['valid' => false, 'error' => 'Įveskite kodą.'];

    try {
        // Ištaisyta lentelė ir stulpelis
        $stmt = $pdo->prepare("SELECT * FROM discount_codes WHERE code = ? AND active = 1 LIMIT 1");
        $stmt->execute([$code]);
        $discount = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$discount) {
            return ['valid' => false, 'error' => 'Nuolaidos kodas nerastas arba negalioja.'];
        }

        // Tikrinimai (limitai)
        if (isset($discount['usage_limit']) && $discount['usage_limit'] > 0) {
            $used = $discount['used_count'] ?? 0;
            if ($used >= $discount['usage_limit']) return ['valid' => false, 'error' => 'Kodo panaudojimo limitas pasiektas.'];
        }

        // Skaičiavimas
        $discountValue = 0;
        $grantsFreeShipping = false;

        if ($discount['type'] === 'percent') {
            $discountValue = round(($cartTotal * ($discount['value'] / 100)), 2);
        } elseif ($discount['type'] === 'amount') {
            $discountValue = (float)$discount['value'];
        }
        
        if (($discount['type'] ?? '') === 'free_shipping' || (!empty($discount['free_shipping']) && $discount['free_shipping'] == 1)) {
            $grantsFreeShipping = true;
        }

        if ($discountValue > $cartTotal) $discountValue = $cartTotal;

        return [
            'valid' => true,
            'data' => $discount,
            'calculated_value' => $discountValue,
            'grants_free_shipping' => $grantsFreeShipping
        ];

    } catch (Exception $e) {
        // Pridėtas tikslesnis klaidos išvedimas atvaizdavimui debugingui
        return ['valid' => false, 'error' => 'Klaida tikrinant nuolaidą: ' . $e->getMessage()];
    }
}

// 4. SKAIČIUOJAME PREKIŲ KREPŠELĮ (SHOP + COMMUNITY)
$cartItemsTotal = 0;
$productsInCart = []; 
$hasFreeShippingProduct = false;

// --- A. SHOP PREKĖS ---
if (!empty($_SESSION['cart'])) {
    $cartKeys = array_keys($_SESSION['cart']);
    $cleanIds = [];
    foreach ($cartKeys as $key) {
        $parts = explode('_', (string)$key);
        $cleanIds[] = (int)$parts[0];
    }
    $cleanIds = array_unique($cleanIds);

    if (!empty($cleanIds)) {
        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $stmt = $pdo->prepare("SELECT id, title, price, sale_price FROM products WHERE id IN ($placeholders)");
        $stmt->execute($cleanIds);
        $productsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $productsMap = [];
        foreach ($productsRaw as $row) $productsMap[$row['id']] = $row;

        foreach ($_SESSION['cart'] as $cartKey => $qty) {
            $parts = explode('_', (string)$cartKey);
            $pId = (int)$parts[0];

            if (isset($productsMap[$pId])) {
                $p = $productsMap[$pId];
                
                $basePrice = (float)$p['price'];
                if (!empty($p['sale_price']) && $p['sale_price'] > 0 && $p['sale_price'] < $p['price']) {
                    $basePrice = (float)$p['sale_price'];
                }

                $variationDelta = 0;
                if (isset($_SESSION['cart_variations'][$cartKey]) && is_array($_SESSION['cart_variations'][$cartKey])) {
                    foreach ($_SESSION['cart_variations'][$cartKey] as $var) {
                        $variationDelta += (float)($var['delta'] ?? 0);
                    }
                }

                $finalPrice = $basePrice + $variationDelta;
                $lineTotal = $finalPrice * $qty;
                $cartItemsTotal += $lineTotal;
                
                if (in_array($pId, $freeShippingProductIds)) {
                    $hasFreeShippingProduct = true;
                }

                $productsInCart[] = [
                    'product_id' => $pId,
                    'price' => $finalPrice,
                    'qty' => $qty,
                    'type' => 'shop'
                ];
            }
        }
    }
}

// --- B. COMMUNITY PREKĖS ---
if (!empty($_SESSION['cart_community'])) {
    $cIds = array_keys($_SESSION['cart_community']);
    if (!empty($cIds)) {
        $placeholders = implode(',', array_fill(0, count($cIds), '?'));
        $stmt = $pdo->prepare("SELECT id, price FROM community_listings WHERE id IN ($placeholders)");
        $stmt->execute($cIds);
        $cProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($cProducts as $cp) {
            $qty = 1; 
            $price = (float)$cp['price'];
            $lineTotal = $price * $qty;
            $cartItemsTotal += $lineTotal;

            $productsInCart[] = [
                'product_id' => $cp['id'],
                'price' => $price,
                'qty' => $qty,
                'type' => 'community'
            ];
        }
    }
}

// 5. NUOLAIDOS APDOROJIMAS
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
                'grants_free_shipping' => $result['grants_free_shipping']
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

// 6. GALUTINIAI SKAIČIAVIMAI
$discountAmount = 0;
$activeDiscountCode = null;
$couponGrantsFreeShipping = false;

// Kodo nuolaida
if (isset($_SESSION['applied_discount'])) {
    $check = checkDiscountCode($pdo, $_SESSION['applied_discount']['code'], $cartItemsTotal);
    if ($check['valid']) {
        $discountAmount = $check['calculated_value'];
        $couponGrantsFreeShipping = $check['grants_free_shipping'];
        $activeDiscountCode = $_SESSION['applied_discount']['code'];
    } else {
        unset($_SESSION['applied_discount']);
        $discountError = "Nuolaida nebegalioja: " . $check['error'];
    }
}

// Globali nuolaida (iš discount_settings)
$globalDiscountAmount = 0;
$globalGrantsFreeShipping = false;

if ($globalDiscountSettings && $globalDiscountSettings['type'] !== 'none') {
    if ($globalDiscountSettings['type'] === 'percent') {
        $globalDiscountAmount = round(($cartItemsTotal * ($globalDiscountSettings['value'] / 100)), 2);
    } elseif ($globalDiscountSettings['type'] === 'amount') {
        $globalDiscountAmount = (float)$globalDiscountSettings['value'];
    }
    if ($globalDiscountSettings['type'] === 'free_shipping' || !empty($globalDiscountSettings['free_shipping'])) {
        $globalGrantsFreeShipping = true;
    }
}

$totalDiscount = $discountAmount + $globalDiscountAmount;
if ($totalDiscount > $cartItemsTotal) {
    $totalDiscount = $cartItemsTotal;
}

$totalAfterDiscount = max(0, $cartItemsTotal - $totalDiscount);

// Siuntimo taisyklės
$isShippingFree = false;
if ($hasFreeShippingProduct) $isShippingFree = true;
elseif ($couponGrantsFreeShipping) $isShippingFree = true;
elseif ($globalGrantsFreeShipping) $isShippingFree = true;
elseif (!empty($shippingSettings['free_over']) && $shippingSettings['free_over'] > 0 && $totalAfterDiscount >= $shippingSettings['free_over']) $isShippingFree = true;

$lockerPriceDisplay = $isShippingFree ? 0.00 : (float)$shippingSettings['locker_price'];
$courierPriceDisplay = $isShippingFree ? 0.00 : (float)$shippingSettings['courier_price'];

// 7. PAŠTOMATŲ SĄRAŠAS (JS)
$lockersForJs = [];
try {
    $stmtLockers = $pdo->query("SELECT * FROM parcel_lockers"); 
    if ($stmtLockers) {
        $allLockers = $stmtLockers->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allLockers as $l) {
            $addressParts = explode(',', $l['address']);
            $derivedCity = trim($addressParts[0] ?? '');
            
            $lockersForJs[] = [
                'title' => $l['title'],
                'address' => $l['address'],
                'city' => $derivedCity,
                'type' => strtolower(trim($l['provider'] ?? 'other')), 
                'full' => ($derivedCity ? $derivedCity . ' - ' : '') . $l['title'] . ' (' . $l['address'] . ')'
            ];
        }
        usort($lockersForJs, function($a, $b) {
            return strcmp($a['city'], $b['city']) ?: strcmp($a['title'], $b['title']);
        });
    }
} catch (Exception $e) {}

// 8. UŽSAKYMO ĮRAŠYMAS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $errors = [];
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Saugumo klaida. Pabandykite iš naujo.';
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $method = $_POST['delivery_method'] ?? 'locker';
    $notes = trim($_POST['notes'] ?? '');
    $selectedLocker = trim($_POST['locker_select'] ?? '');

    $fullAddress = "";
    if ($method === 'courier') {
        $city = trim($_POST['city'] ?? '');
        $street = trim($_POST['street'] ?? '');
        $house = trim($_POST['house'] ?? '');
        $flat = trim($_POST['flat'] ?? '');
        $zip = trim($_POST['zip'] ?? '');

        if (empty($city) || empty($street) || empty($house) || empty($zip)) {
            $errors[] = 'Užpildykite visus adreso laukus kurjeriui.';
        }
        $fullAddress = "$street g. $house" . ($flat ? "-$flat" : "") . ", $city, LT-$zip";
    } elseif ($method === 'locker') {
        if (empty($selectedLocker)) {
            $errors[] = 'Pasirinkite paštomatą.';
        }
        $fullAddress = "Paštomatas: " . $selectedLocker;
    }

    if (empty($name) || empty($phone) || empty($email)) {
        $errors[] = 'Neužpildyti kontaktiniai duomenys.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $finalShippingPrice = 0;
            if (!$isShippingFree && !$isCommunityOnly) {
                if ($method === 'courier') {
                    $finalShippingPrice = (float)$shippingSettings['courier_price'];
                } else {
                    $finalShippingPrice = (float)$shippingSettings['locker_price'];
                }
            }

            $grandTotal = $totalAfterDiscount + $finalShippingPrice;

            // Formuojame JSON su papildoma informacija
            $deliveryDetailsArr = [
                'method' => $method,
                'shipping_price' => $finalShippingPrice,
                'contact_phone' => $phone,
                'contact_email' => $email,
                'notes' => $notes,
                'locker_name' => ($method === 'locker') ? $selectedLocker : null
            ];
            $deliveryDetailsJson = json_encode($deliveryDetailsArr);

            // --- ĮRAŠYMAS Į ORDERS LENTELĘ ---
            $stmtOrder = $pdo->prepare("
                INSERT INTO orders (
                    user_id, 
                    customer_name, 
                    customer_email, 
                    customer_phone, 
                    customer_address, 
                    discount_code, 
                    discount_amount, 
                    shipping_amount, 
                    total, 
                    status, 
                    delivery_method, 
                    delivery_details, 
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'laukiama apmokėjimo', ?, ?, NOW())
            ");
            
            $stmtOrder->execute([
                $_SESSION['user_id'] ?? null,
                $name,
                $email,
                $phone,
                $fullAddress,
                $activeDiscountCode,
                $totalDiscount, // Įrašoma bendra pritaikyta nuolaida (kodas + globali)
                $finalShippingPrice, 
                $grandTotal,
                $method,
                $deliveryDetailsJson
            ]);
            
            $orderId = $pdo->lastInsertId();

            // --- ĮRAŠYMAS Į ORDER_ITEMS ---
            $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
            
            foreach ($productsInCart as $item) {
                // Praleidžiame bendruomenės prekes, kad jos nesidubliuotų ir neiškreiptų pristatymo kainos
                if (isset($item['type']) && $item['type'] === 'community') {
                    continue; 
                }

                $stmtItem->execute([
                    $orderId, 
                    $item['product_id'], 
                    $item['qty'], 
                    $item['price']
                ]);
            }

            // Atnaujiname nuolaidos panaudojimą ištaisydami į discount_codes
            if (!empty($activeDiscountCode)) {
                $pdo->prepare("UPDATE discount_codes SET used_count = used_count + 1 WHERE code = ?")->execute([$activeDiscountCode]);
            }

            $pdo->commit();
            // Nukreipiame su order_id, kad stripe_checkout.php galėtų paimti pristatymo kainą
            header("Location: stripe_checkout.php?order_id=" . $orderId);
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Sistemos klaida: " . $e->getMessage();
        }
    }
}

// User autofill
$user = [];
if (!empty($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}
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

        /* Promo Banner */
        .promo-banner {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border: 1px solid #dbeafe;
            border-radius: 20px;
            padding: 32px;
            margin-bottom: 32px;
            text-align: left;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            display: flex; align-items: center; justify-content: space-between; gap: 24px; flex-wrap: wrap;
        }
        .promo-content { max-width: 600px; flex: 1; }
        .promo-banner h2 { margin: 0 0 12px 0; font-size: 24px; font-weight: 700; color: #1e3a8a; letter-spacing: -0.5px; }
        .promo-banner p { margin: 0; color: #1e40af; line-height: 1.6; font-size: 15px; }
        .pill { display:inline-flex; align-items:center; gap:8px; padding:6px 12px; border-radius:999px; background:#fff; border:1px solid #bfdbfe; font-weight:600; font-size:13px; color:#1e40af; margin-bottom: 12px; }
        .promo-code-box { display: inline-block; background: #ffffff; padding: 10px 18px; border-radius: 10px; font-weight: 700; color: #1e40af; border: 1px dashed #3b82f6; letter-spacing: 1px; font-size: 14px; white-space: nowrap; }

        /* Forms */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-grid-3 { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 16px; } 
        @media(max-width: 600px) { .form-grid-3 { grid-template-columns: 1fr; } }

        .form-group { margin-bottom: 16px; }
        .form-label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: var(--text-muted); }
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; color: var(--text-main); transition: all 0.2s; box-sizing: border-box; background: #fff; }
        .form-control:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }
        textarea.form-control { resize: vertical; min-height: 80px; }

        /* Shipping & Locker Providers */
        .shipping-options { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; }
        @media(max-width: 600px) { .shipping-options { grid-template-columns: 1fr; } }

        .radio-card { position: relative; border: 1px solid var(--border); border-radius: 10px; padding: 16px; cursor: pointer; transition: all 0.2s; display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
        .radio-card:hover { background: #f8fafc; border-color: #cbd5e1; }
        .radio-card.active { border-color: var(--accent); background: #eff6ff; box-shadow: 0 0 0 1px var(--accent); }
        .radio-card input { position: absolute; opacity: 0; cursor: pointer; height: 0; width: 0; }
        
        .radio-header { display: flex; justify-content: space-between; width: 100%; align-items: center; }
        .radio-circle { width: 18px; height: 18px; border-radius: 50%; border: 2px solid #cbd5e1; display: flex; align-items: center; justify-content: center; margin-right: 10px; }
        .radio-card.active .radio-circle { border-color: var(--accent); }
        .radio-card.active .radio-circle::after { content: ''; width: 8px; height: 8px; background: var(--accent); border-radius: 50%; }
        
        .radio-label { font-weight: 600; font-size: 15px; display: flex; align-items: center; }
        .radio-price { font-weight: 600; font-size: 14px; color: var(--text-main); background: #fff; padding: 4px 8px; border-radius: 6px; border: 1px solid var(--border); }
        .radio-price.free { color: var(--success); border-color: #bbf7d0; background: #f0fdf4; }

        /* Provider Selection Buttons */
        .provider-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
        .provider-btn { border: 1px solid var(--border); border-radius: 8px; padding: 12px; text-align: center; font-weight: 600; color: var(--text-muted); cursor: pointer; transition: all 0.2s; background: #fff; }
        .provider-btn:hover { background: #f8fafc; }
        .provider-btn.active { border-color: var(--accent); background: #eff6ff; color: var(--accent); box-shadow: 0 0 0 1px var(--accent); }

        /* CUSTOM SELECT */
        .custom-select-wrapper { position: relative; user-select: none; width: 100%; }
        .custom-select-trigger { position: relative; display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; font-size: 14px; font-weight: 400; color: var(--text-main); background: #fff; border: 1px solid var(--border); border-radius: 8px; cursor: pointer; transition: all 0.2s; }
        .custom-select-wrapper.open .custom-select-trigger { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }
        .custom-select-trigger span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        
        .custom-options-container { position: absolute; display: none; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); z-index: 100; margin-top: 4px; max-height: 300px; overflow: hidden; flex-direction: column; }
        .custom-select-wrapper.open .custom-options-container { display: flex; }
        
        .sticky-search { padding: 8px; background: #fff; border-bottom: 1px solid var(--border); }
        .sticky-search input { width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; box-sizing: border-box; }
        .sticky-search input:focus { outline: none; border-color: var(--accent); }
        
        .options-list { overflow-y: auto; flex: 1; }
        .custom-option { padding: 10px 12px; font-size: 13px; color: var(--text-main); cursor: pointer; transition: background 0.1s; border-bottom: 1px solid #f1f5f9; }
        .custom-option:last-child { border-bottom: none; }
        .custom-option:hover { background: #f1f5f9; }
        .custom-option.selected { background: #eff6ff; color: var(--accent); font-weight: 500; }
        .custom-option.no-results { text-align: center; color: var(--text-muted); cursor: default; }

        /* Sidebar & Summary */
        .sidebar { position: sticky; top: 20px; }
        .summary-row { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 14px; color: var(--text-muted); }
        .summary-row.total { border-top: 1px dashed var(--border); padding-top: 16px; margin-top: 16px; font-weight: 700; font-size: 18px; color: var(--text-main); }
        .summary-row.discount { color: var(--success); font-weight: 500; }
        
        .btn-primary { width: 100%; padding: 14px; background: var(--accent); color: white; border: none; border-radius: 10px; font-size: 16px; font-weight: 600; cursor: pointer; transition: 0.2s; text-align: center; }
        .btn-primary:hover { background: var(--accent-hover); box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2); }
        
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
        
        <div class="promo-banner">
            <?php if (!empty($_SESSION['user_id'])): ?>
                <div class="promo-content">
                    <div class="pill">🎉 Jūs esate klubo narys</div>
                    <h2>AČIŪ, kad esate su mumis!</h2>
                    <p>Kaip registruotam nariui, dovanojame Jums išskirtinę nuolaidą.</p>
                </div>
            <?php else: ?>
                <div class="promo-content">
                    <div class="pill">🎁 Išskirtinis pasiūlymas</div>
                    <h2>Norite gauti nuolaidą?</h2>
                    <p>
                        <a href="login.php" style="color: inherit; text-decoration: underline; font-weight: bold;">Prisijunkite</a> 
                        arba 
                        <a href="register.php" style="color: inherit; text-decoration: underline; font-weight: bold;">registruokitės</a>, 
                        tapkite klubo nariu ir sutaupykite!
                    </p>
                </div>
            <?php endif; ?>
        </div>

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
                        
                        <?php if($isCommunityOnly): ?>
                            <div class="alert alert-success" style="padding: 10px; margin-bottom: 16px; font-weight: 500; background: #eff6ff; color: #1e40af; border-color: #bfdbfe;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: text-bottom; margin-right: 5px;"><circle cx="12" cy="12" r="10"></circle><path d="M12 8v4l3 3"></path></svg>
                                Pristatymą apmoka arba derina prekės pardavėjas. Prašome nurodyti, kur pageidaujate gauti siuntą.
                            </div>
                        <?php elseif($isShippingFree): ?>
                            <div class="alert alert-success" style="padding: 10px; margin-bottom: 16px; font-weight: 500;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: text-bottom; margin-right: 5px;"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                Jums taikomas nemokamas pristatymas!
                            </div>
                        <?php elseif(!empty($shippingSettings['free_over']) && $shippingSettings['free_over'] > 0): ?>
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
                                    <?php if (!$isCommunityOnly): ?>
                                    <span class="radio-price <?php echo $isShippingFree ? 'free' : ''; ?>">
                                        <?php echo number_format($lockerPriceDisplay, 2); ?> €
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </label>

                            <label class="radio-card" onclick="selectShipping(this, 'courier')">
                                <div class="radio-header">
                                    <span class="radio-label">
                                        <div class="radio-circle"></div>
                                        Kurjeris į namus
                                    </span>
                                    <input type="radio" name="delivery_method" value="courier">
                                    <?php if (!$isCommunityOnly): ?>
                                    <span class="radio-price <?php echo $isShippingFree ? 'free' : ''; ?>">
                                        <?php echo number_format($courierPriceDisplay, 2); ?> €
                                    </span>
                                    <?php endif; ?>
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

                    <?php if ($globalDiscountAmount > 0): ?>
                        <div class="summary-row discount">
                            <span>Parduotuvės nuolaida</span>
                            <span>-<?php echo number_format($globalDiscountAmount, 2); ?> €</span>
                        </div>
                    <?php endif; ?>

                    <?php if ($discountAmount > 0): ?>
                        <div class="summary-row discount">
                            <span>Kodo nuolaida (<?php echo htmlspecialchars($activeDiscountCode); ?>)</span>
                            <span>-<?php echo number_format($discountAmount, 2); ?> €</span>
                        </div>
                    <?php endif; ?>

                    <?php if (!$isCommunityOnly): ?>
                    <div class="summary-row">
                        <span>Pristatymas</span>
                        <span id="shipping-display"><?php echo number_format($lockerPriceDisplay, 2); ?> €</span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="summary-row total">
                        <span>VISO MOKĖTI</span>
                        <span id="total-display"><?php echo number_format($totalAfterDiscount + ($isCommunityOnly ? 0 : $lockerPriceDisplay), 2); ?> €</span>
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
        const isCommunityOnly = <?php echo $isCommunityOnly ? 'true' : 'false'; ?>;
        // Čia kainos jau ateina su įvertintu nemokamu siuntimu (jei jis priklauso)
        const prices = {
            locker: <?php echo number_format($lockerPriceDisplay, 2, '.', ''); ?>,
            courier: <?php echo number_format($courierPriceDisplay, 2, '.', ''); ?>
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
            const shipPrice = isCommunityOnly ? 0 : parseFloat(prices[method] || 0);
            const finalPrice = totalAfterDiscount + shipPrice;

            if (!isCommunityOnly && shipDisplay) {
                shipDisplay.textContent = shipPrice.toFixed(2) + ' €';
            }
            if (totalDisplay) {
                totalDisplay.textContent = finalPrice.toFixed(2) + ' €';
            }
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
