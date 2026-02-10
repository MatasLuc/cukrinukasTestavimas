<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

// Įkeliame Paysera bibliotekas
if (file_exists(__DIR__ . '/libwebtopay/WebToPay.php')) {
    require_once __DIR__ . '/libwebtopay/WebToPay.php';
}

// Prisijungiame prie DB
try {
    $pdo = getPdo();
} catch (Throwable $e) {
    http_response_code(500);
    error_log("DB Connection error: " . $e->getMessage());
    echo 'Įvyko klaida prisijungiant prie duomenų bazės.';
    exit;
}

// Užtikriname, kad lentelės egzistuoja (pagal db.php)
ensureProductsTable($pdo);
ensureOrdersTables($pdo);
ensureCartTables($pdo);
ensureLockerTables($pdo);
ensureShippingSettings($pdo);

// --- 1. KREPŠELIO DUOMENŲ GAVIMAS (Pagal cart.php logiką) ---
$rawCart = $_SESSION['cart'] ?? [];
$rawVariations = $_SESSION['cart_variations'] ?? [];

// Jei krepšelis tuščias, metam atgal į produktus
if (empty($rawCart)) {
    header('Location: /products.php');
    exit;
}

// Surenkame produktų ID
$productIdsToFetch = [];
foreach (array_keys($rawCart) as $key) {
    $parts = explode('_', $key);
    $pid = (int)$parts[0];
    if ($pid > 0) {
        $productIdsToFetch[$pid] = true;
    }
}

// Užkrauname produktus iš DB
$fetchedProducts = [];
if (!empty($productIdsToFetch)) {
    $placeholders = implode(',', array_fill(0, count($productIdsToFetch), '?'));
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders)");
    $stmt->execute(array_keys($productIdsToFetch));
    while ($row = $stmt->fetch()) {
        $fetchedProducts[$row['id']] = $row;
    }
}

// Nuolaidų ir nustatymų gavimas
$categoryDiscounts = getCategoryDiscounts($pdo);
$globalDiscount = getGlobalDiscount($pdo);
$freeShippingIds = getFreeShippingProductIds($pdo); // IDs prekių, kurios suteikia nemokamą siuntimą

$items = [];
$subtotal = 0;

// Formuojame items sąrašą (Iteruojame per sesijos raktus, kad atskirtume variacijas)
foreach ($rawCart as $key => $qty) {
    $parts = explode('_', $key);
    $pid = (int)$parts[0];
    
    if (!isset($fetchedProducts[$pid])) continue;
    $product = $fetchedProducts[$pid];
    
    // Prijungiame variacijas
    $currentVariations = $rawVariations[$key] ?? [];
    
    // Skaičiuojame kainą su variacijomis
    $variationDelta = 0;
    foreach ($currentVariations as $cv) {
        $variationDelta += (float)($cv['delta'] ?? 0);
    }
    
    // Bazinės kainos
    $basePrice = (float)$product['price'] + $variationDelta;
    $salePrice = ($product['sale_price'] !== null) ? ((float)$product['sale_price'] + $variationDelta) : null;
    
    // Pritaikome nuolaidas
    $catDisc = $categoryDiscounts[$product['category_id']] ?? null;
    $finalPrice = ($salePrice !== null) ? $salePrice : $basePrice;
    
    // Globali nuolaida
    if (($globalDiscount['type'] ?? '') === 'percent') $finalPrice *= (1 - $globalDiscount['value']/100);
    elseif (($globalDiscount['type'] ?? '') === 'amount') $finalPrice -= $globalDiscount['value'];
    
    // Kategorijos
    if ($catDisc) {
        if ($catDisc['type'] === 'percent') $finalPrice *= (1 - $catDisc['value']/100);
        elseif ($catDisc['type'] === 'amount') $finalPrice -= $catDisc['value'];
    }
    
    $finalPrice = max(0, $finalPrice);
    
    $items[] = [
        'id' => $pid,
        'title' => $product['title'],
        'image_url' => $product['image_url'],
        'quantity' => $qty,
        'price' => $finalPrice,
        'line_total' => $finalPrice * $qty,
        'variation' => $currentVariations, // Čia saugomas pilnas variacijų masyvas
        'category_id' => $product['category_id']
    ];
    
    $subtotal += ($finalPrice * $qty);
}

// Jei po apdorojimo krepšelis tuščias
if (empty($items)) {
    header('Location: /products.php');
    exit;
}

// --- 2. PRISTATYMO KAINOS IR NUSTATYMAI ---
$shippingSettings = getShippingSettings($pdo);
$courierPrice = (float)($shippingSettings['courier_price'] ?? 3.99);
$lockerPrice = (float)($shippingSettings['locker_price'] ?? 2.49);
$freeOver = isset($shippingSettings['free_over']) ? (float)$shippingSettings['free_over'] : null;

// Patikriname nemokamą pristatymą
$hasFreeShippingProduct = false;
foreach ($items as $itm) {
    if (in_array((int)$itm['id'], $freeShippingIds, true)) {
        $hasFreeShippingProduct = true;
        break;
    }
}

$hasCategoryFreeShipping = false;
foreach ($items as $item) {
    $catId = $item['category_id'] ?? null;
    if ($catId && !empty($categoryDiscounts[$catId]['free_shipping'])) {
        $hasCategoryFreeShipping = true;
        break;
    }
}

$qualifiesForFreeByTotal = ($freeOver !== null && $freeOver > 0 && $subtotal >= $freeOver);
$isFreeShipping = !empty($globalDiscount['free_shipping']) 
                  || $hasCategoryFreeShipping 
                  || $hasFreeShippingProduct 
                  || $qualifiesForFreeByTotal;

// Paštomatų tinklai
$lockerNetworks = getLockerNetworks($pdo);

// --- 3. FORMOS DUOMENŲ APDOROJIMAS ---
$name = '';
$email = '';
$phone = '';
$address = '';
$lockerRequest = '';
$deliveryMethod = 'courier';
$lockerProvider = array_key_first($lockerNetworks) ?: '';
$lockerLocation = '';
$lockerId = 0;
$errors = [];

// Jei vartotojas prisijungęs, užpildome
if (isset($_SESSION['user_id'])) {
    $uStmt = $pdo->prepare('SELECT name, email, city, country FROM users WHERE id = ?');
    $uStmt->execute([$_SESSION['user_id']]);
    $uData = $uStmt->fetch();
    if ($uData) {
        $name = $uData['name'];
        $email = $uData['email'];
        if (!empty($uData['city'])) {
            $address = $uData['city'];
        }
    }
}

// POST apdorojimas
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('validateCsrfToken')) {
        validateCsrfToken();
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $deliveryMethod = $_POST['delivery_method'] ?? 'courier';
    
    $lockerProvider = $_POST['locker_provider'] ?? '';
    $lockerLocationTitle = $_POST['locker_location'] ?? '';
    $lockerId = (int)($_POST['locker_id_hidden'] ?? 0);
    $lockerRequest = trim($_POST['locker_request'] ?? '');

    if (empty($name) || empty($email) || empty($phone)) {
        $errors[] = 'Užpildykite visus kontaktinius duomenis (vardą, el. paštą, telefoną).';
    }

    if ($deliveryMethod === 'courier' && empty($address)) {
        $errors[] = 'Pasirinkus kurjerį, būtina įvesti pristatymo adresą.';
    }

    $selectedLockerData = null;
    if ($deliveryMethod === 'locker') {
        if ($lockerId > 0) {
            $selectedLockerData = getLockerById($pdo, $lockerId);
            if (!$selectedLockerData) {
                $errors[] = 'Pasirinktas paštomatas neegzistuoja sistemoje.';
            }
        } elseif (!empty($lockerRequest)) {
            // Vartotojas įvedė ranka
        } else {
            $errors[] = 'Pasirinkite paštomatą iš sąrašo arba įrašykite pageidavimą.';
        }
    }

    $shippingCost = $isFreeShipping ? 0 : ($deliveryMethod === 'locker' ? $lockerPrice : $courierPrice);
    $totalPayable = $subtotal + $shippingCost;

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            $deliveryDetails = [
                'method' => $deliveryMethod,
                'phone' => $phone
            ];

            $finalAddressString = '';

            if ($deliveryMethod === 'locker') {
                $deliveryDetails['provider'] = $lockerProvider;
                if ($selectedLockerData) {
                    $deliveryDetails['locker_id'] = $selectedLockerData['id'];
                    $deliveryDetails['address'] = $selectedLockerData['address'];
                    $deliveryDetails['title'] = $selectedLockerData['title'];
                    $finalAddressString = "{$selectedLockerData['title']}, {$selectedLockerData['address']} ({$lockerProvider})";
                } else {
                    $deliveryDetails['manual_request'] = $lockerRequest;
                    $finalAddressString = "Kliento pageidavimas: $lockerRequest ($lockerProvider)";
                }
            } else {
                $finalAddressString = $address;
            }

            // 1. Įrašome į `orders`
            $sqlOrder = "INSERT INTO orders (
                user_id, customer_name, customer_email, customer_phone, customer_address, 
                discount_amount, shipping_amount, total, status, delivery_method, delivery_details
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmtOrder = $pdo->prepare($sqlOrder);
            $stmtOrder->execute([
                $_SESSION['user_id'] ?? null,
                $name,
                $email,
                $phone,
                $finalAddressString,
                0,
                $shippingCost,
                $totalPayable,
                'laukiama apmokėjimo',
                $deliveryMethod,
                json_encode($deliveryDetails, JSON_UNESCAPED_UNICODE)
            ]);
            
            $orderId = (int)$pdo->lastInsertId();

            // 2. Įrašome į `order_items`
            $sqlItem = "INSERT INTO order_items (order_id, product_id, quantity, price, variation_info) VALUES (?, ?, ?, ?, ?)";
            $stmtItem = $pdo->prepare($sqlItem);

            foreach ($items as $item) {
                // Formuojame variacijos info tekstą
                $varInfoParts = [];
                $varData = $item['variation'] ?? [];
                
                // Jei kartais tai nebūtų masyvas (nors dabar turėtų būti)
                if (!empty($varData) && !is_array($varData)) {
                    $varData = [$varData];
                }

                foreach ($varData as $v) {
                    $group = $v['group'] ?? $v['group_name'] ?? '';
                    $val = $v['name'] ?? '';
                    if ($val) {
                        $varInfoParts[] = trim(($group ? "$group: " : '') . $val);
                    }
                }

                $varInfo = !empty($varInfoParts) ? implode(', ', $varInfoParts) : null;

                $stmtItem->execute([
                    $orderId,
                    $item['id'],
                    $item['quantity'],
                    $item['price'],
                    $varInfo
                ]);
            }

            $pdo->commit();

            // 3. Apmokėjimas (Paysera)
            if (class_exists('WebToPay')) {
                $configPath = __DIR__ . '/libwebtopay/config.php';
                $payseraConfig = [];
                if (file_exists($configPath)) {
                    $payseraConfig = require $configPath;
                }

                $projectId = $payseraConfig['projectid'] ?? 0;
                $signPassword = $payseraConfig['sign_password'] ?? '';
                $testMode = $payseraConfig['test'] ?? 1;

                if ($projectId > 0 && !empty($signPassword)) {
                    $_SESSION['cart'] = [];
                    $_SESSION['cart_variations'] = [];
                    if (isset($_SESSION['user_id'])) {
                        clearUserCart($pdo, (int)$_SESSION['user_id']);
                    }

                    $host = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
                    
                    $request = [
                        'projectid'     => $projectId,
                        'sign_password' => $signPassword,
                        'orderid'       => $orderId,
                        'amount'        => (int)(round($totalPayable * 100)),
                        'currency'      => 'EUR',
                        'accepturl'     => $host . '/libwebtopay/accept.php',
                        'cancelurl'     => $host . '/libwebtopay/cancel.php',
                        'callbackurl'   => $host . '/libwebtopay/callback.php',
                        'test'          => $testMode,
                        'p_firstname'   => $name,
                        'p_email'       => $email,
                    ];

                    WebToPay::redirectToPayment($request);
                    exit;
                }
            }

            $_SESSION['cart'] = [];
            $_SESSION['cart_variations'] = [];
            if (isset($_SESSION['user_id'])) {
                clearUserCart($pdo, (int)$_SESSION['user_id']);
            }

            // GUEST CHECKOUT FIX: Nukreipiame pagal tai, ar vartotojas prisijungęs
            if (isset($_SESSION['user_id'])) {
                header('Location: /orders.php');
            } else {
                header('Location: /order_success.php');
            }
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Checkout save error: " . $e->getMessage());
            $errors[] = 'Įvyko klaida formuojant užsakymą. Bandykite dar kartą.';
        }
    }
}

$finalShipping = $isFreeShipping ? 0 : ($deliveryMethod === 'locker' ? $lockerPrice : $courierPrice);
$finalTotal = $subtotal + $finalShipping;

?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Apmokėjimas | Cukrinukas.lt</title>
  <?php echo headerStyles(); ?>
  <style>
    :root {
      --bg: #f7f7fb;
      --card: #ffffff;
      --border: #e4e7ec;
      --text-main: #0f172a;
      --text-muted: #475467;
      --accent: #2563eb;
      --accent-light: #eff6ff;
      --focus-ring: rgba(37, 99, 235, 0.2);
      --danger-bg: #fef2f2;
      --danger-text: #991b1b;
    }
    * { box-sizing: border-box; }
    body { margin:0; background: var(--bg); color: var(--text-main); font-family: 'Inter', sans-serif; }
    
    .page { max-width: 1100px; margin:0 auto; padding:32px 20px 80px; }
    .page-title { margin: 0 0 24px; font-size: 28px; color: var(--text-main); letter-spacing: -0.5px; }

    .checkout-grid { display: grid; grid-template-columns: 1fr 380px; gap: 24px; align-items: start; }
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; box-shadow: 0 2px 4px -2px rgba(0, 0, 0, 0.05); padding: 24px; }
    .card-title { font-size: 18px; font-weight: 700; margin: 0 0 20px; padding-bottom: 12px; border-bottom: 1px solid var(--border); }

    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 14px; color: var(--text-main); }
    
    input[type="text"], input[type="email"], input[type="tel"], input[type="search"], textarea, select {
        width: 100%; padding: 12px 14px; border: 1px solid var(--border); border-radius: 10px; font-size: 14px; background: #fff; color: var(--text-main); transition: all .2s; outline: none;
    }
    input:focus, textarea:focus, select:focus { border-color: var(--accent); box-shadow: 0 0 0 4px var(--focus-ring); }
    textarea { min-height: 80px; resize: vertical; }

    .delivery-options { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
    .radio-chip { position: relative; display: flex; align-items: center; gap: 10px; padding: 14px; border: 1px solid var(--border); border-radius: 12px; cursor: pointer; transition: all .2s; background: #fff; }
    .radio-chip:hover { border-color: #cbd5e1; background: #f8fafc; }
    .radio-chip.checked { border-color: var(--accent); background: var(--accent-light); color: var(--accent); }
    .radio-chip input { margin: 0; accent-color: var(--accent); width: 16px; height: 16px; }
    .radio-chip span { font-weight: 600; font-size: 14px; }

    .locker-container { background: #f8fafc; border: 1px dashed var(--border); border-radius: 12px; padding: 16px; margin-top: 16px; }
    .locker-combobox { position: relative; }
    .locker-results { display: none; position: absolute; top: 100%; left: 0; right: 0; margin-top: 6px; background: #fff; border: 1px solid var(--border); border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); max-height: 240px; overflow-y: auto; z-index: 50; }
    .locker-result { padding: 10px 14px; font-size: 13px; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
    .locker-result:hover { background: #f1f5f9; }
    .locker-empty { padding: 12px; text-align: center; color: var(--text-muted); font-size: 13px; }

    .alert { padding: 12px 16px; border-radius: 12px; background: var(--danger-bg); border: 1px solid #fecaca; color: var(--danger-text); margin-bottom: 20px; font-size: 14px; }
    
    .summary-item { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
    .totals-row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 14px; color: var(--text-muted); }
    .totals-row.final { margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); color: var(--text-main); font-weight: 700; font-size: 18px; align-items: center; }

    .btn-pay { display: inline-flex; align-items: center; justify-content: center; width: 100%; padding: 14px; margin-top: 24px; border-radius: 12px; font-weight: 600; font-size: 16px; cursor: pointer; border: none; background: #0f172a; color: #fff; transition: all .2s; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
    .btn-pay:hover { background: #1e293b; transform: translateY(-1px); }

    @media (max-width: 900px) { .checkout-grid { grid-template-columns: 1fr; } .card.sticky { position: static !important; } }
    @media (max-width: 600px) { .delivery-options { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'checkout'); ?>
  
  <div class="page">
    <h1 class="page-title">Užsakymo apmokėjimas</h1>

    <div class="checkout-grid">
      <div class="main-column">
        
        <?php if ($errors): ?>
          <div class="alert">
            <?php foreach ($errors as $e): ?>
              <div>• <?php echo htmlspecialchars($e); ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="post" id="checkout-form">
          <?php echo csrfField(); ?>
          
          <div class="card" style="margin-bottom: 24px;">
            <h2 class="card-title">Kontaktinė informacija</h2>
            <div class="form-group">
                <label for="name">Vardas, pavardė</label>
                <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($name); ?>" required placeholder="Vardenis Pavardenis">
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label for="email">El. paštas</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required placeholder="adresas@pastas.lt">
                </div>
                <div class="form-group">
                    <label for="phone">Telefono numeris</label>
                    <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($phone); ?>" required placeholder="+37060000000">
                </div>
            </div>
          </div>

          <div class="card">
            <h2 class="card-title">Pristatymo būdas</h2>
            
            <div class="delivery-options">
              <label class="radio-chip <?php echo $deliveryMethod === 'courier' ? 'checked' : ''; ?>" id="chip-courier">
                <input type="radio" name="delivery_method" value="courier" <?php echo $deliveryMethod === 'courier' ? 'checked' : ''; ?>>
                <span>Kurjeris į namus<?php echo $isFreeShipping ? '' : ' (' . number_format($courierPrice, 2) . ' €)'; ?></span>
              </label>
              <label class="radio-chip <?php echo $deliveryMethod === 'locker' ? 'checked' : ''; ?>" id="chip-locker">
                <input type="radio" name="delivery_method" value="locker" <?php echo $deliveryMethod === 'locker' ? 'checked' : ''; ?>>
                <span>Paštomatas<?php echo $isFreeShipping ? '' : ' (' . number_format($lockerPrice, 2) . ' €)'; ?></span>
              </label>
            </div>

            <div id="courier-fields" style="display: <?php echo $deliveryMethod === 'courier' ? 'block' : 'none'; ?>;">
                <div class="form-group">
                    <label for="address">Pristatymo adresas</label>
                    <textarea id="address" name="address" placeholder="Gatvė, namo nr., miestas, pašto kodas"><?php echo htmlspecialchars($address); ?></textarea>
                </div>
            </div>

            <div id="locker-fields" class="locker-container" style="display: <?php echo $deliveryMethod === 'locker' ? 'block' : 'none'; ?>;">
                <div class="form-group">
                    <label for="locker-provider">Pasirinkite tinklą</label>
                    <select id="locker-provider" name="locker_provider">
                        <option value="">-- Pasirinkite --</option>
                        <?php foreach($lockerNetworks as $prov => $locs): ?>
                            <option value="<?php echo htmlspecialchars($prov); ?>" <?php echo $lockerProvider === $prov ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($prov)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="locker-location-input">Paštomato paieška</label>
                    <div class="locker-combobox">
                        <input id="locker-location-input" name="locker_location" type="search" 
                               placeholder="Pradėkite rašyti adresą..." 
                               value="<?php echo htmlspecialchars($lockerLocationTitle); ?>" 
                               autocomplete="off">
                        
                        <input type="hidden" id="locker-id-hidden" name="locker_id_hidden" value="<?php echo (int)$lockerId; ?>">
                        
                        <div id="locker-location-results" class="locker-results"></div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label for="locker-request">Kitas paštomatas (jei neradote sąraše)</label>
                    <input type="text" id="locker-request" name="locker_request" value="<?php echo htmlspecialchars($lockerRequest); ?>" placeholder="Pvz.: artimiausias PC Akropolis">
                </div>
            </div>
          </div>
        </form>
      </div>

      <div class="sidebar">
        <div class="card sticky" style="position: sticky; top: 100px;">
            <h2 class="card-title">Jūsų užsakymas</h2>
            
            <div style="margin-bottom: 20px;">
                <?php foreach ($items as $item): ?>
                    <div class="summary-item">
                        <div>
                            <div style="font-weight:500; margin-bottom:2px;"><?php echo htmlspecialchars($item['title']); ?></div>
                            <div style="font-size:12px; color:var(--text-muted); display:flex; flex-direction:column; gap:2px;">
                                <?php 
                                    $varData = $item['variation'] ?? [];
                                    if (!empty($varData)): ?>
                                        <?php foreach ($varData as $v): ?>
                                            <?php 
                                                $vGroup = $v['group'] ?? $v['group_name'] ?? 'Variacija';
                                                $vName = $v['name'] ?? '';
                                            ?>
                                            <?php if($vName): ?>
                                                <span><?php echo htmlspecialchars($vGroup); ?>: <strong><?php echo htmlspecialchars($vName); ?></strong></span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    
                                    <?php if ($item['quantity'] > 1): ?>
                                        <span>Kiekis: <?php echo $item['quantity']; ?> vnt.</span>
                                    <?php endif; ?>
                            </div>
                        </div>
                        <div style="font-weight:600;"><?php echo number_format($item['line_total'], 2); ?> €</div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="totals-row">
                <span>Tarpinė suma</span>
                <span><?php echo number_format($subtotal, 2); ?> €</span>
            </div>
            
            <div class="totals-row">
                <span>Pristatymas</span>
                <span id="shipping-summary"><?php echo number_format($finalShipping, 2); ?> €</span>
            </div>

            <div class="totals-row final">
                <span>Viso mokėti</span>
                <span id="payable-total"><?php echo number_format($finalTotal, 2); ?> €</span>
            </div>

            <button type="submit" form="checkout-form" class="btn-pay">Apmokėti</button>
            
            <p style="margin:16px 0 0; font-size:12px; color:var(--text-muted); text-align:center; line-height:1.5;">
                Paspausdami „Apmokėti“, būsite nukreipti į Paysera sistemą.
            </p>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function() {
      // Duomenys iš PHP
      const lockerOptions = <?php echo json_encode($lockerNetworks, JSON_UNESCAPED_UNICODE); ?>;
      const prices = { 
          courier: <?php echo json_encode($courierPrice); ?>, 
          locker: <?php echo json_encode($lockerPrice); ?> 
      };
      const isFree = <?php echo $isFreeShipping ? 'true' : 'false'; ?>;
      const subtotal = <?php echo json_encode($subtotal); ?>;

      // DOM elementai
      const form = document.getElementById('checkout-form');
      const courierFields = document.getElementById('courier-fields');
      const lockerFields = document.getElementById('locker-fields');
      const addressInput = document.getElementById('address');
      
      const lockerProviderSelect = document.getElementById('locker-provider');
      const lockerSearchInput = document.getElementById('locker-location-input');
      const lockerIdHidden = document.getElementById('locker-id-hidden');
      const lockerResults = document.getElementById('locker-location-results');
      const lockerRequestInput = document.getElementById('locker-request');
      
      const radioCourier = document.querySelector('input[value="courier"]');
      const radioLocker = document.querySelector('input[value="locker"]');
      const chipCourier = document.getElementById('chip-courier');
      const chipLocker = document.getElementById('chip-locker');
      
      const elShipping = document.getElementById('shipping-summary');
      const elTotal = document.getElementById('payable-total');

      function formatPrice(num) { return Number(num).toFixed(2) + ' €'; }

      function updateUI(method) {
        // Kainos
        const shipCost = isFree ? 0 : prices[method];
        if (elShipping) elShipping.textContent = formatPrice(shipCost);
        if (elTotal) elTotal.textContent = formatPrice(subtotal + shipCost);

        // Stiliai ir matomumas
        if (method === 'courier') {
            chipCourier.classList.add('checked');
            chipLocker.classList.remove('checked');
            courierFields.style.display = 'block';
            lockerFields.style.display = 'none';
            
            addressInput.setAttribute('required', 'required');
            lockerProviderSelect.removeAttribute('required');
        } else {
            chipCourier.classList.remove('checked');
            chipLocker.classList.add('checked');
            courierFields.style.display = 'none';
            lockerFields.style.display = 'block';
            
            addressInput.removeAttribute('required');
            lockerProviderSelect.setAttribute('required', 'required');
        }
      }

      // Klausomės pristatymo būdo keitimo
      [radioCourier, radioLocker].forEach(r => {
          if(!r) return;
          r.addEventListener('change', e => updateUI(e.target.value));
      });

      // Paštomatų logika
      function renderLockers(provider) {
          lockerResults.innerHTML = '';
          const list = lockerOptions[provider] || [];
          const term = lockerSearchInput.value.toLowerCase().trim();

          const filtered = list.filter(l => {
              if (!term) return true;
              const text = (l.title + ' ' + l.address + ' ' + (l.note||'')).toLowerCase();
              return text.includes(term);
          });

          if (filtered.length === 0) {
              const div = document.createElement('div');
              div.className = 'locker-empty';
              div.textContent = provider ? 'Nerasta paštomatų pagal paiešką' : 'Pirmiausia pasirinkite tinklą';
              lockerResults.appendChild(div);
          } else {
              filtered.forEach(l => {
                  const div = document.createElement('div');
                  div.className = 'locker-result';
                  div.textContent = l.title + ' — ' + l.address;
                  div.addEventListener('mousedown', () => { // mousedown veikia prieš blur
                      lockerSearchInput.value = l.title + ' — ' + l.address;
                      lockerIdHidden.value = l.id;
                      lockerResults.style.display = 'none';
                  });
                  lockerResults.appendChild(div);
              });
          }
          lockerResults.style.display = 'block';
      }

      if (lockerProviderSelect) {
          lockerProviderSelect.addEventListener('change', () => {
              lockerSearchInput.value = '';
              lockerIdHidden.value = '0';
              renderLockers(lockerProviderSelect.value);
          });
      }

      if (lockerSearchInput) {
          lockerSearchInput.addEventListener('focus', () => renderLockers(lockerProviderSelect.value));
          lockerSearchInput.addEventListener('input', () => {
              lockerIdHidden.value = '0'; // Reset ID jei vartotojas keičia tekstą
              renderLockers(lockerProviderSelect.value);
          });
          lockerSearchInput.addEventListener('blur', () => {
              setTimeout(() => lockerResults.style.display = 'none', 200);
          });
      }

      // Formos submit validacija
      const btnPay = document.querySelector('.btn-pay');
      if (btnPay) {
          btnPay.addEventListener('click', (e) => {
             const method = document.querySelector('input[name="delivery_method"]:checked').value;
             if (method === 'locker') {
                 // Jei nepasirinktas ID iš sąrašo ir neįrašytas rankinis prašymas
                 const hasId = lockerIdHidden.value && lockerIdHidden.value !== '0';
                 const hasRequest = lockerRequestInput.value.trim().length > 0;
                 
                 if (!hasId && !hasRequest) {
                     e.preventDefault();
                     alert('Pasirinkite paštomatą iš sąrašo arba įrašykite pageidavimą.');
                     lockerSearchInput.focus();
                 }
             }
          });
      }

      // Init
      const currentMethod = document.querySelector('input[name="delivery_method"]:checked')?.value || 'courier';
      updateUI(currentMethod);

    })();
  </script>

  <?php renderFooter($pdo); ?>
</body>
</html>
