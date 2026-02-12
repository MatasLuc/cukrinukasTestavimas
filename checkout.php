<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
// Naudojame require_once, kad išvengtume dvigubo failų įtraukimo klaidų
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/shipping_helper.php'; 

$pdo = getPdo();

if (empty($_SESSION['user_id'])) {
    $_SESSION['redirect_after_login'] = 'checkout.php';
    header('Location: /login.php');
    exit;
}

if (empty($_SESSION['cart']) || count($_SESSION['cart']) === 0) {
    header('Location: /products.php');
    exit;
}

// 1. Gauname nustatymus iš DB su apsauga (jei lentelė dar nesukurta)
try {
    $shippingSettings = getShippingSettings($pdo);
} catch (Exception $e) {
    // Jei įvyko klaida (pvz., nėra lentelės), naudojame numatytuosius nustatymus
    $shippingSettings = [
        'base_price' => 0,
        'courier_price' => 4.99,
        'locker_price' => 2.99,
        'free_over' => 50.00
    ];
    // Galima įrašyti klaidą į logą: error_log($e->getMessage());
}

// 2. Skaičiuojame krepšelio sumą (Prekės)
$cartItemsTotal = 0;
$productsInCart = [];
$ids = array_keys($_SESSION['cart']);

if (!empty($ids)) {
    // Saugus placeholderių generavimas
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

// FORMOS PATEIKIMAS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $method = $_POST['delivery_method'] ?? 'pickup';
    $notes = trim($_POST['notes'] ?? '');

    // Validacija
    if (empty($name)) $errors[] = 'Būtina įvesti vardą.';
    if (empty($phone)) $errors[] = 'Būtina įvesti telefoną.';
    if ($method === 'courier' && empty($address)) $errors[] = 'Kurjeriui būtinas adresas.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // 3. Galutinis kainos skaičiavimas serverio pusėje (saugumas)
            $shippingPrice = calculateShippingPrice($shippingSettings, $cartItemsTotal, $method);
            $finalTotal = $cartItemsTotal + $shippingPrice;

            // Formuojame delivery details JSON
            $deliveryDetails = json_encode([
                'method' => $method, // 'courier', 'locker', 'pickup'
                'shipping_price' => $shippingPrice,
                'phone' => $phone,
                'email' => $email,
                'notes' => $notes,
                'locker_address' => $_POST['locker_address'] ?? ''
            ]);

            // Įrašome užsakymą
            // Pastaba: Įsitikinkite, kad 'orders' lentelė turi stulpelį 'delivery_details'
            $stmtOrder = $pdo->prepare("
                INSERT INTO orders 
                (user_id, total, status, created_at, customer_name, customer_address, delivery_method, delivery_details, customer_email) 
                VALUES (?, ?, 'Laukiama apmokėjimo', NOW(), ?, ?, ?, ?, ?)
            ");
            
            $stmtOrder->execute([
                $_SESSION['user_id'],
                $finalTotal,
                $name,
                $address, // Adresas
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
            unset($_SESSION['cart']);

            // 4. Nukreipiame į Stripe
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
        .form-control { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; }
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
            // Paskaičiuojame kainas JS'ui
            // Naudojame number_format, kad užtikrintume teisingą formatą (pvz. 2.99)
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

            <div class="form-group" id="address-field" style="display:none;">
                <label class="form-label">Pristatymo adresas</label>
                <input type="text" name="address" class="form-control" placeholder="Gatvė, namo nr., miestas, pašto kodas" value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>">
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
        // PHP kintamieji į JS
        // Naudojame number_format su '.' skyrikliu, kad JS nesutriktų dėl kablelių
        const cartTotal = <?php echo number_format($cartItemsTotal, 2, '.', ''); ?>;
        const prices = {
            pickup: 0.00,
            locker: <?php echo $isFree ? '0.00' : number_format($lockerCost, 2, '.', ''); ?>,
            courier: <?php echo $isFree ? '0.00' : number_format($courierCost, 2, '.', ''); ?>
        };

        function updateUI(method) {
            const addrField = document.getElementById('address-field');
            const shipDisplay = document.getElementById('shipping-display');
            const totalDisplay = document.getElementById('total-display');

            // Adreso lauko rodymas
            if (method === 'courier') {
                addrField.style.display = 'block';
                addrField.querySelector('input').setAttribute('required', 'required');
            } else {
                addrField.style.display = 'none';
                addrField.querySelector('input').removeAttribute('required');
            }

            // Kainų atnaujinimas
            const shipPrice = parseFloat(prices[method] || 0);
            const finalPrice = cartTotal + shipPrice;

            shipDisplay.textContent = shipPrice.toFixed(2) + ' €';
            totalDisplay.textContent = finalPrice.toFixed(2) + ' €';
        }
    </script>
</body>
</html>
