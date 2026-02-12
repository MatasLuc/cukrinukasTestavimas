<?php
// Įjungiame klaidų rodymą, kad matytume, jei kas negerai (galima išjungti production aplinkoje)
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

// 4. Gauname pristatymo kainų nustatymus
$shippingSettings = getShippingSettings($pdo);

// 5. GAUNAME PAŠTOMATUS IŠ DB (Pataisyta dalis)
$lockerList = [];
try {
    // Rūšiuojame pagal pavadinimą (title), nes 'city' stulpelio DB nėra
    $stmtLockers = $pdo->query("SELECT * FROM parcel_lockers ORDER BY title ASC");
    $lockerList = $stmtLockers->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Jei lentelės nėra ar kita klaida, sąrašas liks tuščias
    error_log("Klaida gaunant paštomatus: " . $e->getMessage());
}

// 6. Skaičiuojame krepšelio sumą
$cartItemsTotal = 0;
$productsInCart = [];
$ids = array_keys($_SESSION['cart']);

if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, title, price FROM products WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($products as $p) {
        $qty = $_SESSION['cart'][$p['id']];
        $cartItemsTotal += $p['price'] * $qty;
        $productsInCart[] = [
            'id' => $p['id'],
            'price' => $p['price'],
            'qty' => $qty
        ];
    }
}

// 7. FORMOS PATEIKIMAS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    
    // CSRF VALIDACIJA
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Saugumo klaida (netinkamas CSRF raktažodis). Pabandykite perkrauti puslapį.';
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $method = $_POST['delivery_method'] ?? 'pickup';
    $notes = trim($_POST['notes'] ?? '');
    $selectedLocker = trim($_POST['locker_select'] ?? '');

    // Laukų validacija
    if (empty($name)) $errors[] = 'Būtina įvesti vardą.';
    if (empty($phone)) $errors[] = 'Būtina įvesti telefoną.';
    
    if ($method === 'courier' && empty($address)) {
        $errors[] = 'Pasirinkus kurjerį, būtina nurodyti adresą.';
    }
    
    if ($method === 'locker' && empty($selectedLocker)) {
        $errors[] = 'Būtina pasirinkti paštomatą iš sąrašo.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Perskaičiuojame kainas serverio pusėje
            $shippingPrice = calculateShippingPrice($shippingSettings, $cartItemsTotal, $method);
            $finalTotal = $cartItemsTotal + $shippingPrice;

            // Formuojame delivery details JSON
            $deliveryDetailsData = [
                'method' => $method,
                'shipping_price' => $shippingPrice,
                'phone' => $phone,
                'email' => $email,
                'notes' => $notes
            ];

            // Jei pasirinktas paštomatas, įrašome jį
            if ($method === 'locker') {
                $deliveryDetailsData['locker_address'] = $selectedLocker;
                // Kad būtų patogiau admin panelėje, galime į pagrindinį adreso lauką įrašyti paštomatą
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

            $pdo->commit();
            
            // Išvalome krepšelį
            unset($_SESSION['cart']);

            // Nukreipiame į Stripe apmokėjimą
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
        .total-row { font-size: 20px; font-weight: 800; color: #0f172a; margin-top: 15px; }
        .btn-pay { width: 100%; padding: 15px; background: #10b981; color: white; border: none; border-radius: 8px; font-size: 18px; font-weight: 600; cursor: pointer; }
        .btn-pay:hover { background: #059669; }
        .free-shipping-notice { background: #ecfdf5; color: #065f46; padding: 10px; border-radius: 6px; margin-bottom: 20px; text-align: center; border: 1px solid #a7f3d0; }
        .hidden-field { display: none; }
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

        <?php 
            $courierCost = calculateShippingPrice($shippingSettings, $cartItemsTotal, 'courier');
            $lockerCost = calculateShippingPrice($shippingSettings, $cartItemsTotal, 'locker');
            $isFree = ($shippingSettings['free_over'] > 0 && $cartItemsTotal >= $shippingSettings['free_over']);
        ?>

        <?php if($isFree): ?>
            <div class="free-shipping-notice">🎉 Jums taikomas nemokamas pristatymas!</div>
        <?php elseif($shippingSettings['free_over'] > 0): ?>
            <div style="text-align:center; font-size:13px; color:#64748b; margin-bottom:20px;">
                Trūksta <?php echo number_format($shippingSettings['free_over'] - $cartItemsTotal, 2); ?> € iki nemokamo pristatymo.
            </div>
        <?php endif; ?>

        <form method="POST">
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
                                // NAUDOJAME TAVO DB STULPELIUS: title, address
                                $title = htmlspecialchars($locker['title'] ?? '');
                                $address = htmlspecialchars($locker['address'] ?? '');
                                
                                // Suformuojame matomą tekstą
                                $displayText = "$title ($address)";
                                // Suformuojame reikšmę (kas bus įrašyta į DB)
                                $valueText = "$title - $address"; 
                            ?>
                            <option value="<?php echo $valueText; ?>"><?php echo $displayText; ?></option>
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
                <div class="row">
                    <span>Pristatymas:</span>
                    <span id="shipping-display">0.00 €</span>
                </div>
                <div class="row total-row">
                    <span>VISO MOKĖTI:</span>
                    <span id="total-display"><?php echo number_format($cartItemsTotal, 2); ?> €</span>
                </div>
            </div>
            
            <br>
            <button type="submit" class="btn-pay">Apmokėti (Stripe)</button>
        </form>
    </div>

    <?php renderFooter($pdo); ?>

    <script>
        const cartTotal = <?php echo number_format($cartItemsTotal, 2, '.', ''); ?>;
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

            // 1. Reset (paslepiame viską, nuimame required)
            addrField.style.display = 'none';
            lockerField.style.display = 'none';
            addrInput.removeAttribute('required');
            lockerSelect.removeAttribute('required');

            // 2. Logic (rodome ko reikia pagal pasirinkimą)
            if (method === 'courier') {
                addrField.style.display = 'block';
                addrInput.setAttribute('required', 'required');
            } else if (method === 'locker') {
                lockerField.style.display = 'block';
                lockerSelect.setAttribute('required', 'required');
            }

            // 3. Price update
            const shipPrice = parseFloat(prices[method] || 0);
            const finalPrice = cartTotal + shipPrice;

            shipDisplay.textContent = shipPrice.toFixed(2) + ' €';
            totalDisplay.textContent = finalPrice.toFixed(2) + ' €';
        }

        // Paleidžiame funkciją užsikrovus, kad nustatytų pradinę būseną
        document.addEventListener('DOMContentLoaded', function() {
            // Randame pažymėtą radio mygtuką
            const selected = document.querySelector('input[name="delivery_method"]:checked');
            if (selected) {
                updateUI(selected.value);
            }
        });
    </script>
</body>
</html>
