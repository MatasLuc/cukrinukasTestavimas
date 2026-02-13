<?php
require 'env.php';
require 'db.php';
require 'lib/stripe/init.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Gauname vartotojo stripe_account_id
$stmt = $pdo->prepare("SELECT stripe_account_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user || !$user['stripe_account_id']) {
    // Jei kažkas negerai, grąžiname į paskyrą su klaida
    header("Location: account.php?error=stripe_not_found");
    exit;
}

\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

try {
    // Patikriname Stripe paskyros būseną
    $account = \Stripe\Account::retrieve($user['stripe_account_id']);

    // charges_enabled - ar gali priimti pinigus
    // details_submitted - ar suvedė duomenis
    if ($account->details_submitted) {
        // Atnaujiname DB
        $updateStmt = $pdo->prepare("UPDATE users SET stripe_onboarding_completed = 1 WHERE id = ?");
        $updateStmt->execute([$user_id]);

        header("Location: account.php?success=stripe_connected");
    } else {
        // Vartotojas neužbaigė pildymo
        header("Location: account.php?error=stripe_incomplete");
    }
    exit;

} catch (Exception $e) {
    echo 'Klaida: ' . $e->getMessage();
}
?>
