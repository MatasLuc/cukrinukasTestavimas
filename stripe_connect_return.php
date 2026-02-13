<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/stripe/init.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT stripe_account_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user || !$user['stripe_account_id']) {
    header("Location: account.php?error=stripe_not_found");
    exit;
}

// Saugus rakto gavimas
$stripeKey = getenv('STRIPE_SECRET_KEY');
if (!$stripeKey && isset($_ENV['STRIPE_SECRET_KEY'])) {
    $stripeKey = $_ENV['STRIPE_SECRET_KEY'];
}
\Stripe\Stripe::setApiKey($stripeKey);

try {
    $account = \Stripe\Account::retrieve($user['stripe_account_id']);

    // Tikriname, ar charges_enabled (ar gali priimti pinigus)
    // ARBA ar details_submitted (ar užpildė formą)
    if ($account->details_submitted) {
        $updateStmt = $pdo->prepare("UPDATE users SET stripe_onboarding_completed = 1 WHERE id = ?");
        $updateStmt->execute([$user_id]);

        header("Location: account.php?success=stripe_connected");
    } else {
        header("Location: account.php?error=stripe_incomplete");
    }
    exit;

} catch (Exception $e) {
    echo 'Klaida: ' . $e->getMessage();
    exit;
}
?>
