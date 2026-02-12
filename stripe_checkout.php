<?php
// stripe_checkout.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';

// Įkeliame Stripe biblioteką
if (file_exists(__DIR__ . '/lib/stripe/init.php')) {
    require_once __DIR__ . '/lib/stripe/init.php';
} else {
    die("Klaida: Nerasta Stripe biblioteka (lib/stripe/init.php)");
}

session_start();
$pdo = getPdo();

if (empty($_GET['order_id'])) {
    die("Klaida: Nenurodytas užsakymo ID.");
}

$orderId = (int)$_GET['order_id'];

// Patikriname užsakymą
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die("Klaida: Užsakymas nerastas.");
}

// Jei jau apmokėta
if ($order['status'] === 'Apmokėta' || $order['status'] === 'Vykdomas') {
    header("Location: /order_success.php?session_id=already_paid");
    exit;
}

// Gauname prekes
$stmtItems = $pdo->prepare("
    SELECT oi.*, p.title 
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$stmtItems->execute([$orderId]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY'] ?? '');

$lineItems = [];
$itemsTotal = 0;

foreach ($items as $item) {
    $productName = $item['title'] ?? 'Prekė #' . $item['product_id'];
    $lineItems[] = [
        'price_data' => [
            'currency' => 'eur',
            'product_data' => [
                'name' => $productName,
            ],
            'unit_amount' => (int)round($item['price'] * 100),
        ],
        'quantity' => (int)$item['quantity'],
    ];
    $itemsTotal += $item['price'] * $item['quantity'];
}

// Pristatymas
$shippingCost = $order['total'] - $itemsTotal;
if ($shippingCost > 0.01) {
    $lineItems[] = [
        'price_data' => [
            'currency' => 'eur',
            'product_data' => [
                'name' => 'Pristatymas',
            ],
            'unit_amount' => (int)round($shippingCost * 100),
        ],
        'quantity' => 1,
    ];
}

$domain = 'https://' . $_SERVER['HTTP_HOST'];

try {
    $checkout_session = \Stripe\Checkout\Session::create([
        'payment_method_types' => ['card'],
        'line_items' => $lineItems,
        'mode' => 'payment',
        
        // 1. Pagrindinis ID sesijai
        'client_reference_id' => $orderId, 
        
        'customer_email' => $order['customer_email'],
        
        // 2. Metadata sesijai
        'metadata' => [
            'order_id' => $orderId,
            'customer_name' => $order['customer_name']
        ],
        
        // 3. SVARBU: Metadata pačiam mokėjimui (PaymentIntent), kad veiktų payment_intent.succeeded
        'payment_intent_data' => [
            'metadata' => [
                'order_id' => $orderId
            ]
        ],

        'success_url' => $domain . '/order_success.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $domain . '/checkout.php',
    ]);

    header("HTTP/1.1 303 See Other");
    header("Location: " . $checkout_session->url);
    exit;
} catch (Exception $e) {
    die("Stripe inicijavimo klaida: " . $e->getMessage());
}
