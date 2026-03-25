<?php
// order_success.php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/db.php';

session_start();
$pdo = getPdo();

// Išvalome krepšelius dėl atsargumo, kai pasiekiamas sėkmės puslapis
if (isset($_GET['session_id']) || isset($_GET['order_id'])) {
    unset($_SESSION['cart']);
    unset($_SESSION['cart_community']);
    unset($_SESSION['checkout_delivery']);
    unset($_SESSION['cart_variations']);
}

$order = null;

// Ieškome užsakymo pagal Stripe sesiją (saugiausias būdas svečiams)
if (!empty($_GET['session_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE stripe_session_id = ? LIMIT 1");
    $stmt->execute([$_GET['session_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
} 
// Jeigu naudojama Paysera, kuri grąžina order_id
elseif (!empty($_GET['order_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
    $stmt->execute([$_GET['order_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Pasiruošiame pristatymo tekstą, jeigu užsakymas rastas
$deliveryText = '-';
if ($order) {
    $deliveryDetails = json_decode($order['delivery_details'] ?? '{}', true);
    if (!empty($deliveryDetails['locker_name'])) {
        $deliveryText = 'Paštomatas: ' . $deliveryDetails['locker_name'];
    } elseif (!empty($order['customer_address'])) {
        $deliveryText = 'Kurjeriu: ' . $order['customer_address'];
    } else {
        $deliveryText = htmlspecialchars($order['delivery_method'] ?? 'Nežinomas');
    }
}
?>
<!doctype html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <title>Ačiū už užsakymą!</title>
    <?php echo headerStyles(); ?>
    <style>
        .success-box {
            max-width: 600px;
            margin: 50px auto;
            text-align: center;
            padding: 40px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            border: 1px solid #e4e7ec;
            font-family: 'Inter', sans-serif;
        }
        .icon { font-size: 60px; color: #10b981; margin-bottom: 20px; }
        .success-box h1 { margin-top: 0; color: #0f172a; }
        .success-box p { color: #475467; line-height: 1.6; }
        
        .order-details {
            text-align: left;
            background: #f8fafc;
            padding: 24px;
            border-radius: 12px;
            margin-top: 30px;
            border: 1px dashed #cbd5e1;
        }
        .order-details h2 {
            margin-top: 0;
            font-size: 18px;
            color: #0f172a;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .order-details p {
            margin: 10px 0;
            font-size: 15px;
        }
        .order-details strong {
            color: #0f172a;
            display: inline-block;
            min-width: 150px;
        }
        .tracking-box {
            margin-top: 15px;
            padding: 12px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            color: #1e40af;
            font-weight: 600;
        }

        .btn {
            display: inline-block;
            margin-top: 24px;
            padding: 12px 24px;
            background: #2563eb;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: background 0.2s;
            border: none;
        }
        .btn:hover { background: #1d4ed8; }
        .btn-secondary {
            background: #f1f5f9;
            color: #475467;
            border: 1px solid #cbd5e1;
        }
        .btn-secondary:hover {
            background: #e2e8f0;
        }
    </style>
</head>
<body>
    <?php renderHeader($pdo); ?>

    <div class="success-box">
        <div class="icon">✅</div>
        <h1>Ačiū už užsakymą!</h1>
        <p>Mokėjimas sėkmingai gautas. Jūsų užsakymas pradėtas vykdyti.</p>
        
        <?php if ($order): ?>
            <p>Patvirtinimo laišką išsiuntėme jūsų el. paštu: <br><strong><?= htmlspecialchars($order['customer_email']) ?></strong></p>
            
            <div class="order-details">
                <h2>Užsakymo informacija</h2>
                <p><strong>Užsakymo numeris:</strong> #<?= (int)$order['id'] ?></p>
                <p><strong>Suma:</strong> <?= number_format((float)$order['total'], 2) ?> €</p>
                <p><strong>Pristatymas:</strong> <?= htmlspecialchars($deliveryText) ?></p>
                
                <?php if (!empty($order['tracking_number'])): ?>
                    <div class="tracking-box">
                        📦 Sekimo numeris: <?= htmlspecialchars($order['tracking_number']) ?>
                    </div>
                <?php else: ?>
                    <p style="margin-top: 16px; font-size: 13px; color: #64748b;">
                        <em>* Siuntos sekimo numeris bus atsiųstas el. paštu, kai tik užsakymas bus paruoštas išsiuntimui.</em>
                    </p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p>Iškilus klausimams, prašome susisiekti su mūsų aptarnavimo komanda.</p>
        <?php endif; ?>

        <br>
        <?php if (!empty($_SESSION['user_id'])): ?>
            <a href="/orders.php" class="btn">Mano užsakymai</a>
        <?php endif; ?>
        <a href="/products.php" class="btn <?= !empty($_SESSION['user_id']) ? 'btn-secondary' : '' ?>">Grįžti į parduotuvę</a>
    </div>

    <?php renderFooter($pdo); ?>
</body>
</html>