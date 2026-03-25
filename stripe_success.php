<?php
// stripe_success.php - Apmokėjimo užfiksavimas ir užsakymų atnaujinimas
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/lib/stripe/init.php';
require_once __DIR__ . '/order_functions.php'; // Įtraukiame pagrindines užsakymo funkcijas

session_start();

// Įkeliame Stripe raktą
$stripeSecretKey = requireEnv('STRIPE_SECRET_KEY');
\Stripe\Stripe::setApiKey($stripeSecretKey);

// 1. Paimame sesijos ID iš URL
$session_id = $_GET['session_id'] ?? null;

if (!$session_id) {
    header("Location: index.php");
    exit;
}

try {
    // 2. Gauname sesijos duomenis iš Stripe
    $checkout_session = \Stripe\Checkout\Session::retrieve($session_id);

    // KLAIDOS TAISYMAS: Stripe grąžina 'paid', o ne 'apmokėta'
    if ($checkout_session->payment_status !== 'paid') {
        header("Location: cart.php?error=payment_failed");
        exit;
    }

    $pdo = getPdo();
    
    // --- ATKURIAME UŽSAKYMO ID IR PAYMENT INTENT ---
    $orderId = $checkout_session->client_reference_id ?? ($checkout_session->metadata->order_id ?? null);
    $paymentIntentId = $checkout_session->payment_intent ?? $session_id;

    if (!$orderId) {
        die("Klaida: Nepavyko atkurti užsakymo ID iš mokėjimo sistemos.");
    }

    // Papildomai atnaujiname stripe_session_id
    $stmtUpdateSession = $pdo->prepare("UPDATE orders SET stripe_session_id = ? WHERE id = ?");
    $stmtUpdateSession->execute([$session_id, $orderId]);

    // ---------------------------------------------------
    // UŽSAKYMO UŽBAIGIMAS
    // ---------------------------------------------------
    // Iškviečiame centralizuotą funkciją. Ji automatiškai:
    // 1. Patvirtins parduotuvės prekių užsakymą
    // 2. Patvirtins "laukiama" turgelio prekių užsakymą
    // 3. Išsiųs visus el. laiškus
    completeOrder($pdo, $orderId, true, $paymentIntentId);

    // 5. Išvalome likusius krepšelius, nes viskas išsaugota DB!
    unset($_SESSION['cart']);
    unset($_SESSION['cart_community']);
    unset($_SESSION['checkout_delivery']);
    unset($_SESSION['cart_variations']);

    // 6. Nukreipiame į padėkos puslapį ir perduodame sesijos ID
    header("Location: order_success.php?session_id=" . urlencode($session_id));
    exit;

} catch (Exception $e) {
    error_log("Stripe Success Critical Error: " . $e->getMessage());
    die("Įvyko klaida apdorojant užsakymą. <br><strong>Techninė klaida:</strong> " . htmlspecialchars($e->getMessage()));
}
?>
