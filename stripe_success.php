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
    
    // Jei vartotojas nėra prisijungęs sesijoje, bandome atkurti iš Stripe metadata (jei ten saugojome)
    // Šiame projekte darome prielaidą, kad vartotojas yra prisijungęs
    if (!$userId) {
        // Fallback: Galima bandyti ieškoti pagal email, bet saugiau reikalauti login
        die("Klaida: Vartotojo sesija nerasta. Susisiekite su administracija.");
    }

    // 3. Patikriname, ar šis session_id jau panaudotas (Idempotency), kad nedubliuotume užsakymų perkrovus puslapį
    // Tikriname tiek orders, tiek community_orders
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE stripe_payment_intent_id = ?");
    $stmt->execute([$checkout_session->payment_intent]);
    $existsMain = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT id FROM community_orders WHERE stripe_payment_intent_id = ?");
    $stmt->execute([$checkout_session->payment_intent]);
    $existsCommunity = $stmt->fetch();

    if ($existsMain || $existsCommunity) {
        // Užsakymas jau apdorotas, nukreipiame į pirkėjo užsakymus
        header("Location: orders.php?success=already_processed");
        exit;
    }

    // Pradedame DB tranzakciją, kad viskas įsirašytų arba niekas
    $pdo->beginTransaction();

    // ---------------------------------------------------
    // A. PARDUOTUVĖS PREKĖS (Shop Logic)
    // ---------------------------------------------------
    $shopOrderCreated = false;
    
    if (!empty($_SESSION['cart'])) {
        $totalShopPrice = 0;
        $orderItems = [];

        // Surenkame prekių informaciją iš DB, kad būtume tikri dėl kainų
        $ids = array_keys($_SESSION['cart']);
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($products as $product) {
                $qty = $_SESSION['cart'][$product['id']];
                $price = $product['price']; // Galima pridėti nuolaidos logiką
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
            // Įrašome į `orders` lentelę (standartinė parduotuvė)
            // Pastaba: shipping_price čia laikome 0 arba paimame iš checkout metadata, jei buvo skaičiuojama
            $stmt = $pdo->prepare("INSERT INTO orders (user_id, total_price, status, payment_method, stripe_payment_intent_id, created_at) VALUES (?, ?, 'paid', 'stripe', ?, NOW())");
            $stmt->execute([$userId, $totalShopPrice, $checkout_session->payment_intent]);
            $orderId = $pdo->lastInsertId();

            // Įrašome prekes į `order_items` (jei tokia lentelė naudojama sename modelyje)
            // Jei lentelės struktūra kitokia, čia reikėtų pakoreguoti
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
    $communityOrdersCreated = []; // Saugosime info laiškų siuntimui

    if (!empty($_SESSION['cart_community'])) {
        
        // 1. Gauname komisinio dydį (%)
        $stmt = $pdo->query("SELECT community_commission FROM system_settings LIMIT 1");
        $commissionRate = $stmt->fetchColumn() ?: 0; // Jei nėra, 0%

        // 2. Surenkame prekes iš DB
        $cIds = array_keys($_SESSION['cart_community']);
        if (!empty($cIds)) {
            $placeholders = implode(',', array_fill(0, count($cIds), '?'));
            // Svarbu: paimame ir seller_id bei shipping_price
            $stmt = $pdo->prepare("SELECT id, user_id as seller_id, title, price, shipping_price FROM community_listings WHERE id IN ($placeholders)");
            $stmt->execute($cIds);
            $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 3. Grupuojame prekes pagal PARDAVĖJĄ (Seller ID)
            // Nes kiekvienas pardavėjas siunčia atskirai -> atskiras "Užsakymas"
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
                    'shipping' => $listing['shipping_price'], // Siuntimas per prekę
                    'qty' => $qty
                ];
            }

            // 4. Kuriame užsakymus kiekvienam pardavėjui
            foreach ($itemsBySeller as $sellerId => $items) {
                $subtotal = 0;
                $shippingTotal = 0;
                
                // Skaičiuojame sumas šiam pardavėjui
                foreach ($items as $item) {
                    $subtotal += $item['price'] * $item['qty'];
                    $shippingTotal += $item['shipping'] * $item['qty']; // Arba shipping flat rate? Čia darome sumą.
                }

                $grandTotal = $subtotal + $shippingTotal;
                
                // Skaičiuojame komisinį (tik nuo prekės kainos, dažniausiai nuo siuntimo neskaičiuojama, bet priklauso nuo taisyklių)
                // Čia skaičiuojame nuo subtotal
                $commissionAmount = ($subtotal * $commissionRate) / 100;

                // Įrašome į `community_orders`
                // Svarbu: Statusas 'paid', bet payout_status 'hold' (Escrow)
                // items_json saugo snapshotą, kas nupirkta, jei vėliau listingas dingtų
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

                // Surenkame info pardavėjo laiškui
                $communityOrdersCreated[] = [
                    'seller_id' => $sellerId,
                    'items' => $items,
                    'total_paid' => $grandTotal,
                    'shipping' => $shippingTotal
                ];
                
                // Sumažiname prekių likučius (jei community_listings turi quantity, čia reiktų update)
                // Pagal esamą info darome prielaidą, kad listingai vienetiniai arba neriboti, 
                // bet jei reikia - čia vieta UPDATE quantity
            }
        }
    }

    // Patvirtiname tranzakciją
    $pdo->commit();

    // 4. Siunčiame el. laiškus
    
    // A. Laiškas PIRKĖJUI (vienas bendras)
    $buyerEmail = $_SESSION['user_email'] ?? $checkout_session->customer_details->email;
    $subject = "Jūsų užsakymas patvirtintas!";
    $body = "<h1>Ačiū už jūsų užsakymą!</h1>";
    $body .= "<p>Jūsų mokėjimas sėkmingai gautas.</p>";
    
    if ($shopOrderCreated) {
        $body .= "<h3>Parduotuvės prekės:</h3><p>Bus išsiųstos artimiausiu metu.</p>";
    }
    
    if (!empty($communityOrdersCreated)) {
        $body .= "<h3>Bendruomenės prekės:</h3>";
        $body .= "<p>Pardavėjai gavo pranešimus ir išsiųs prekes per nustatytą laiką. Pinigai saugomi sistemoje iki patvirtinsite gavimą.</p>";
        $body .= "<ul>";
        foreach ($communityOrdersCreated as $co) {
            foreach ($co['items'] as $item) {
                $body .= "<li>{$item['title']} (x{$item['qty']})</li>";
            }
        }
        $body .= "</ul>";
        $body .= "<p>Svarbu: Gavę prekę, būtinai prisijunkite ir paspauskite „Gavau prekę“ savo užsakymų skiltyje.</p>";
    }
    
    sendEmail($buyerEmail, $subject, $body);

    // B. Laiškai PARDAVĖJAMS (kiekvienam atskirai)
    foreach ($communityOrdersCreated as $co) {
        // Gauname pardavėjo email
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
            $sBody .= "<p>Gauta suma (su siuntimu): " . number_format($co['total_paid'] / 100, 2) . " EUR</p>"; // Jei kaina centais, dalinam. Jei eurais, nereikia.
            // *Pastaba dėl kainų formato*: Stripe grąžina centais, bet DB dažniausiai saugote taip pat kaip cart.php. 
            // Čia darau prielaidą, kad DB kainos yra tokios pat kaip cart.php (tikėtina float arba int centais).
            
            $sBody .= "<p>Adresą rasite prisijungę, skiltyje „Mano Pardavimai“.</p>";
            $sBody .= "<p><strong>Svarbu:</strong> Pinigai bus pervesti į jūsų Stripe sąskaitą praėjus 48 val. po to, kai pirkėjas patvirtins gavimą.</p>";

            sendEmail($seller['email'], $sSubject, $sBody);
        }
    }

    // 5. Išvalome krepšelius
    unset($_SESSION['cart']);
    unset($_SESSION['cart_community']);

    // 6. Nukreipiame į padėkos puslapį
    header("Location: orders.php?success=1");
    exit;

} catch (Exception $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    // Log error
    error_log("Stripe Success Error: " . $e->getMessage());
    die("Įvyko klaida apdorojant užsakymą. Pinigai nuskaityti, bet užsakymas neišsaugotas. Susisiekite su administracija nurodydami sesijos ID: " . htmlspecialchars($session_id));
}
