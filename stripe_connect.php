<?php
require 'env.php';
require 'db.php';
require 'lib/stripe/init.php';

session_start();

// Tikriname, ar vartotojas prisijungęs
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
    die("Vartotojas nerastas.");
}

\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

try {
    // 1. Patikriname, ar vartotojas jau turi Stripe paskyrą
    $accountId = $user['stripe_account_id'];

    if (!$accountId) {
        // Jei neturi, sukuriame naują 'express' tipo paskyrą
        $account = \Stripe\Account::create([
            'type' => 'express',
            'country' => 'LT', // Arba dinamiškai pagal vartotojo šalį, jei turite
            'email' => $user['email'],
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
        ]);

        $accountId = $account->id;

        // Išsaugome DB
        $updateStmt = $pdo->prepare("UPDATE users SET stripe_account_id = ? WHERE id = ?");
        $updateStmt->execute([$accountId, $user_id]);
    }

    // 2. Sukuriame Account Link (nuorodą, kur vartotojas pildys duomenis)
    $accountLink = \Stripe\AccountLink::create([
        'account' => $accountId,
        'refresh_url' => $_ENV['BASE_URL'] . '/stripe_connect.php', // Jei nutrūktų, bando iš naujo
        'return_url' => $_ENV['BASE_URL'] . '/stripe_connect_return.php', // Sėkmės atveju
        'type' => 'account_onboarding',
    ]);

    // Nukreipiame vartotoją į Stripe
    header("Location: " . $accountLink->url);
    exit;

} catch (Exception $e) {
    echo 'Klaida jungiantis su Stripe: ' . $e->getMessage();
}
?>
