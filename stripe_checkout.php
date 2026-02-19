<?php
// stripe_checkout.php
// Įjungiame klaidų rodymą
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/stripe/init.php';
require_once __DIR__ . '/order_functions.php'; // Pridėta funkcijų biblioteka

session_start();
$pdo = getPdo();

// Konfigūracija
$stripeKey = getenv('STRIPE_SECRET_KEY') ?: ($_ENV['STRIPE_SECRET_KEY'] ?? null);
$baseUrl = getenv('BASE_URL') ?: ($_ENV['BASE_URL'] ?? null);

if (!$baseUrl) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $baseUrl = $protocol . "://" . $_SERVER['HTTP_HOST'];
}

if (!$stripeKey) {
    die("Klaida: Nėra nustatytas STRIPE_SECRET_KEY.");
}

\Stripe\Stripe::setApiKey($stripeKey);

// --- 0. GAUNAME UŽSAKYMO INFORMACIJĄ (Dėl pristatymo kainos) ---
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$order = null;

if ($orderId > 0) {
    $stmtOrder = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
    $stmtOrder->execute([$orderId]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);
}

if (!$order) {
    // Jei nėra užsakymo ID, negalime tęsti, nes nežinome pristatymo kainos
    die("Klaida: Nerastas užsakymo ID. Grįžkite į krepšelį.");
}

// --- KREPŠELIO SURINKIMAS ---
$line_items = [];
$has_items = false;

// 1. PARDUOTUVĖS PREKĖS (Standartinis krepšelis)
if (isset($_SESSION['cart']) && count($_SESSION['cart']) > 0) {
    // Paimame IDs
    $ids = array_keys($_SESSION['cart']);
    // Išvalome IDs nuo variacijų (pvz. 12_L_Red -> 12)
    $cleanIds = [];
    foreach ($ids as $idStr) {
        $parts = explode('_', (string)$idStr);
        $cleanIds[] = (int)$parts[0];
    }
    $cleanIds = array_unique($cleanIds);

    if (!empty($cleanIds)) {
        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders)");
        $stmt->execute($cleanIds);
        $shop_products_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Pasidarome map'ą patogesniam darbui
        $shop_products = [];
        foreach ($shop_products_raw as $p) {
            $shop_products[$p['id']] = $p;
        }

        foreach ($_SESSION['cart'] as $cartKey => $qty) {
            $parts = explode('_', (string)$cartKey);
            $productId = (int)$parts[0];
            
            if (!isset($shop_products[$productId])) continue;
            
            $product = $shop_products[$productId];
            
            // --- KAINOS LOGIKA (Svarbu: Sale Price) ---
            $basePrice = floatval($product['price']);
            
            // Tikriname ar yra akcija
            if (!empty($product['sale_price']) && $product['sale_price'] > 0 && $product['sale_price'] < $product['price']) {
                $basePrice = floatval($product['sale_price']);
            }

            // Pridedame variacijų kainų skirtumus (jei yra)
            $variationDelta = 0;
            if (isset($_SESSION['cart_variations'][$cartKey]) && is_array($_SESSION['cart_variations'][$cartKey])) {
                foreach ($_SESSION['cart_variations'][$cartKey] as $var) {
                    $variationDelta += (float)($var['delta'] ?? 0);
                }
            }
            
            $finalPrice = $basePrice + $variationDelta;
            
            // Konvertuojam į centus
            $unit_amount = round($finalPrice * 100); 

            $line_items[] = [
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => [
                        'name' => $product['title'],
                        'description' => 'Parduotuvės prekė',
                        'metadata' => [
                            'product_id' => $product['id'],
                            'type' => 'shop'
                        ]
                    ],
                    'unit_amount' => $unit_amount,
                ],
                'quantity' => $qty,
            ];
            $has_items = true;
        }
    }
}

// 2. BENDRUOMENĖS PREKĖS (Community krepšelis)
if (isset($_SESSION['cart_community']) && count($_SESSION['cart_community']) > 0) {
    $c_ids = array_keys($_SESSION['cart_community']);
    if (!empty($c_ids)) {
        $placeholders = implode(',', array_fill(0, count($c_ids), '?'));
        // Prijungiame vartotojus
        $stmt = $pdo->prepare("SELECT m.*, u.stripe_account_id, u.stripe_onboarding_completed 
                               FROM community_listings m 
                               JOIN users u ON m.user_id = u.id 
                               WHERE m.id IN ($placeholders)");
        $stmt->execute($c_ids);
        $comm_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($comm_products as $item) {
            if (empty($item['stripe_account_id']) || $item['stripe_onboarding_completed'] == 0) {
                continue; 
            }

            $qty = (int)$_SESSION['cart_community'][$item['id']]; 
            $price = floatval($item['price']);
            $unit_amount = round($price * 100);

            $line_items[] = [
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => [
                        'name' => $item['title'] . ' (iš nario)',
                        'description' => 'Bendruomenės prekė',
                        'metadata' => [
                            'community_item_id' => $item['id'],
                            'seller_id' => $item['user_id'],
                            'type' => 'community'
                        ]
                    ],
                    'unit_amount' => $unit_amount,
                ],
                'quantity' => $qty,
            ];
            $has_items = true;
        }
    }
}

// 3. PRISTATYMAS
// Imame tikslią sumą iš DB order įrašo
if ($order && floatval($order['shipping_amount']) > 0) {
    $shippingCost = floatval($order['shipping_amount']);
    $line_items[] = [
        'price_data' => [
            'currency' => 'eur',
            'product_data' => [
                'name' => 'Pristatymas',
                'description' => ($order['delivery_method'] == 'courier' ? 'Kurjeris' : 'Paštomatas'),
            ],
            'unit_amount' => round($shippingCost * 100),
        ],
        'quantity' => 1,
    ];
}

// 4. PREKIŲ KREPŠELIO TIKRINIMAS
if (!$has_items) {
    header("Location: cart.php?error=empty");
    exit;
}

// --- SUKURIAME LAUKIANČIUS BENDRUOMENĖS UŽSAKYMUS PRIEŠ MOKĖJIMĄ ---
createPendingCommunityOrders($pdo, $orderId, $_SESSION['user_id'] ?? 0);

// 5. KURIAME STRIPE SESIJĄ
try {
    $sessionConfig = [
        'payment_method_types' => ['card'],
        'line_items' => $line_items,
        'mode' => 'payment',
        'client_reference_id' => $orderId, // SVARBU: Susiejame Stripe sesiją su DB užsakymo ID
        'success_url' => $baseUrl . '/stripe_success.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $baseUrl . '/stripe_return.php?order_id=' . $orderId, // Grąžiname į spec. puslapį arba atgal
        'metadata' => [
            'order_type' => 'mixed_cart',
            'order_id' => $orderId // Labai svarbu susieti su DB užsakymu
        ],
        'payment_intent_data' => [
            'metadata' => [
                'order_type' => 'mixed_cart',
                'order_id' => $orderId
            ],
        ],
    ];
    
    // Kliento el. paštas (jei yra užsakyme)
    if (!empty($order['customer_email'])) {
        $sessionConfig['customer_email'] = $order['customer_email'];
    }

    $checkout_session = \Stripe\Checkout\Session::create($sessionConfig);

    header("Location: " . $checkout_session->url);
    exit;

} catch (Exception $e) {
    die('Stripe Checkout Klaida: ' . $e->getMessage());
}
?>
