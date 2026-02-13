<?php
// Įjungiame klaidų rodymą
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/stripe/init.php';

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

// --- KREPŠELIO SURINKIMAS ---
$line_items = [];
$cart_total = 0;
$has_items = false;

// 1. PARDUOTUVĖS PREKĖS (Standartinis krepšelis)
if (isset($_SESSION['cart']) && count($_SESSION['cart']) > 0) {
    // Paimame IDs
    $ids = array_keys($_SESSION['cart']);
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $shop_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($shop_products as $product) {
            $qty = $_SESSION['cart'][$product['id']];
            $price = floatval($product['price']); // Čia reiktų naudoti sale_price jei yra
            
            // Konvertuojam į centus
            $unit_amount = round($price * 100); 

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
        // Prijungiame vartotojus, kad gautume info apie pardavėją (nors apmokėjimas eina mums)
        $stmt = $pdo->prepare("SELECT m.*, u.stripe_account_id, u.stripe_onboarding_completed 
                               FROM community_market m 
                               JOIN users u ON m.user_id = u.id 
                               WHERE m.id IN ($placeholders)");
        $stmt->execute($c_ids);
        $comm_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($comm_products as $item) {
            // Patikrinimas: ar pardavėjas gali gauti pinigus?
            // Jei negali - galime tiesiog ignoruoti arba mesti klaidą. 
            // Čia ignoruojame, kad nesustotų visas krepšelis, arba galima išmesti įspėjimą.
            if (empty($item['stripe_account_id']) || $item['stripe_onboarding_completed'] == 0) {
                continue; // Praleidžiam prekę
            }

            $qty = 1; // Bendruomenės prekės dažniausiai unikalios
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

if (!$has_items) {
    header("Location: cart.php?error=empty");
    exit;
}

// 3. KURIAME STRIPE SESIJĄ (Vienas bendras apmokėjimas)
try {
    $checkout_session = \Stripe\Checkout\Session::create([
        'payment_method_types' => ['card'],
        'line_items' => $line_items,
        'mode' => 'payment',
        
        // Metadata padės mums atpažinti užsakymą success puslapyje
        'payment_intent_data' => [
            'metadata' => [
                'order_type' => 'mixed_cart',
                // Pastaba: Stripe metadata turi limitą.
                // Mes pasitikėsime $_SESSION duomenimis success puslapyje.
            ],
        ],
        'metadata' => [
            'order_type' => 'mixed_cart'
        ],
        
        'success_url' => $baseUrl . '/stripe_success.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $baseUrl . '/cart.php',
    ]);

    header("Location: " . $checkout_session->url);
    exit;

} catch (Exception $e) {
    die('Stripe Checkout Klaida: ' . $e->getMessage());
}
?>
