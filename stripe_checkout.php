<?php
// stripe_checkout.php

// Įjungiame klaidų rodymą laikinai, kad matytume, jei kas negerai
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';

// SVARBU: Pataisytas kelias iki Stripe bibliotekos pagal tavo failų struktūrą
if (file_exists(__DIR__ . '/lib/stripe/init.php')) {
    require_once __DIR__ . '/lib/stripe/init.php';
} else {
    die("Klaida: Nerasta Stripe biblioteka (lib/stripe/init.php)");
}

session_start();
$pdo = getPdo();

// 1. Patikriname ar gautas order_id
if (empty($_GET['order_id'])) {
    die("Klaida: Nenurodytas užsakymo ID.");
}

$orderId = (int)$_GET['order_id'];

// 2. Patikriname ar toks užsakymas egzistuoja ir priklauso vartotojui
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
$stmt->execute([$orderId, $_SESSION['user_id'] ?? 0]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die("Klaida: Užsakymas nerastas arba jūs neturite teisių jį peržiūrėti.");
}

// Jei jau apmokėtas - neleidžiame mokėti vėl
if ($order['status'] === 'Apmokėta' || $order['status'] === 'Vykdomas') {
    header("Location: /order_success.php?session_id=already_paid");
    exit;
}

// 3. Gauname prekes iš DB (Order Items + Product Title)
$stmtItems = $pdo->prepare("
    SELECT oi.*, p.title 
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$stmtItems->execute([$orderId]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// Nustatome API raktą
if (empty($_ENV['STRIPE_SECRET_KEY'])) {
    die("Klaida: Nėra nustatytas STRIPE_SECRET_KEY .env faile");
}
\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

// 4. Formuojame Stripe krepšelį
$lineItems = [];
$itemsTotal = 0;

foreach ($items as $item) {
    // Jei prekė ištrinta iš DB, naudojame atsarginį pavadinimą
    $productName = $item['title'] ?? 'Prekė #' . $item['product_id'];
    
    $lineItems[] = [
        'price_data' => [
            'currency' => 'eur',
            'product_data' => [
                'name' => $productName,
            ],
            'unit_amount' => (int)round($item['price'] * 100), // Kaina centais
        ],
        'quantity' => (int)$item['quantity'],
    ];
    $itemsTotal += $item['price'] * $item['quantity'];
}

// 5. Ar yra pristatymo mokestis? (Total - Prekės)
$shippingCost = $order['total'] - $itemsTotal;

// Dėl apvalinimo paklaidų, tikriname ar skirtumas didesnis nei 1 centas
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
        'client_reference_id' => $orderId, // Labai svarbu Webhook'ui
        'customer_email' => $order['customer_email'],
        'metadata' => [
            'order_id' => $orderId
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
