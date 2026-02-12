<?php
// stripe_checkout.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/vendor/autoload.php';

session_start();

$pdo = getPdo();

if (empty($_GET['order_id'])) {
    die("Klaida: Nenurodytas užsakymo ID.");
}

$orderId = (int)$_GET['order_id'];

// Patikriname ar užsakymas egzistuoja ir priklauso vartotojui
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
$stmt->execute([$orderId, $_SESSION['user_id'] ?? 0]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die("Klaida: Užsakymas nerastas.");
}

// Paimame prekes Stripe krepšeliui
$stmtItems = $pdo->prepare("
    SELECT oi.*, p.title 
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$stmtItems->execute([$orderId]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

$lineItems = [];
$itemsTotal = 0;

foreach ($items as $item) {
    $lineItems[] = [
        'price_data' => [
            'currency' => 'eur',
            'product_data' => [
                'name' => $item['title'],
            ],
            'unit_amount' => (int)round($item['price'] * 100),
        ],
        'quantity' => $item['quantity'],
    ];
    $itemsTotal += $item['price'] * $item['quantity'];
}

// Apskaičiuojame ir pridedame pristatymo mokestį
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
        // SVARBU: client_reference_id perduoda užsakymo ID webhook'ui
        'client_reference_id' => $orderId,
        'customer_email' => $order['customer_email'],
        'success_url' => $domain . '/order_success.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $domain . '/checkout.php',
    ]);

    header("HTTP/1.1 303 See Other");
    header("Location: " . $checkout_session->url);
} catch (Exception $e) {
    die("Stripe klaida: " . $e->getMessage());
}
