<?php
// stripe_success.php - Apmokėjimo užfiksavimas ir užsakymų sukūrimas
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/lib/stripe/init.php';

session_start();

// Naudojame requireEnv, nes taip sutvarkėme praeitame žingsnyje
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
    $userId = $_SESSION['user_id'] ?? null;
    
    // Jei vartotojas nėra prisijungęs sesijoje
    if (!$userId) {
        die("Klaida: Vartotojo sesija nerasta. Susisiekite su administracija.");
    }

    // 3. Patikriname, ar šis session_id jau panaudotas (Idempotency)
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE stripe_payment_intent_id = ?");
    $stmt->execute([$checkout_session->payment_intent]);
    $existsMain = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT id FROM community_orders WHERE stripe_payment_intent_id = ?");
    $stmt->execute([$checkout_session->payment_intent]);
    $existsCommunity = $stmt->fetch();

    if ($existsMain || $existsCommunity) {
        // Užsakymas jau apdorotas
        header("Location: orders.php?success=already_processed");
        exit;
    }

    // Pradedame DB tranzakciją
    $pdo->beginTransaction();

    // ---------------------------------------------------
    // A. PARDUOTUVĖS PREKĖS (Shop Logic)
    // ---------------------------------------------------
    $shopOrderCreated = false;
    
    if (!empty($_SESSION['cart'])) {
        $totalShopPrice = 0;
        $orderItems = [];

        $ids = array_keys($_SESSION['cart']);
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($products as $product) {
                $qty = $_SESSION['cart'][$product['id']];
                $price = $product['price'];
                $subtotal = $price * $qty;
                $totalShopPrice += $subtotal;

                $orderItems[] = [
                    'product_id' => $product['id'],
                    'qty' => $qty,
                    'price' => $price,
                    'name' => $product['name']
                ];
            }
        }

        if ($totalShopPrice > 0) {
            $stmt = $pdo->prepare("INSERT INTO orders (user_id, total_price, status, payment_method, stripe_payment_intent_id, created_at) VALUES (?, ?, 'paid', 'stripe', ?, NOW())");
            $stmt->execute([$userId, $totalShopPrice, $checkout_session->payment_intent]);
            $orderId = $pdo->lastInsertId();

            $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
            foreach ($orderItems as $item) {
                $stmtItem->execute([$orderId, $item['product_id'], $item['qty'], $item['price']]);
            }
            $shopOrderCreated = true;
        }
    }

    // ---------------------------------------------------
    // B. BENDRUOMENĖS PREKĖS (Marketplace Logic)
    // ---------------------------------------------------
    $communityOrdersCreated = [];

    if (!empty($_SESSION['cart_community'])) {
        
        $stmt = $pdo->query("SELECT community_commission FROM system_settings LIMIT 1");
        $commissionRate = $stmt->fetchColumn() ?: 0;

        $cIds = array_keys($_SESSION['cart_community']);
        if (!empty($cIds)) {
            $placeholders = implode(',', array_fill(0, count($cIds), '?'));
            $stmt = $pdo->prepare("SELECT id, user_id as seller_id, title, price, shipping_price FROM community_listings WHERE id IN ($placeholders)");
            $stmt->execute($cIds);
            $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $itemsBySeller = [];
            foreach ($listings as $listing) {
                $sellerId = $listing['seller_id'];
                $qty = $_SESSION['cart_community'][$listing['id']];
                
                if (!isset($itemsBySeller[$sellerId])) {
                    $itemsBySeller[$sellerId] = [];
                }
                
                $itemsBySeller[$sellerId][] = [
                    'listing_id' => $listing['id'],
                    'title' => $listing['title'],
                    'price' => $listing['price'],
                    'shipping' => $listing['shipping_price'],
                    'qty' => $qty
                ];
            }

            foreach ($itemsBySeller as $sellerId => $items) {
                $subtotal = 0;
                $shippingTotal = 0;
                
                foreach ($items as $item) {
                    $subtotal += $item['price'] * $item['qty'];
                    $shippingTotal += $item['shipping'] * $item['qty'];
                }

                $grandTotal = $subtotal + $shippingTotal;
                $commissionAmount = ($subtotal * $commissionRate) / 100;
                $itemsJson = json_encode($items);

                $stmt = $pdo->prepare("
                    INSERT INTO community_orders 
                    (buyer_id, seller_id, total_item_price, total_shipping_price, platform_commission, total_paid, stripe_payment_intent_id, status, payout_status, delivery_status, created_at, items_json) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'paid', 'hold', 'pending', NOW(), ?)
                ");
                
                $stmt->execute([
                    $userId,
                    $sellerId,
                    $subtotal,
                    $shippingTotal,
                    $commissionAmount,
                    $grandTotal,
                    $checkout_session->payment_intent,
                    $itemsJson
                ]);

                $communityOrdersCreated[] = [
                    'seller_id' => $sellerId,
                    'items' => $items,
                    'total_paid' => $grandTotal,
                    'shipping' => $shippingTotal
                ];
            }
        }
    }

    // Patvirtiname tranzakciją
    // Po šios eilutės duomenys yra išsaugoti. RollBack nebegalimas.
    $pdo->commit();

    // 4. Siunčiame el. laiškus (gali įvykti klaidų, bet užsakymas jau sukurtas)
    try {
        // A. Laiškas PIRKĖJUI
        $buyerEmail = $_SESSION['user_email'] ?? $checkout_session->customer_details->email;
        $subject = "Jūsų užsakymas patvirtintas!";
        $body = "<h1>Ačiū už jūsų užsakymą!</h1>";
        $body .= "<p>Jūsų mokėjimas sėkmingai gautas.</p>";
        
        if ($shopOrderCreated) {
            $body .= "<h3>Parduotuvės prekės:</h3><p>Bus išsiųstos artimiausiu metu.</p>";
        }
        
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

        // B. Laiškai PARDAVĖJAMS
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
                $sBody .= "<p>Gauta suma: " . number_format($co['total_paid'] / 100, 2) . " EUR</p>";

                sendEmail($seller['email'], $sSubject, $sBody);
            }
        }
    } catch (Exception $emailError) {
        // Jei laiškai neišsisiuntė, tiesiog loguojame klaidą, bet nemetame fatal error vartotojui
        error_log("Klaida siunčiant laiškus po sėkmingo apmokėjimo: " . $emailError->getMessage());
    }

    // 5. Išvalome krepšelius
    unset($_SESSION['cart']);
    unset($_SESSION['cart_community']);

    // 6. Nukreipiame į padėkos puslapį
    header("Location: orders.php?success=1");
    exit;

} catch (Exception $e) {
    // PATAISYMAS ČIA:
    // Tikriname ar $pdo egzistuoja IR ar tranzakcija vis dar aktyvi
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // Log error
    error_log("Stripe Success Error: " . $e->getMessage());
    die("Įvyko klaida apdorojant užsakymą. Pinigai nuskaityti, bet užsakymas neišsaugotas. Susisiekite su administracija nurodydami sesijos ID: " . htmlspecialchars($session_id));
}
