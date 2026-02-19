<?php
// orders.php - Pirkėjo užsakymai ir Pardavėjo pardavimai (Apjungta)
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/env.php';

if (file_exists(__DIR__ . '/lib/stripe/init.php')) {
    require_once __DIR__ . '/lib/stripe/init.php';
}

if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$pdo = getPdo();
ensureOrdersTables($pdo);
tryAutoLogin($pdo);

$userId = (int) $_SESSION['user_id'];
$message = "";

// -----------------------------------------------------------------------------
// 1. VEIKSMAI
// -----------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $sessionToken = getCsrfToken();
    $submittedToken = $_POST['csrf_token'] ?? '';

    // Kadangi norime parodyti gražią HTML žinutę vietoj 'exit', tikriname rankiniu būdu
    if (!is_string($submittedToken) || empty($submittedToken) || !hash_equals($sessionToken, $submittedToken)) {
        $message = '<div class="alert alert-danger">Klaida: neteisingas saugumo raktas.</div>';
    } else {
        $action = $_POST['action'];
        $orderId = (int)($_POST['order_id'] ?? 0);

        // A. Pirkėjas patvirtina gavimą (Turgelis)
        if ($action === 'confirm_delivery') {
            $stmt = $pdo->prepare("SELECT id FROM community_orders WHERE id = ? AND buyer_id = ? AND status = 'shipped'");
            $stmt->execute([$orderId, $userId]);
            
            if ($stmt->fetch()) {
                $upd = $pdo->prepare("UPDATE community_orders SET status = 'delivered', delivered_at = NOW() WHERE id = ?");
                $upd->execute([$orderId]);
                $message = '<div class="alert alert-success">Prekės gavimas patvirtintas!</div>';
            }
        }
        
        // B. Pardavėjas pažymi kaip išsiųstą
        elseif ($action === 'mark_shipped') {
            $trackingNumber = trim($_POST['tracking_number'] ?? '');
            if (empty($trackingNumber)) {
                $message = '<div class="alert alert-danger">Būtina įvesti sekimo numerį!</div>';
            } else {
                $stmt = $pdo->prepare("SELECT id, status, buyer_id FROM community_orders WHERE id = ? AND seller_id = ? AND status = 'apmokėta'");
                $stmt->execute([$orderId, $userId]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($order) {
                    $stmt = $pdo->prepare("UPDATE community_orders SET status = 'shipped', tracking_number = ?, shipped_at = NOW() WHERE id = ?");
                    $stmt->execute([$trackingNumber, $orderId]);
                    $message = '<div class="alert alert-success">Užsakymas pažymėtas kaip išsiųstas!</div>';

                    // Siunčiame laišką pirkėjui
                    $buyerStmt = $pdo->prepare("SELECT email, name FROM users WHERE id = ?");
                    $buyerStmt->execute([$order['buyer_id']]);
                    $buyer = $buyerStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($buyer) {
                        $subject = "Jūsų turgelio prekė išsiųsta!";
                        $body = "Sveiki, " . htmlspecialchars($buyer['name'] ?? 'Pirkėjau') . ",<br><br>";
                        $body .= "Pardavėjas išsiuntė jūsų užsakymą #C-{$orderId}.<br>";
                        $body .= "Sekimo numeris: <strong>" . htmlspecialchars($trackingNumber) . "</strong><br><br>";
                        $body .= "Kai gausite prekę, prašome prisijungti prie paskyros ir patvirtinti gavimą.";
                        sendEmail($buyer['email'], $subject, $body);
                    }
                } else {
                    $message = '<div class="alert alert-warning">Veiksmas negalimas.</div>';
                }
            }
        }
    }
}

// -----------------------------------------------------------------------------
// 2. FACEBOOK PIXEL LOGIKA
// -----------------------------------------------------------------------------
$newPurchaseScript = '';
if (!empty($_SESSION['flash_success']) && strpos($_SESSION['flash_success'], 'Apmokėjimas patvirtintas') !== false) {
    $latestOrderStmt = $pdo->prepare('SELECT id, total FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 1');
    $latestOrderStmt->execute([$userId]);
    $latest = $latestOrderStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($latest) {
        $safeTotal = (float)$latest['total'];
        $safeId = (int)$latest['id'];
        $newPurchaseScript = "
        <script>
          if(typeof fbq === 'function') {
              fbq('track', 'Purchase', {
                value: {$safeTotal},
                currency: 'EUR',
                content_ids: ['{$safeId}'],
                content_type: 'product'
              });
          }
        </script>";
    }
}

// -----------------------------------------------------------------------------
// 3. DUOMENŲ GAVIMAS IR STRIPE BALANSAS
// -----------------------------------------------------------------------------

// Vartotojo duomenys (Stripe statusui)
$stmt = $pdo->prepare('SELECT id, name, email, stripe_account_id, stripe_onboarding_completed FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Stripe Balanso gavimas, jei vartotojas susietas
$stripeBalanceAvailable = 0;
$stripeBalancePending = 0;

if (!empty($user['stripe_account_id']) && !empty($user['stripe_onboarding_completed'])) {
    try {
        $stripeSecret = getenv('STRIPE_SECRET_KEY') ?: ($_ENV['STRIPE_SECRET_KEY'] ?? '');
        if ($stripeSecret && class_exists('\Stripe\Stripe')) {
            \Stripe\Stripe::setApiKey($stripeSecret);
            
            $balance = \Stripe\Balance::retrieve([], ['stripe_account' => $user['stripe_account_id']]);
            
            foreach ($balance->available as $av) {
                if (strtolower($av->currency) === 'eur') {
                    $stripeBalanceAvailable += $av->amount;
                }
            }
            foreach ($balance->pending as $pe) {
                if (strtolower($pe->currency) === 'eur') {
                    $stripeBalancePending += $pe->amount;
                }
            }
            
            $stripeBalanceAvailable = $stripeBalanceAvailable / 100;
            $stripeBalancePending = $stripeBalancePending / 100;
        }
    } catch (\Exception $e) {
        error_log("Klaida gaunant Stripe balansą pardavėjui {$userId}: " . $e->getMessage());
    }
}


// A. Parduotuvės užsakymai (Pirkėjas)
$orderStmt = $pdo->prepare('
    SELECT * FROM orders 
    WHERE user_id = ? 
    ORDER BY created_at DESC
');
$orderStmt->execute([$userId]);
$shopOrders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

// Tikros parduotuvės prekės
$itemStmt = $pdo->prepare('
    SELECT 
        oi.*, 
        p.title, 
        p.image_url,
        "product" as item_type
    FROM order_items oi 
    LEFT JOIN products p ON p.id = oi.product_id 
    WHERE oi.order_id = ?
');

// Užklausa turgelio prekėms, kurios buvo įsigytos TAME PAČIAME krepšelyje (laiko atžvilgiu)
$commSameOrderStmt = $pdo->prepare("
    SELECT co.*, cl.title, cl.image_url 
    FROM community_orders co
    LEFT JOIN community_listings cl ON co.item_id = cl.id
    WHERE co.buyer_id = ? 
      AND ABS(TIMESTAMPDIFF(SECOND, co.created_at, ?)) < 120
");

// B. Turgelio pirkimai (Pirkėjas) - atskiras tabas
$commStmt = $pdo->prepare("
    SELECT co.*, cl.title, cl.image_url, u.name as seller_name 
    FROM community_orders co
    LEFT JOIN community_listings cl ON co.item_id = cl.id
    LEFT JOIN users u ON co.seller_id = u.id
    WHERE co.buyer_id = ? 
    ORDER BY co.created_at DESC
");
$commStmt->execute([$userId]);
$communityOrders = $commStmt->fetchAll(PDO::FETCH_ASSOC);

// C. Turgelio pardavimai (Pardavėjas)
$salesStmt = $pdo->prepare("
    SELECT co.*, cl.title, cl.image_url, u.name as p_name, u.email as p_email
    FROM community_orders co
    LEFT JOIN community_listings cl ON co.item_id = cl.id
    LEFT JOIN users u ON co.buyer_id = u.id
    WHERE co.seller_id = ? 
    ORDER BY co.created_at DESC
");
$salesStmt->execute([$userId]);
$sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

// Skirtukų logika
$activeTab = $_GET['tab'] ?? 'shop';
if (!in_array($activeTab, ['shop', 'community_buy', 'community_sell'])) {
    $activeTab = 'shop';
}
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Užsakymai ir Pardavimai | Cukrinukas.lt</title>
  <?php echo headerStyles(); ?>
  <style>
    :root {
      --bg: #f7f7fb; --card: #ffffff; --border: #e4e7ec;
      --text-main: #0f172a; --text-muted: #475467;
      --accent: #2563eb; --accent-hover: #1d4ed8;
      --success-bg: #ecfdf5; --success-text: #065f46; --success-border: #6ee7b7;
      --warning-bg: #fffbeb; --warning-text: #92400e; --warning-border: #fcd34d;
      --danger-bg: #fef2f2; --danger-text: #991b1b; --danger-border: #fca5a5;
      --neutral-bg: #f1f5f9; --neutral-text: #475467; --neutral-border: #cbd5e1;
    }
    * { box-sizing: border-box; }
    body { margin:0; background: var(--bg); color: var(--text-main); font-family: 'Inter', sans-serif; }
    a { color:inherit; text-decoration:none; transition: color .2s; }
    
    .page { max-width: 1200px; margin:0 auto; padding:32px 20px 72px; display:flex; flex-direction:column; gap:28px; }

    /* Hero Section */
    .hero { 
        background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        border:1px solid #dbeafe; border-radius:24px; padding:32px; 
        display:flex; align-items:center; justify-content:space-between; gap:24px; flex-wrap:wrap; 
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }
    .hero h1 { margin:0 0 8px; font-size:28px; color:#1e3a8a; letter-spacing:-0.5px; }
    .hero p { margin:0; color:#1e40af; line-height:1.5; max-width:520px; font-size:15px; }
    .pill { 
        display:inline-flex; align-items:center; gap:8px; padding:6px 12px; border-radius:999px; 
        background:#fff; border:1px solid #bfdbfe; font-weight:600; font-size:13px; color:#1e40af; margin-bottom: 12px;
    }

    /* Layout & Tabs */
    .layout { display:grid; grid-template-columns: 1fr 320px; gap:24px; align-items:start; }
    @media(max-width: 900px){ .layout { grid-template-columns:1fr; } }

    .nav-tabs { display: flex; gap: 10px; margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 12px; flex-wrap: wrap; }
    .nav-tab { 
        padding: 8px 16px; border-radius: 8px; font-weight: 600; font-size: 14px; cursor: pointer; color: var(--text-muted); 
        transition: all 0.2s; background: transparent; border: 1px solid transparent;
    }
    .nav-tab:hover { background: #fff; color: var(--text-main); }
    .nav-tab.active { background: #fff; color: var(--accent); border-color: var(--border); box-shadow: 0 2px 4px rgba(0,0,0,0.02); }

    .section-header { display:flex; align-items:center; justify-content:space-between; margin-bottom: 16px; }
    .section-header h2 { margin:0; font-size:20px; color: var(--text-main); font-weight: 700; }
    .section-header span { font-size: 13px; color: var(--text-muted); font-weight: 500; background: #e2e8f0; padding: 2px 8px; border-radius: 12px; }

    /* Order Cards */
    .order-list { display:flex; flex-direction: column; gap:20px; }
    .card { background:var(--card); border:1px solid var(--border); border-radius:20px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
    
    .card-header { padding: 16px 24px; background: #f8fafc; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
    .order-meta { display: flex; gap: 24px; align-items: center; }
    .meta-group { display: flex; flex-direction: column; gap: 2px; }
    .meta-label { font-size: 10px; text-transform: uppercase; font-weight: 700; color: var(--text-muted); letter-spacing: 0.5px; }
    .meta-value { font-size: 14px; font-weight: 600; color: var(--text-main); font-family: 'Roboto Mono', monospace; }
    
    .status-badge { padding:5px 12px; border-radius:6px; font-size:12px; font-weight:600; text-transform: uppercase; letter-spacing: 0.5px; }
    .st-pending, .st-hold, .st-shipped { background: var(--warning-bg); color: var(--warning-text); border: 1px solid var(--warning-border); }
    .st-paid, .st-delivered { background: var(--success-bg); color: var(--success-text); border: 1px solid var(--success-border); }
    .st-cancelled { background: var(--danger-bg); color: var(--danger-text); border: 1px solid var(--danger-border); }
    .st-action-required { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

    .card-body { padding: 24px; }
    .delivery-info { font-size: 13px; color: var(--text-main); margin-bottom: 20px; display: flex; align-items: flex-start; gap: 10px; background: #f8fafc; padding: 12px; border-radius: 12px; border: 1px dashed var(--border); }
    .delivery-info p { margin: 0 0 4px 0; }
    .delivery-info p:last-child { margin: 0; }

    .item-list { display:grid; gap:16px; margin-bottom: 24px; }
    .item { display:flex; gap:16px; align-items:center; }
    .item img { width:64px; height:64px; object-fit:cover; border-radius:12px; border:1px solid var(--border); background: #fff; padding: 4px; flex-shrink: 0; }
    .item-details { flex:1; min-width: 0; }
    .item-title { font-weight:600; font-size:15px; color:var(--text-main); margin-bottom: 2px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .item-meta { font-size:13px; color: var(--text-main); font-weight: 500; }
    .item-price { font-weight:700; font-size:15px; color:var(--text-main); text-align: right; white-space: nowrap; }

    .card-footer { padding-top: 20px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; gap: 16px; }
    .card-footer.block-footer { display: block; }
    .total-price { display: flex; flex-direction: column; align-items: flex-end; }
    .total-label { font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; }
    .total-value { font-size: 18px; font-weight: 700; color: var(--accent); }

    /* Forms */
    .input-group { display: flex; gap: 8px; }
    .form-control { flex: 1; padding: 10px; border-radius: 8px; border: 1px solid var(--border); }

    /* Buttons & Sidebar */
    .btn, .btn-outline { padding:10px 20px; border-radius:10px; font-weight:600; font-size:14px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; transition: all .2s; border:none; }
    .btn { background: #0f172a; color:#fff; }
    .btn:hover { background: #1e293b; transform: translateY(-1px); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
    .btn-green { background: #16a34a; color: #fff; } .btn-green:hover { background: #15803d; }
    .btn-outline { background: #fff; color: var(--text-main); border: 1px solid var(--border); }
    .btn-outline:hover { border-color: var(--accent); color: var(--accent); background: #f8fafc; }
    
    .sidebar-card { padding: 24px; background:var(--card); border:1px solid var(--border); border-radius:20px; margin-bottom: 20px; }
    .sidebar-card h3 { margin:0 0 16px; font-size:16px; font-weight: 700; }
    .sidebar-menu { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:8px; }
    .sidebar-menu a { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:10px; color: var(--text-muted); font-size:14px; font-weight:500; transition: all .2s; }
    .sidebar-menu a:hover { background: #f8fafc; color: var(--text-main); }
    .sidebar-menu a.active { background: #eff6ff; color: var(--accent); font-weight: 600; }

    .empty-state { text-align: center; padding: 64px 20px; background: #fff; border-radius: 20px; border: 1px dashed var(--border); }
    .alert { padding: 12px 16px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; }
    .alert-success { background: var(--success-bg); color: var(--success-text); border: 1px solid var(--success-border); }
    .alert-danger { background: var(--danger-bg); color: var(--danger-text); border: 1px solid var(--danger-border); }
    .alert-warning { background: var(--warning-bg); color: var(--warning-text); border: 1px solid var(--warning-border); }

    @media (max-width: 600px) { .card-header { flex-direction: column; align-items: flex-start; } .card-footer:not(.block-footer) { flex-direction: column-reverse; align-items: stretch; } .input-group { flex-direction:column; } }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'orders'); ?>
  
  <div class="page">
    <?php if ($activeTab === 'community_sell'): ?>
    <section class="hero">
      <div>
        <div class="pill">📈 Prekyba</div>
        <h1>Mano pardavimai</h1>
        <p>Čia matote visas parduotas prekes. Nepamirškite įvesti siuntos numerio, kai išsiųsite prekę.</p>
      </div>
      <div>
          <a href="/community_listing_new.php" class="btn" style="background:#fff; color:#1e40af; border:1px solid #bfdbfe;">+ Naujas skelbimas</a>
      </div>
    </section>
    <?php else: ?>
    <section class="hero">
      <div>
        <div class="pill">📦 Istorija</div>
        <h1>Mano užsakymai</h1>
        <p>Sekite užsakymų būseną ir peržiūrėkite pirkinių istoriją.</p>
      </div>
    </section>
    <?php endif; ?>

    <div class="layout">
      <div>
          <?= $message ?>

          <div class="nav-tabs">
              <a href="?tab=shop" class="nav-tab <?= $activeTab === 'shop' ? 'active' : '' ?>">Parduotuvė (<?= count($shopOrders) ?>)</a>
              <a href="?tab=community_buy" class="nav-tab <?= $activeTab === 'community_buy' ? 'active' : '' ?>">Turgelio pirkiniai (<?= count($communityOrders) ?>)</a>
              <a href="?tab=community_sell" class="nav-tab <?= $activeTab === 'community_sell' ? 'active' : '' ?>">Mano pardavimai (<?= count($sales) ?>)</a>
          </div>

          <?php if ($activeTab === 'shop'): ?>
              <?php if (!$shopOrders): ?>
                <div class="empty-state">
                  <div style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;">🛒</div>
                  <h3>Parduotuvėje dar nieko nepirkote</h3>
                  <p style="color: var(--text-muted);">Atraskite mūsų asortimentą.</p>
                  <a class="btn" href="/products.php">Pradėti apsipirkimą</a>
                </div>
              <?php else: ?>
                <div class="order-list">
                  <?php foreach ($shopOrders as $order): ?>
                    <?php 
                      // 1. Gauname parduotuvės prekes
                      $itemStmt->execute([$order['id']]); 
                      $orderItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC); 
                      
                      // 2. Gauname bendruomenės prekes iš to paties krepšelio sesijos
                      $commSameOrderStmt->execute([$userId, $order['created_at']]);
                      $coItems = $commSameOrderStmt->fetchAll(PDO::FETCH_ASSOC);

                      $statusLower = mb_strtolower($order['status'] ?? '');
                      $stClass = 'status-default';
                      if (strpos($statusLower, 'apmokėta') !== false || strpos($statusLower, 'paid') !== false) $stClass = 'st-paid';
                      elseif (strpos($statusLower, 'laukiama') !== false) $stClass = 'st-pending';
                      
                      $itemsTotal = 0;
                    ?>
                    <div class="card">
                      <div class="card-header">
                        <div class="order-meta">
                            <div class="meta-group"><span class="meta-label">Užsakymas</span><span class="meta-value">#<?= (int)$order['id']; ?></span></div>
                            <div class="meta-group"><span class="meta-label">Data</span><span class="meta-value date"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($order['created_at'] ?? 'now'))); ?></span></div>
                        </div>
                        <div class="<?= $stClass ?> status-badge"><?= htmlspecialchars($order['status'] ?? '-'); ?></div>
                      </div>
                      <div class="card-body">
                          <div class="delivery-info">
                              <span style="margin-right:8px;">🚚</span>
                              <div>
                                  <strong><?= htmlspecialchars($order['customer_name'] ?? '-'); ?></strong><br>
                                  <?= htmlspecialchars($order['customer_address'] ?? '-'); ?>
                              </div>
                          </div>
                          
                          <div class="item-list">
                            <?php foreach ($orderItems as $item): ?>
                              <?php 
                                  $isDeleted = empty($item['title']); 
                                  $itemTotal = (float)$item['price'] * (int)$item['quantity'];
                                  $itemsTotal += $itemTotal;
                                  $itemUrl = $isDeleted ? '#' : '/produktas/' . slugify($item['title']) . '-' . (int)$item['product_id'];
                              ?>
                              <div class="item">
                                <?php if (!$isDeleted): ?>
                                    <a href="<?= htmlspecialchars($itemUrl); ?>">
                                      <img src="<?= htmlspecialchars($item['image_url'] ?? '/uploads/default.png'); ?>" alt="">
                                    </a>
                                    <div class="item-details">
                                      <a href="<?= htmlspecialchars($itemUrl); ?>" class="item-title"><?= htmlspecialchars($item['title']); ?></a>
                                      <div class="item-meta"><?= (int)$item['quantity']; ?> vnt. × <?= number_format((float)$item['price'], 2); ?> €</div>
                                    </div>
                                <?php else: ?>
                                    <div style="width:64px; height:64px; display:flex; align-items:center; justify-content:center; background:#f1f5f9; border-radius:12px; border:1px dashed var(--border); font-size:24px; opacity:0.6; flex-shrink:0;">📦</div>
                                    <div class="item-details" style="opacity: 0.6;">
                                      <span class="item-title">Ištrinta prekė</span>
                                      <div class="item-meta"><?= (int)$item['quantity']; ?> vnt. × <?= number_format((float)$item['price'], 2); ?> €</div>
                                    </div>
                                <?php endif; ?>
                                <div class="item-price"><?= number_format($itemTotal, 2); ?> €</div>
                              </div>
                            <?php endforeach; ?>

                            <?php foreach ($coItems as $coItem): ?>
                              <?php 
                                  $isDeleted = empty($coItem['title']); 
                                  $coItemPrice = (float)$coItem['item_price'];
                                  $coItemTotal = (float)$coItem['total_amount'];
                                  $coQty = ($coItemTotal > 0 && $coItemPrice > 0) ? round($coItemTotal / $coItemPrice) : 1;
                                  
                                  $itemsTotal += $coItemTotal;
                                  $itemUrl = $isDeleted ? '#' : '/community_listing.php?id=' . (int)$coItem['item_id'];
                              ?>
                              <div class="item">
                                <?php if (!$isDeleted): ?>
                                    <a href="<?= htmlspecialchars($itemUrl); ?>">
                                      <img src="<?= htmlspecialchars($coItem['image_url'] ?? '/uploads/default.png'); ?>" alt="">
                                    </a>
                                    <div class="item-details">
                                      <a href="<?= htmlspecialchars($itemUrl); ?>" class="item-title"><?= htmlspecialchars($coItem['title']); ?> <span style="font-size:12px; color:var(--text-muted); font-weight:normal;">(Iš Turgelio)</span></a>
                                      <div class="item-meta"><?= $coQty; ?> vnt. × <?= number_format($coItemPrice, 2); ?> €</div>
                                    </div>
                                <?php else: ?>
                                    <div style="width:64px; height:64px; display:flex; align-items:center; justify-content:center; background:#f1f5f9; border-radius:12px; border:1px dashed var(--border); font-size:24px; opacity:0.6; flex-shrink:0;">📦</div>
                                    <div class="item-details" style="opacity: 0.6;">
                                      <span class="item-title">Ištrinta prekė (Iš Turgelio)</span>
                                      <div class="item-meta"><?= $coQty; ?> vnt. × <?= number_format($coItemPrice, 2); ?> €</div>
                                    </div>
                                <?php endif; ?>
                                <div class="item-price"><?= number_format($coItemTotal, 2); ?> €</div>
                              </div>
                            <?php endforeach; ?>
                            
                            <?php 
                              $discountAmount = (float)($order['discount_amount'] ?? 0);
                              $deliveryCost = (float)($order['shipping_amount'] ?? 0);

                              // Jeigu pristatymo suma nebuvo aiškiai išsaugota (senesni užsakymai arba specifiniai), 
                              // ją suskaičiuojame iš bendros sumos atėmę visų prekių sumą ir pridėję nuolaidą atgal.
                              if ($deliveryCost <= 0) {
                                  $deliveryCost = max(0, round((float)$order['total'] - $itemsTotal + $discountAmount, 2));
                              }

                              if ($deliveryCost > 0): 
                                $deliveryMethodName = 'Pristatymas';
                                $orderDelDetails = json_decode($order['delivery_details'] ?? '{}', true) ?: [];
                                $provKey = strtolower($orderDelDetails['locker_provider'] ?? '');
                                $provMap = ['lpexpress' => 'LP EXPRESS', 'omniva' => 'OMNIVA'];
                                $provName = $provMap[$provKey] ?? strtoupper($provKey);

                                if (!empty($order['delivery_method'])) {
                                    if ($order['delivery_method'] === 'locker') {
                                        $deliveryMethodName = 'Pristatymas paštomatu' . ($provName ? " ($provName)" : '');
                                    } elseif ($order['delivery_method'] === 'courier') {
                                        $deliveryMethodName = 'Pristatymas kurjeriu';
                                    } else {
                                        $deliveryMethodName = 'Pristatymas (' . htmlspecialchars($order['delivery_method']) . ')';
                                    }
                                }
                            ?>
                              <div class="item" style="border-top: 1px dashed var(--border); padding-top: 16px; margin-top: 8px;">
                                <div style="width:64px; height:64px; display:flex; align-items:center; justify-content:center; background:#f8fafc; border-radius:12px; border:1px dashed var(--border); font-size:24px; flex-shrink:0;">🚚</div>
                                <div class="item-details">
                                  <span class="item-title"><?= $deliveryMethodName ?></span>
                                </div>
                                <div class="item-price"><?= number_format($deliveryCost, 2); ?> €</div>
                              </div>
                            <?php endif; ?>
                            
                            <?php if ($discountAmount > 0): ?>
                              <div class="item" style="border-top: 1px dashed var(--border); padding-top: 8px; margin-top: 8px;">
                                <div style="width:64px; height:64px; display:flex; align-items:center; justify-content:center; background:#fef2f2; color:#991b1b; border-radius:12px; border:1px dashed #fca5a5; font-size:24px; flex-shrink:0;">🎟️</div>
                                <div class="item-details">
                                  <span class="item-title">Nuolaida <?= !empty($order['discount_code']) ? '(' . htmlspecialchars($order['discount_code']) . ')' : '' ?></span>
                                </div>
                                <div class="item-price" style="color:#991b1b;">-<?= number_format($discountAmount, 2); ?> €</div>
                              </div>
                            <?php endif; ?>
                          </div>
                          
                          <div class="card-footer">
                              <div style="flex-grow:1;"><a class="btn-outline" href="/products.php">Pirkti vėl</a></div>
                              <div class="total-price">
                                  <span class="total-label">Viso mokėti</span>
                                  <span class="total-value"><?= number_format((float)$order['total'], 2); ?> €</span>
                              </div>
                          </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

          <?php elseif ($activeTab === 'community_buy'): ?>
              <?php if (!$communityOrders): ?>
                <div class="empty-state">
                  <div style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;">🤝</div>
                  <h3>Turgelyje dar nieko nepirkote</h3>
                  <a class="btn" href="/community_market.php">Naršyti turgelį</a>
                </div>
              <?php else: ?>
                <div class="order-list">
                  <?php foreach ($communityOrders as $order): ?>
                    <?php 
                      $stClass = 'status-default';
                      $stText = $order['status'] ?? '';
                      if ($stText == 'paid' || $stText == 'apmokėta') { $stClass = 'st-pending'; $stText = 'Laukiama išsiuntimo'; }
                      if ($stText == 'shipped') { $stClass = 'st-shipped'; $stText = 'Išsiųsta'; }
                      if ($stText == 'delivered') { $stClass = 'st-paid'; $stText = 'Gauta / Užbaigta'; }
                      
                      $isCommDeleted = empty($order['title']);
                    ?>
                    <div class="card">
                      <div class="card-header">
                        <div class="order-meta">
                            <div class="meta-group"><span class="meta-label">Užsakymas</span><span class="meta-value">#C-<?= (int)$order['id']; ?></span></div>
                            <div class="meta-group"><span class="meta-label">Pardavėjas</span><span class="meta-value date" style="color: var(--accent);"><?= htmlspecialchars($order['seller_name'] ?? '-'); ?></span></div>
                        </div>
                        <div class="<?= $stClass ?> status-badge"><?= htmlspecialchars($stText); ?></div>
                      </div>
                      <div class="card-body">
                          <?php if (!empty($order['tracking_number'])): ?>
                            <div class="delivery-info">
                                <span style="margin-right:8px; color:var(--accent);">📦</span>
                                <div><strong>Sekimo numeris:</strong> <?= htmlspecialchars($order['tracking_number']); ?></div>
                            </div>
                          <?php endif; ?>
                          
                          <div class="item-list">
                              <div class="item">
                                <?php if (!$isCommDeleted): ?>
                                    <a href="/community_listing.php?id=<?= $order['item_id'] ?>">
                                      <img src="<?= htmlspecialchars($order['image_url'] ?? '/uploads/default.png'); ?>" alt="">
                                    </a>
                                    <div class="item-details">
                                      <a href="/community_listing.php?id=<?= $order['item_id'] ?>" class="item-title" style="text-decoration:none;"><?= htmlspecialchars($order['title']); ?></a>
                                      <div class="item-meta">1 vnt.</div>
                                    </div>
                                <?php else: ?>
                                    <div style="width:64px; height:64px; display:flex; align-items:center; justify-content:center; background:#f1f5f9; border-radius:12px; border:1px dashed var(--border); font-size:24px; opacity:0.6; flex-shrink:0;">📦</div>
                                    <div class="item-details" style="opacity: 0.6;">
                                      <span class="item-title">Skelbimas nebeaktyvus</span>
                                      <div class="item-meta">1 vnt.</div>
                                    </div>
                                <?php endif; ?>
                                <div class="item-price"><?= number_format((float)$order['total_amount'], 2); ?> €</div>
                              </div>
                          </div>
                          
                          <div class="card-footer">
                              <div style="flex-grow:1;">
                                <?php if (($order['status'] ?? '') === 'shipped'): ?>
                                    <form method="POST" onsubmit="return confirm('Ar tikrai gavote prekę?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="confirm_delivery">
                                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                        <button type="submit" class="btn btn-green">Gavau prekę</button>
                                    </form>
                                <?php elseif (($order['status'] ?? '') === 'delivered'): ?>
                                    <span style="color: var(--success-text); font-weight: 600;">✓ Užsakymas baigtas</span>
                                <?php endif; ?>
                              </div>
                              <div class="total-price">
                                  <span class="total-label">Sumokėta</span>
                                  <span class="total-value"><?= number_format((float)$order['total_amount'], 2); ?> €</span>
                              </div>
                          </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              
          <?php elseif ($activeTab === 'community_sell'): ?>
              <?php if (empty($sales)): ?>
                <div class="empty-state">
                  <div style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;">📦</div>
                  <h3>Jūs dar neturite pardavimų</h3>
                  <p style="color: var(--text-muted);">Įkelkite nereikalingus daiktus ir pradėkite prekiauti.</p>
                  <a class="btn" href="/community_listing_new.php">Įkelti skelbimą</a>
                </div>
              <?php else: ?>
                <div class="order-list">
                  <?php foreach ($sales as $sale): ?>
                    <?php 
                        $isPending = (($sale['status'] ?? '') === 'apmokėta');
                        $statusText = '';
                        $statusClass = '';
                        
                        switch($sale['status'] ?? '') {
                            case 'apmokėta': $statusText = 'REIKIA IŠSIŲSTI'; $statusClass='st-action-required'; break;
                            case 'shipped': $statusText = 'IŠSIŲSTA (Laukiama)'; $statusClass='st-shipped'; break;
                            case 'delivered': $statusText = 'UŽBAIGTA'; $statusClass='st-delivered'; break;
                            default: $statusText = $sale['status'] ?? 'Nežinoma'; $statusClass='st-pending';
                        }
                        
                        $isSaleDeleted = empty($sale['title']);

                        // Ištraukiame pristatymo informaciją ir JSON
                        $deliveryDetails = json_decode($sale['delivery_details'] ?? '{}', true) ?: [];
                        
                        // Gauname duomenis iš atitinkamų stulpelių bei JSON atgalinio suderinamumo
                        $method = $sale['delivery_method'] ?? $deliveryDetails['method'] ?? 'Nežinomas';
                        $phone = $sale['customer_phone'] ?? $deliveryDetails['phone'] ?? $deliveryDetails['contact_phone'] ?? '-';
                        $email = $sale['p_email'] ?? $deliveryDetails['contact_email'] ?? '-';
                        $lockerName = $deliveryDetails['locker_name'] ?? $deliveryDetails['locker_address'] ?? '';
                        $address = $sale['customer_address'] ?? '-';
                        $notes = $sale['note'] ?? $deliveryDetails['notes'] ?? '';
                        $buyerName = $sale['customer_name'] ?? $deliveryDetails['contact_name'] ?? $sale['p_name'] ?? 'Nežinomas pirkėjas';

                        // Formatuojame pristatymo būdo pavadinimą
                        $provKey = strtolower($deliveryDetails['locker_provider'] ?? '');
                        $provMap = ['lpexpress' => 'LP EXPRESS', 'omniva' => 'OMNIVA'];
                        $provName = $provMap[$provKey] ?? strtoupper($provKey);

                        $methodText = 'Nežinomas';
                        if ($method === 'locker') $methodText = 'Paštomatas' . ($provName ? " ($provName)" : '');
                        elseif ($method === 'courier') $methodText = 'Kurjeris';
                        elseif ($method === 'pickup') $methodText = 'Atsiėmimas vietoje';
                        elseif ($method !== 'Nežinomas') $methodText = htmlspecialchars($method);
                    ?>
                    <div class="card">
                      <div class="card-header">
                        <div class="order-meta">
                            <div class="meta-group"><span class="meta-label">Užsakymas</span><span class="meta-value">#C-<?= (int)$sale['id']; ?></span></div>
                            <div class="meta-group"><span class="meta-label">Data</span><span class="meta-value date"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($sale['created_at'] ?? 'now'))); ?></span></div>
                        </div>
                        <div class="status-badge <?= $statusClass; ?>"><?= htmlspecialchars($statusText); ?></div>
                      </div>

                      <div class="card-body">
                          <div class="delivery-info" style="display:flex; flex-direction:column; gap:16px;">
                              
                              <div style="display:flex; align-items:flex-start; gap:10px;">
                                  <span style="font-size:18px; color:var(--accent); margin-top:2px;">👤</span>
                                  <div>
                                      <p><strong>Pirkėjas:</strong> <?= htmlspecialchars($buyerName); ?></p>
                                      <p><strong>El. paštas:</strong> <a href="mailto:<?= htmlspecialchars($email); ?>" style="color:var(--accent);"><?= htmlspecialchars($email); ?></a></p>
                                      <?php if ($phone !== '-'): ?>
                                          <p><strong>Telefonas:</strong> <?= htmlspecialchars($phone); ?></p>
                                      <?php endif; ?>
                                  </div>
                              </div>

                              <div style="display:flex; align-items:flex-start; gap:10px; border-top:1px dashed var(--border); padding-top:16px;">
                                  <span style="font-size:18px; color:var(--accent); margin-top:2px;">🚚</span>
                                  <div>
                                      <p><strong>Pristatymo būdas:</strong> <?= $methodText ?></p>
                                      <?php if ($method === 'locker' && $lockerName): ?>
                                          <p><strong>Paštomatas:</strong> <?= htmlspecialchars($lockerName); ?></p>
                                      <?php else: ?>
                                          <p><strong>Adresas:</strong> <?= htmlspecialchars($address); ?></p>
                                      <?php endif; ?>
                                      
                                      <?php if (!empty($notes)): ?>
                                          <p style="margin-top: 8px;"><strong>Pirkėjo pastaba:</strong><br> <em><?= nl2br(htmlspecialchars($notes)); ?></em></p>
                                      <?php endif; ?>
                                  </div>
                              </div>
                          </div>

                          <div class="item-list">
                              <div class="item">
                                <?php if (!$isSaleDeleted): ?>
                                    <img src="<?= htmlspecialchars($sale['image_url'] ?? '/uploads/default.png'); ?>" alt="">
                                    <div class="item-details">
                                      <span class="item-title"><?= htmlspecialchars($sale['title']); ?></span>
                                      <div class="item-meta">1 vnt.</div>
                                    </div>
                                <?php else: ?>
                                    <div style="width:64px; height:64px; display:flex; align-items:center; justify-content:center; background:#f1f5f9; border-radius:12px; border:1px dashed var(--border); font-size:24px; opacity:0.6; flex-shrink:0;">📦</div>
                                    <div class="item-details" style="opacity: 0.6;">
                                      <span class="item-title">Skelbimas nebeaktyvus</span>
                                      <div class="item-meta">1 vnt.</div>
                                    </div>
                                <?php endif; ?>
                                <div class="item-price"><?= number_format((float)$sale['total_amount'], 2); ?> €</div>
                              </div>
                          </div>

                          <div class="card-footer block-footer">
                              <div style="width: 100%;">
                                <?php if ($isPending): ?>
                                    <form method="POST" style="margin-bottom:12px;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="mark_shipped">
                                        <input type="hidden" name="order_id" value="<?= $sale['id'] ?>">
                                        
                                        <label class="meta-label" style="display:block; margin-bottom:6px;">Įveskite siuntos sekimo numerį:</label>
                                        <div class="input-group">
                                            <input type="text" name="tracking_number" class="form-control" placeholder="pvz. LP123456789LT" required>
                                            <button class="btn" type="submit">Patvirtinti išsiuntimą</button>
                                        </div>
                                    </form>
                                <?php elseif (!empty($sale['tracking_number'])): ?>
                                    <div style="font-size: 14px; background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid var(--border);">
                                        <strong>Sekimo nr.:</strong> <span style="font-family: monospace; font-size: 15px; color: var(--accent);"><?= htmlspecialchars($sale['tracking_number']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-top:16px; padding-top:16px; border-top:1px dashed var(--border);">
                                    <div style="font-size:12px; color:var(--text-muted);">
                                        <?php if (($sale['payout_status'] ?? '') == 'hold'): ?>
                                            <span style="color:#d97706; display: flex; align-items: center; gap: 4px;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                                Laukiama pervedimo patvirtinimo
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="total-price">
                                        <span class="total-label">Viso gauta</span>
                                        <span class="total-value"><?= number_format((float)$sale['total_amount'], 2); ?> €</span>
                                    </div>
                                </div>
                              </div>
                          </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
          <?php endif; ?>
      </div>

      <aside>  
          <div class="card sidebar-card" style="margin-top:20px;">
            <h3>Pardavėjo statusas</h3>
            <p style="font-size:13px; color:var(--text-muted); line-height:1.5; margin-bottom:12px;">
                Norėdami parduoti prekes bendruomenės turgelyje, turite susieti savo sąskaitą su Stripe.
            </p>
            
            <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
                <span style="font-size: 20px; color: #635bff; font-weight: bold;">S</span>
                <span style="font-weight:600; font-size:14px;">Stripe Express</span>
                <?php if (!empty($user['stripe_onboarding_completed'])): ?>
                    <span style="background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; border: 1px solid #bbf7d0;">Patvirtinta</span>
                <?php else: ?>
                    <span style="background: #f1f5f9; color: #64748b; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; border: 1px solid #e2e8f0;">Nepradėta</span>
                <?php endif; ?>
            </div>

            <?php if (!empty($user['stripe_onboarding_completed'])): ?>
                
                <div style="margin-bottom: 16px; padding: 12px; background: #f8fafc; border-radius: 8px; border: 1px solid var(--border);">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span style="font-size: 13px; color: var(--text-muted);">Prieinamas likutis:</span>
                        <strong style="font-size: 14px; color: var(--success-text);"><?= number_format($stripeBalanceAvailable, 2) ?> €</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="font-size: 13px; color: var(--text-muted);">Laukiama (Pending):</span>
                        <strong style="font-size: 14px; color: var(--warning-text);"><?= number_format($stripeBalancePending, 2) ?> €</strong>
                    </div>
                </div>
                
                <a href="stripe_connect.php" style="color: #635bff; text-decoration: none; font-weight: 500; font-size: 13px;">Stripe Valdymas &rarr;</a>
            <?php else: ?>
                <a href="stripe_connect.php" style="display: inline-flex; align-items: center; background-color: #635bff; color: white; border: none; padding: 8px 12px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 13px; transition: background-color 0.2s;">
                   <span style="margin-right: 6px; font-weight: bold; font-family: sans-serif;">S</span> <?php echo !empty($user['stripe_account_id']) ? 'Tęsti registraciją' : 'Tapti pardavėju'; ?>
                </a>
            <?php endif; ?>
          </div>

          <div class="card sidebar-card" style="margin-top:20px; background: #f8fafc; border: 1px dashed var(--border);">
              <h3>Pagalba</h3>
              <p style="font-size:13px; color:var(--text-muted); line-height:1.5; margin-bottom:12px;">Kilo klausimų dėl užsakymo ar pardavimo? Susisiekite su mumis.</p>
              <a href="mailto:labas@cukrinukas.lt" style="font-size:13px; font-weight:600; color:var(--accent);">labas@cukrinukas.lt</a>
          </div>
      </aside>
    </div>
  </div>

  <?php renderFooter($pdo); ?>
  <?php echo $newPurchaseScript; ?>
</body>
</html>
