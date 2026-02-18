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
    $userId = $_SESSION['user_id'] ?? null;
    
    // Jei vartotojas nėra prisijungęs sesijoje
    if (!$userId) {
        die("Klaida: Vartotojo sesija nerasta. Susisiekite su administracija.");
    }

    // --- Pirkėjo duomenys iš Stripe ---
    $customerDetails = $checkout_session->customer_details;
    
    $customerName = $customerDetails->name ?? 'Nenurodyta';
    $customerEmail = $customerDetails->email ?? ($_SESSION['user_email'] ?? 'info@cukrinukas.lt');
    $customerPhone = $customerDetails->phone ?? ''; 
    
    // Formuojame adresą iš Stripe duomenų
    $addressData = $customerDetails->address ?? null;
    $customerAddress = "Nėra adreso duomenų";
    if ($addressData) {
        $lines = array_filter([
            $addressData->line1,
            $addressData->line2,
            $addressData->postal_code,
            $addressData->city,
            $addressData->country
        ]);
        $customerAddress = implode(', ', $lines);
    }
    
    // --- ATKURIAME PRISTATYMO INFORMACIJĄ ---
    $sessionDelivery = $_SESSION['checkout_delivery'] ?? [];
    
    // Nustatome pristatymo būdą (default: courier, jei sesija dingo)
    $deliveryMethod = $sessionDelivery['method'] ?? 'courier';
    
    // Suformuojame delivery_details JSON
    $deliveryDetailsArr = [
        'address' => $customerAddress,
        'city' => $addressData->city ?? '',
        'zip' => $addressData->postal_code ?? '',
        'phone' => $customerPhone,
        'country_code' => $addressData->country ?? 'LT'
    ];
    
    // Jei buvo pasirinktas paštomatas, pridedame jo info iš sesijos
    if ($deliveryMethod === 'locker' && !empty($sessionDelivery['locker_address'])) {
        $deliveryDetailsArr['locker_address'] = $sessionDelivery['locker_address'];
    }
    
    $deliveryDetailsJson = json_encode($deliveryDetailsArr);
    // ----------------------------------------

    // 3. Patikriname, ar šis session_id jau panaudotas (Idempotency)
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE stripe_session_id = ?");
    $stmt->execute([$session_id]);
    $existsMain = $stmt->fetch();

    $existsCommunity = false;
    if ($checkout_session->payment_intent) {
        $stmt = $pdo->prepare("SELECT id FROM community_orders WHERE stripe_payment_intent_id = ?");
        $stmt->execute([$checkout_session->payment_intent]);
        $existsCommunity = $stmt->fetch();
    }

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
                $qty = (int)$_SESSION['cart'][$product['id']];
                
                // Naudojame akcijinę kainą, jei ji yra
                $price = ($product['sale_price'] !== null) ? $product['sale_price'] : $product['price'];
                
                $subtotal = $price * $qty;
                $totalShopPrice += $subtotal;

                $orderItems[] = [
                    'product_id' => $product['id'],
                    'qty' => $qty,
                    'price' => $price,
                    'name' => $product['title']
                ];
            }
        }

        if ($totalShopPrice > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO orders (
                    user_id, 
                    customer_name, 
                    customer_email, 
                    customer_phone, 
                    customer_address, 
                    delivery_method,
                    delivery_details,
                    total, 
                    status, 
                    stripe_session_id, 
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?, NOW())
            ");
            
            $stmt->execute([
                $userId,
                $customerName,
                $customerEmail,
                $customerPhone,
                $customerAddress,
                $deliveryMethod,
                $deliveryDetailsJson,
                $totalShopPrice,
                $session_id
            ]);
            
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
        
        // Ieškome setting_value pagal setting_key
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute(['community_commission']);
        $commissionRate = $stmt->fetchColumn() ?: 0; // Default 0 jei nėra settingo

        $cIds = array_keys($_SESSION['cart_community']);
        if (!empty($cIds)) {
            $placeholders = implode(',', array_fill(0, count($cIds), '?'));
            
            // PATAISYTA: Pašalintas 'shipping_price' iš SELECT užklausos
            $stmt = $pdo->prepare("SELECT id, user_id as seller_id, title, price FROM community_listings WHERE id IN ($placeholders)");
            $stmt->execute($cIds);
            $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Grupuojame pagal pardavėją
            $itemsBySeller = [];
            foreach ($listings as $listing) {
                $sellerId = $listing['seller_id'];
                $qty = (int)$_SESSION['cart_community'][$listing['id']];
                
                if (!isset($itemsBySeller[$sellerId])) {
                    $itemsBySeller[$sellerId] = [];
                }
                
                $itemsBySeller[$sellerId][] = [
                    'listing_id' => $listing['id'],
                    'title' => $listing['title'],
                    'price' => $listing['price'],
                    'shipping' => 0, // PATAISYTA: Pristatymas visada 0
                    'qty' => $qty
                ];
            }

            foreach ($itemsBySeller as $sellerId => $items) {
                $subtotal = 0;
                $shippingTotal = 0;
                
                foreach ($items as $item) {
                    $subtotal += $item['price'] * $item['qty'];
                    $shippingTotal += 0; // PATAISYTA: Nėra papildomo pristatymo mokesčio
                }

                $grandTotal = $subtotal + $shippingTotal;
                $commissionAmount = ($subtotal * $commissionRate) / 100;
                $itemsJson = json_encode($items);

                // Naudojame payment_intent kaip ID
                $paymentIntentId = $checkout_session->payment_intent ?? $session_id . '_comm_' . $sellerId;

                $stmt = $pdo->prepare("
                    INSERT INTO community_orders 
                    (buyer_id, seller_id, total_item_price, total_shipping_price, platform_commission, total_paid, stripe_payment_intent_id, status, payout_status, delivery_status, created_at, items_json) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'paid', 'hold', 'pending', NOW(), ?)
                ");
                
                $stmt->execute([
                    $userId,
                    $sellerId,
                    $subtotal,
                    $shippingTotal, // Čia įsirašys 0
                    $commissionAmount,
                    $grandTotal,
                    $paymentIntentId,
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
    $pdo->commit();

    // 4. Siunčiame el. laiškus
    try {
        $buyerEmail = $customerEmail; 
        
        if ($buyerEmail) {
            $subject = "Jūsų užsakymas patvirtintas!";
            $body = "<h1>Ačiū už jūsų užsakymą!</h1>";
            $body .= "<p>Jūsų mokėjimas sėkmingai gautas.</p>";
            
            if ($shopOrderCreated) {
                $body .= "<h3>Parduotuvės prekės:</h3><p>Bus išsiųstos artimiausiu metu adresu: $customerAddress</p>";
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
                $sBody .= "<p>Gauta suma: " . number_format($co['total_paid'] / 100, 2) . " EUR</p>";

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
