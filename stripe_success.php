<?php
// stripe_success.php - Apmokėjimo užfiksavimas ir užsakymų atnaujinimas
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/lib/stripe/init.php';

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

    // Jei apmokėjimas dar neįvykdytas arba atšauktas
    if ($checkout_session->payment_status !== 'paid') {
        header("Location: cart.php?error=payment_failed");
        exit;
    }

    $pdo = getPdo();
    
    // --- ATKURIAME UŽSAKYMO ID ---
    // Paimame ID, kurį perdavėme stripe_checkout.php faile
    $orderId = $checkout_session->client_reference_id ?? ($checkout_session->metadata->order_id ?? null);

    if (!$orderId) {
        die("Klaida: Nepavyko atkurti užsakymo ID iš mokėjimo sistemos.");
    }

    // Patikriname, ar toks užsakymas egzistuoja DB
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        die("Klaida: Užsakymas #$orderId nerastas duomenų bazėje.");
    }

    // Jei užsakymas jau apmokėtas, nebedarome nieko (kad nedubliuotume veiksmų)
    if ($order['status'] === 'paid') {
        unset($_SESSION['cart']);
        unset($_SESSION['cart_community']);
        unset($_SESSION['checkout_delivery']);
        header("Location: orders.php?success=already_paid");
        exit;
    }

    // Pradedame DB tranzakciją
    $pdo->beginTransaction();

    // ---------------------------------------------------
    // ATNAUJINAME PAGRINDINĮ UŽSAKYMĄ (UPDATE)
    // ---------------------------------------------------
    // Vietoj INSERT, mes atnaujiname statusą į 'paid'.
    
    $stmtUpdate = $pdo->prepare("UPDATE orders SET status = 'paid', stripe_session_id = ?, updated_at = NOW() WHERE id = ?");
    $stmtUpdate->execute([$session_id, $orderId]);

    // PASTABA: Prekės į `order_items` jau buvo įrašytos `checkout.php` faile,
    // todėl čia jų iš naujo įrašinėti nereikia.

    // ---------------------------------------------------
    // B. BENDRUOMENĖS PREKĖS (Marketplace Logic)
    // ---------------------------------------------------
    // Kadangi checkout.php paprastai nesukuria `community_orders` įrašų (tik bendrą orderį),
    // čia paliekame logiką, kuri sukuria įrašus pardavėjų apskaitai.
    
    $communityOrdersCreated = []; 

    if (!empty($_SESSION['cart_community'])) {
        
        // Ieškome setting_value pagal setting_key
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute(['community_commission']);
        $commissionRate = $stmt->fetchColumn() ?: 0; // Default 0 jei nėra settingo

        $cIds = array_keys($_SESSION['cart_community']);
        if (!empty($cIds)) {
            $placeholders = implode(',', array_fill(0, count($cIds), '?'));
            
            $stmt = $pdo->prepare("SELECT id, user_id as seller_id, title, price FROM community_listings WHERE id IN ($placeholders)");
            $stmt->execute($cIds);
            $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Grupuojame pagal pardavėją tik el. laiškams
            $itemsBySellerForEmail = [];

            // Stripe ID (Payment Intent arba Session ID)
            $paymentId = $checkout_session->payment_intent ?? $session_id;

            foreach ($listings as $listing) {
                $sellerId = $listing['seller_id'];
                $itemId = $listing['id'];
                $qty = (int)$_SESSION['cart_community'][$itemId];
                $unitPrice = $listing['price'];
                $itemTotalPrice = $unitPrice * $qty;
                
                // SKAIČIAVIMAI PAGAL DB STRUKTŪRĄ
                $shippingPrice = 0; // Visada 0
                $totalAmount = $itemTotalPrice + $shippingPrice;
                
                $adminCommissionAmount = ($itemTotalPrice * $commissionRate) / 100;
                $sellerPayoutAmount = $totalAmount - $adminCommissionAmount;

                // ĮRAŠOME Į DB KIEKVIENĄ PREKĘ ATSKIRAI Į community_orders
                $stmt = $pdo->prepare("
                    INSERT INTO community_orders 
                    (buyer_id, seller_id, item_id, item_price, shipping_price, 
                     total_amount, admin_commission_rate, admin_commission_amount, seller_payout_amount, 
                     stripe_payment_intent_id, status, payout_status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', 'hold', NOW())
                ");
                
                $stmt->execute([
                    $order['user_id'], // Imame user_id iš originalaus užsakymo
                    $sellerId,
                    $itemId,
                    $itemTotalPrice,
                    $shippingPrice,
                    $totalAmount,
                    $commissionRate,
                    $adminCommissionAmount,
                    $sellerPayoutAmount,
                    $paymentId
                ]);

                // Kaupiame informaciją el. laiškams
                if (!isset($itemsBySellerForEmail[$sellerId])) {
                    $itemsBySellerForEmail[$sellerId] = [
                        'total_paid' => 0,
                        'items' => []
                    ];
                }
                $itemsBySellerForEmail[$sellerId]['total_paid'] += $totalAmount;
                $itemsBySellerForEmail[$sellerId]['items'][] = [
                    'title' => $listing['title'],
                    'qty' => $qty
                ];
            }

            // Suformuojame masyvą laiškų siuntimui
            foreach ($itemsBySellerForEmail as $sellerId => $data) {
                $communityOrdersCreated[] = [
                    'seller_id' => $sellerId,
                    'items' => $data['items'],
                    'total_paid' => $data['total_paid']
                ];
            }
        }
    }

    // Patvirtiname tranzakciją
    $pdo->commit();

    // 4. Siunčiame el. laiškus
    try {
        $buyerEmail = $order['customer_email']; 
        
        if ($buyerEmail) {
            $subject = "Jūsų užsakymas #{$orderId} patvirtintas!";
            $body = "<h1>Ačiū už jūsų užsakymą!</h1>";
            $body .= "<p>Jūsų mokėjimas sėkmingai gautas.</p>";
            $body .= "<p>Užsakymo nr: <strong>#{$orderId}</strong></p>";
            $body .= "<p>Suma: " . number_format($order['total'], 2) . " €</p>";
            
            // Kadangi nebežinome tikslių prekių pavadinimų iš sesijos (jei atstatėme tik orderį),
            // bet vartotojas vis dar turi sesiją, galime naudoti bendrą pranešimą.
            // Arba jei norite detaliai - reikėtų daryti SELECT FROM order_items.
            // Čia paliekame bendrą pranešimą, kad nesudėtingintume kodo.
            $body .= "<p>Pristatymo informacija: {$order['customer_address']}</p>";
            
            if (!empty($communityOrdersCreated)) {
                $body .= "<h3>Bendruomenės prekės:</h3>";
                $body .= "<ul>";
                foreach ($communityOrdersCreated as $co) {
                    foreach ($co['items'] as $item) {
                        $body .= "<li>{$item['title']} (x{$item['qty']})</li>";
                    }
                }
                $body .= "</ul>";
            }
            
            sendEmail($buyerEmail, $subject, $body);
        }

        // Laiškai PARDAVĖJAMS
        foreach ($communityOrdersCreated as $co) {
            $stmt = $pdo->prepare("SELECT email, username FROM users WHERE id = ?");
            $stmt->execute([$co['seller_id']]);
            $seller = $stmt->fetch();

            if ($seller) {
                $sSubject = "Naujas užsakymas! (Cukrinukas Turgelis)";
                $sBody = "<h2>Sveiki, {$seller['username']}!</h2>";
                $sBody .= "<p>Turite naują užsakymą turgelyje.</p>";
                $sBody .= "<h3>Reikia išsiųsti:</h3><ul>";
                foreach ($co['items'] as $item) {
                    $sBody .= "<li>{$item['title']} (x{$item['qty']})</li>";
                }
                $sBody .= "</ul>";
                $sBody .= "<p>Gauta suma: " . number_format($co['total_paid'], 2) . " EUR</p>";

                sendEmail($seller['email'], $sSubject, $sBody);
            }
        }
    } catch (Exception $emailError) {
        error_log("Klaida siunčiant laiškus (Stripe Success): " . $emailError->getMessage());
    }

    // 5. Išvalome krepšelius
    unset($_SESSION['cart']);
    unset($_SESSION['cart_community']);
    unset($_SESSION['checkout_delivery']);
    unset($_SESSION['cart_variations']); // Išvalome ir variacijas

    // 6. Nukreipiame į padėkos puslapį
    header("Location: orders.php?success=1");
    exit;

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Stripe Success Critical Error: " . $e->getMessage());
    die("Įvyko klaida apdorojant užsakymą. <br><strong>Techninė klaida:</strong> " . htmlspecialchars($e->getMessage()) . "<br>Sesijos ID: " . htmlspecialchars($session_id));
}
?>
