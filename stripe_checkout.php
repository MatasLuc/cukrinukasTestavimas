<?php
// stripe_checkout.php
session_start();
require_once __DIR__ . '/lib/stripe/init.php';
require_once __DIR__ . '/db.php';

// Pagalbinė funkcija env.php kintamiesiems
if (!function_exists('requireEnv')) {
    function requireEnv($key) {
        $envPath = __DIR__ . '/.env';
        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                list($name, $value) = explode('=', $line, 2);
                if (trim($name) === $key) return trim($value);
            }
        }
        return getenv($key);
    }
}

$stripeSecret = requireEnv('STRIPE_SECRET_KEY');
$domain = requireEnv('DOMAIN');

if (empty($_SESSION['user_id']) || !isset($_GET['order_id'])) {
    header('Location: /orders.php');
    exit;
}

$orderId = (int)$_GET['order_id'];
$pdo = getPdo();

// 1. Gauname užsakymo informaciją (Total jau yra su pristatymu)
$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND user_id = ?');
$stmt->execute([$orderId, $_SESSION['user_id']]);
$order = $stmt->fetch();

if (!$order) die("Užsakymas nerastas");

// Jei jau apmokėta
if (stripos($order['status'], 'apmokėta') !== false) {
    header('Location: /orders.php');
    exit;
}

// 2. Gauname prekes sąrašui sugeneruoti
$itemStmt = $pdo->prepare('SELECT oi.*, p.title FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?');
$itemStmt->execute([$orderId]);
$items = $itemStmt->fetchAll();

$lineItems = [];
$itemsTotal = 0;

foreach ($items as $item) {
    $price = (float)$item['price'];
    $qty = (int)$item['quantity'];
    $itemsTotal += $price * $qty;
    
    $lineItems[] = [
        'price_data' => [
            'currency' => 'eur',
            'product_data' => [
                'name' => $item['title'],
            ],
            'unit_amount' => (int)(round($price, 2) * 100),
        ],
        'quantity' => $qty,
    ];
}

// 3. Apskaičiuojame pristatymo kainą kaip skirtumą
// (Order Total DB) - (Items Total) = Shipping Cost
$orderTotal = (float)$order['total'];
$shippingCost = $orderTotal - $itemsTotal;

// Jei yra teigiamas skirtumas, pridedame eilutę pristatymui
if ($shippingCost > 0.01) {
    $lineItems[] = [
        'price_data' => [
            'currency' => 'eur',
            'product_data' => [
                'name' => 'Pristatymas',
            ],
            'unit_amount' => (int)(round($shippingCost, 2) * 100),
        ],
        'quantity' => 1,
    ];
}

\Stripe\Stripe::setApiKey($stripeSecret);

try {
    $checkout_session = \Stripe\Checkout\Session::create([
        'line_items' => $lineItems,
        'mode' => 'payment',
        'success_url' => $domain . '/stripe_return.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $domain . '/orders.php',
        'metadata' => [
            'order_id' => $orderId
        ],
        'customer_email' => $order['customer_email'] ?? null,
    ]);

    // Išsaugome sesijos ID
    $pdo->prepare("UPDATE orders SET stripe_session_id = ? WHERE id = ?")->execute([$checkout_session->id, $orderId]);

    header("Location: " . $checkout_session->url);
    exit;
} catch (Exception $e) {
    echo "Stripe klaida: " . $e->getMessage();
}
?>
