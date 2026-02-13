<?php
// 1. Įjungiame klaidų rodymą (laikinai)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 2. Įtraukiame reikalingus failus
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/stripe/init.php';

session_start();

// 3. FIX: Inicijuojame duomenų bazės ryšį naudodami funkciją iš db.php
$pdo = getPdo();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Gauname vartotojo duomenis
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    die("Klaida: Vartotojas nerastas.");
}

// Saugus rakto gavimas
$stripeKey = getenv('STRIPE_SECRET_KEY');
if (!$stripeKey && isset($_ENV['STRIPE_SECRET_KEY'])) {
    $stripeKey = $_ENV['STRIPE_SECRET_KEY'];
}

if (!$stripeKey) {
    die("Klaida: Nėra nustatytas STRIPE_SECRET_KEY. Patikrinkite .env failą.");
}

\Stripe\Stripe::setApiKey($stripeKey);

// Saugus BASE_URL gavimas
$baseUrl = getenv('BASE_URL');
if (!$baseUrl && isset($_ENV['BASE_URL'])) {
    $baseUrl = $_ENV['BASE_URL'];
}
if (!$baseUrl) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $baseUrl = $protocol . "://" . $_SERVER['HTTP_HOST'];
}

try {
    $accountId = $user['stripe_account_id'];

    if (!$accountId) {
        // Sukuriame Express sąskaitą
        $account = \Stripe\Account::create([
            'type' => 'express',
            'country' => 'LT',
            'email' => $user['email'],
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
        ]);

        $accountId = $account->id;

        $updateStmt = $pdo->prepare("UPDATE users SET stripe_account_id = ? WHERE id = ?");
        $updateStmt->execute([$accountId, $user_id]);
    }

    // Sukuriame nuorodą
    $accountLink = \Stripe\AccountLink::create([
        'account' => $accountId,
        'refresh_url' => $baseUrl . '/stripe_connect.php',
        'return_url' => $baseUrl . '/stripe_connect_return.php',
        'type' => 'account_onboarding',
    ]);

    header("Location: " . $accountLink->url);
    exit;

} catch (Exception $e) {
    echo 'Stripe Klaida: ' . $e->getMessage();
    exit;
}
?>
