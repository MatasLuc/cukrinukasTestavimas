<?php
// stripe_checkout.php
session_start();

// Svarbu: Nurodome kelią iki tavo rankiniu būdu įkeltos bibliotekos
require_once __DIR__ . '/lib/stripe/init.php';
require_once __DIR__ . '/db.php';

// Funkcija aplinkos kintamųjų gavimui (iš tavo db.php arba env.php)
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

if (!$stripeSecret || !$domain) {
    die('Klaida: Nerasti Stripe nustatymai .env faile');
}

if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if (!$orderId) {
    die('Neteisingas užsakymo ID');
}

$pdo = getPdo();

// Patikriname ar užsakymas priklauso vartotojui
$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND user_id = ?');
$stmt->execute([$orderId, $_SESSION['user_id']]);
$order = $stmt->fetch();

if (!$order) {
    die('Užsakymas nerastas.');
}

// Jei jau apmokėta
if (stripos($order['status'], 'apmokėta') !== false) {
    header('Location: /orders.php');
    exit;
}

// Gauname prekes Stripe formatui
$itemStmt = $pdo->prepare('
    SELECT oi.*, p.title 
    FROM order_items oi 
    JOIN products p ON p.id = oi.product_id 
    WHERE oi.order_id = ?
');
$itemStmt->execute([$orderId]);
$items = $itemStmt->fetchAll();

$lineItems = [];
$calculatedTotal = 0;

foreach ($items as $item) {
    // Stripe kaina centais (int)
    $priceInCents = (int)(round((float)$item['price'], 2) * 100);
    $calculatedTotal += (float)$item['price'] * (int)$item['quantity'];
    
    $lineItems[] = [
        'price_data' => [
            'currency' => 'eur',
            'product_data' => [
                'name' => $item['title'],
            ],
            'unit_amount' => $priceInCents,
        ],
        'quantity' => (int)$item['quantity'],
    ];
}

// Pristatymo mokestis (skirtumas tarp užsakymo sumos ir prekių sumos)
$orderTotal = (float)$order['total'];
$diff = $orderTotal - $calculatedTotal;

if ($diff > 0.01) {
    $lineItems[] = [
        'price_data' => [
            'currency' => 'eur',
            'product_data' => [
                'name' => 'Pristatymas / Pakavimas',
            ],
            'unit_amount' => (int)(round($diff, 2) * 100),
        ],
        'quantity' => 1,
    ];
}

\Stripe\Stripe::setApiKey($stripeSecret);

try {
    $checkout_session = \Stripe\Checkout\Session::create([
        'line_items' => $lineItems,
        'mode' => 'payment',
        // Čia nurodome, kur grįžti po apmokėjimo
        'success_url' => $domain . '/stripe_return.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $domain . '/orders.php',
        'metadata' => [
            'order_id' => $orderId,
            'user_id' => $_SESSION['user_id']
        ],
        // Jei turime email, perduodame jį Stripe
        'customer_email' => $_SESSION['user_email'] ?? null,
    ]);

    // Išsaugome sesijos ID (jei reikia debuginti vėliau)
    $updateStmt = $pdo->prepare('UPDATE orders SET stripe_session_id = ? WHERE id = ?');
    $updateStmt->execute([$checkout_session->id, $orderId]);

    // Nukreipiame į Stripe
    header("HTTP/1.1 303 See Other");
    header("Location: " . $checkout_session->url);
    exit;
    
} catch (Exception $e) {
    http_response_code(500);
    echo "Klaida inicijuojant mokėjimą: " . $e->getMessage();
}
?>
