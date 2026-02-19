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
    
    // --- ATKURIAME UŽSAKYMO ID ---
    $orderId = $checkout_session->client_reference_id ?? ($checkout_session->metadata->order_id ?? null);

    if (!$orderId) {
        die("Klaida: Nepavyko atkurti užsakymo ID iš mokėjimo sistemos.");
    }

    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        die("Klaida: Užsakymas #$orderId nerastas duomenų bazėje.");
    }

    // ---------------------------------------------------
    // B. BENDRUOMENĖS PREKĖS (Marketplace Logic)
    // Tvarkome pirmiausia, nes informacija yra sesijoje
    // ---------------------------------------------------
    $communityOrdersCreated = []; 

    if (!empty($_SESSION['cart_community'])) {
        
        $stmtComm = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $stmtComm->execute(['community_commission']);
        $commissionRate = $stmtComm->fetchColumn() ?: 0;

        $cIds = array_keys($_SESSION['cart_community']);
        if (!empty($cIds)) {
            $placeholders = implode(',', array_fill(0, count($cIds), '?'));
            
            $stmtListings = $pdo->prepare("SELECT id, user_id as seller_id, title, price FROM community_listings WHERE id IN ($placeholders)");
            $stmtListings->execute($cIds);
            $listings = $stmtListings->fetchAll(PDO::FETCH_ASSOC);

            $itemsBySellerForEmail = [];
            $paymentId = $checkout_session->payment_intent ?? $session_id;

            $pdo->beginTransaction(); // Pradedame DB tranzakciją bendruomenės prekėms
            
            foreach ($listings as $listing) {
                $sellerId = $listing['seller_id'];
                $itemId = $listing['id'];
                $qty = (int)$_SESSION['cart_community'][$itemId];
                $unitPrice = $listing['price'];
                $itemTotalPrice = $unitPrice * $qty;
                
                $shippingPrice = 0; // Visada 0
                $totalAmount = $itemTotalPrice + $shippingPrice;
                
                $adminCommissionAmount = ($itemTotalPrice * $commissionRate) / 100;
                $sellerPayoutAmount = $totalAmount - $adminCommissionAmount;

                // Įrašome į bendruomenės užsakymus
                $stmtIns = $pdo->prepare("
                    INSERT INTO community_orders 
                    (buyer_id, seller_id, item_id, item_price, shipping_price, 
                     total_amount, admin_commission_rate, admin_commission_amount, seller_payout_amount, 
                     stripe_payment_intent_id, status, payout_status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'apmokėta', 'hold', NOW())
                ");
                
                $stmtIns->execute([
                    $order['user_id'], 
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

                // NAUJA: Pakeičiame bendruomenės prekės statusą į 'sold'
                $stmtUpdateListing = $pdo->prepare("UPDATE community_listings SET status = 'sold' WHERE id = ?");
                $stmtUpdateListing->execute([$itemId]);

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

            $pdo->commit(); // Patvirtiname bendruomenės prekių pakeitimus

            foreach ($itemsBySellerForEmail as $sellerId => $data) {
                $communityOrdersCreated[] = [
                    'seller_id' => $sellerId,
                    'items' => $data['items'],
                    'total_paid' => $data['total_paid']
                ];
            }
        }
        
        // Iškart išvalome iš sesijos, kad atnaujinus puslapį nesidubliuotų
        unset($_SESSION['cart_community']);
    }

    // ---------------------------------------------------
    // A. PAGRINDINIS UŽSAKYMAS (Parduotuvės prekės)
    // ---------------------------------------------------
    // Iškviečiame jūsų centralizuotą funkciją likučių sumažinimui.
    // Paduodame false 3-iu parametru, kad siųstume apjungtą el. laišką žemiau.
    if (mb_strtolower($order['status']) !== 'apmokėta') {
        
        $orderCompletedNow = completeOrder($pdo, $orderId, false);
        
        if ($orderCompletedNow || !empty($communityOrdersCreated)) {
            // Išsiunčiame pirkėjui apjungtą laišką su visomis detalėmis
            $buyerEmail = $order['customer_email']; 
            if ($buyerEmail) {
                $subject = "Jūsų užsakymas #{$orderId} patvirtintas!";
                $body = "<h1>Ačiū už jūsų užsakymą!</h1>";
                $body .= "<p>Jūsų mokėjimas sėkmingai gautas.</p>";
                $body .= "<p>Užsakymo nr: <strong>#{$orderId}</strong></p>";
                $body .= "<p>Suma: " . number_format($order['total'], 2) . " €</p>";
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
        }
    }

    // Papildomai atnaujiname stripe_session_id
    $stmtUpdateSession = $pdo->prepare("UPDATE orders SET stripe_session_id = ? WHERE id = ?");
    $stmtUpdateSession->execute([$session_id, $orderId]);

    // Laiškai PARDAVĖJAMS
    foreach ($communityOrdersCreated as $co) {
        $stmtGetSeller = $pdo->prepare("SELECT email, username FROM users WHERE id = ?");
        $stmtGetSeller->execute([$co['seller_id']]);
        $seller = $stmtGetSeller->fetch();

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

    // 5. Išvalome likusius krepšelius
    unset($_SESSION['cart']);
    unset($_SESSION['checkout_delivery']);
    unset($_SESSION['cart_variations']);

    // 6. Nukreipiame į padėkos puslapį
    header("Location: orders.php?success=1");
    exit;

} catch (Exception $e) {
    error_log("Stripe Success Critical Error: " . $e->getMessage());
    die("Įvyko klaida apdorojant užsakymą. <br><strong>Techninė klaida:</strong> " . htmlspecialchars($e->getMessage()));
}
?>
