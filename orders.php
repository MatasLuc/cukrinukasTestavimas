<?php
// orders.php - Pirkėjo užsakymai (Parduotuvė + Turgelis)
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/helpers.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$pdo = getPdo();
ensureOrdersTables($pdo);
// ensureProductsTable($pdo); // Galima palikti, jei reikia
// ensureAdminAccount($pdo);
tryAutoLogin($pdo);

$userId = (int) $_SESSION['user_id'];
$message = "";

// -----------------------------------------------------------------------------
// 1. VEIKSMAI: Pirkėjas patvirtina gavimą (Turgelis)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_delivery') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = '<div class="alert alert-danger mb-4">Saugumo klaida. Bandykite dar kartą.</div>';
    } else {
        $orderId = (int)$_POST['order_id'];
        // Tikriname, ar užsakymas priklauso vartotojui ir dar nepristatytas
        $stmt = $pdo->prepare("SELECT id, status FROM community_orders WHERE id = ? AND buyer_id = ?");
        $stmt->execute([$orderId, $userId]);
        $ord = $stmt->fetch();

        if ($ord && $ord['status'] !== 'delivered') {
            $stmt = $pdo->prepare("UPDATE community_orders SET status = 'delivered', delivered_at = NOW() WHERE id = ?");
            $stmt->execute([$orderId]);
            $message = '<div class="alert alert-success mb-4">Ačiū! Prekės gavimas patvirtintas.</div>';
        }
    }
}

// -----------------------------------------------------------------------------
// 2. FACEBOOK PIXEL LOGIKA (Tik parduotuvės užsakymams)
// -----------------------------------------------------------------------------
$newPurchaseScript = '';
if (!empty($_SESSION['flash_success']) && strpos($_SESSION['flash_success'], 'Apmokėjimas patvirtintas') !== false) {
    $latestOrderStmt = $pdo->prepare('SELECT id, total_price FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 1');
    $latestOrderStmt->execute([$userId]);
    $latest = $latestOrderStmt->fetch();
    
    if ($latest) {
        $safeTotal = (float)($latest['total_price'] / 100); // Konvertuojam centus į eurus jei reikia, arba paliekam kaip buvo
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
// 3. DUOMENŲ GAVIMAS
// -----------------------------------------------------------------------------

// A. Parduotuvės užsakymai
$shopStmt = $pdo->prepare('
    SELECT * FROM orders 
    WHERE user_id = ? 
    ORDER BY created_at DESC
');
$shopStmt->execute([$userId]);
$shopOrders = $shopStmt->fetchAll();

// Paruošiame prekių gavimą parduotuvės užsakymams
$shopItemStmt = $pdo->prepare('
    SELECT oi.*, p.title, p.image_url 
    FROM order_items oi 
    LEFT JOIN products p ON p.id = oi.product_id 
    WHERE oi.order_id = ?
');

// B. Turgelio užsakymai
$commStmt = $pdo->prepare("
    SELECT co.*, u.username as seller_name 
    FROM community_orders co
    LEFT JOIN users u ON co.seller_id = u.id
    WHERE co.buyer_id = ? 
    ORDER BY co.created_at DESC
");
$commStmt->execute([$userId]);
$communityOrders = $commStmt->fetchAll();

// Suskaičiuojame bendrą kiekį
$totalOrdersCount = count($shopOrders) + count($communityOrders);

// Nustatome aktyvų tabą
$activeTab = isset($_GET['tab']) && $_GET['tab'] === 'community' ? 'community' : 'shop';

?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mano užsakymai | Cukrinukas.lt</title>
  <?php echo headerStyles(); ?>
  <style>
    :root {
      --bg: #f7f7fb;
      --card: #ffffff;
      --border: #e4e7ec;
      --text-main: #0f172a;
      --text-muted: #475467;
      --accent: #2563eb;
      --accent-hover: #1d4ed8;
      
      --success-bg: #ecfdf5;
      --success-text: #065f46;
      --success-border: #6ee7b7;

      --warning-bg: #fffbeb;
      --warning-text: #92400e;
      --warning-border: #fcd34d;

      --danger-bg: #fef2f2;
      --danger-text: #991b1b;
      --danger-border: #fca5a5;

      --neutral-bg: #f1f5f9;
      --neutral-text: #475467;
      --neutral-border: #cbd5e1;
    }
    * { box-sizing: border-box; }
    body { margin:0; background: var(--bg); color: var(--text-main); font-family: 'Inter', sans-serif; }
    a { color:inherit; text-decoration:none; transition: color .2s; }
    
    .page { max-width: 1200px; margin:0 auto; padding:32px 20px 72px; display:flex; flex-direction:column; gap:28px; }

    /* Hero */
    .hero { 
        background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        border:1px solid #dbeafe; 
        border-radius:24px; 
        padding:32px; 
        display:flex; 
        align-items:center; 
        justify-content:space-between; 
        gap:24px; 
        flex-wrap:wrap; 
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }
    .hero h1 { margin:0 0 8px; font-size:28px; color:#1e3a8a; letter-spacing:-0.5px; }
    .hero p { margin:0; color:#1e40af; line-height:1.5; max-width:520px; font-size:15px; }
    
    .pill { 
        display:inline-flex; align-items:center; gap:8px; 
        padding:6px 12px; border-radius:999px; 
        background:#fff; border:1px solid #bfdbfe; 
        font-weight:600; font-size:13px; color:#1e40af; 
        margin-bottom: 12px;
    }

    /* Layout */
    .layout { display:grid; grid-template-columns: 1fr 320px; gap:24px; align-items:start; }
    @media(max-width: 900px){ .layout { grid-template-columns:1fr; } }

    .section-header { display:flex; align-items:center; justify-content:space-between; margin-bottom: 16px; }
    .section-header h2 { margin:0; font-size:20px; color: var(--text-main); font-weight: 700; }
    .section-header span { font-size: 13px; color: var(--text-muted); font-weight: 500; background: #e2e8f0; padding: 2px 8px; border-radius: 12px; }

    /* Tabs */
    .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 10px; }
    .tab-btn {
        padding: 8px 16px; border-radius: 8px; border: none; background: transparent;
        color: var(--text-muted); font-weight: 600; cursor: pointer; transition: all 0.2s;
    }
    .tab-btn.active { background: #eff6ff; color: var(--accent); }
    .tab-btn:hover:not(.active) { background: #f8fafc; }

    /* Cards */
    .order-list { display:flex; flex-direction: column; gap:20px; }
    .card { 
        background:var(--card); 
        border:1px solid var(--border); 
        border-radius:20px; 
        overflow: hidden;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        transition: transform .2s, box-shadow .2s;
    }
    
    .sidebar-card { padding: 24px; }
    .sidebar-card h3 { margin:0 0 16px; font-size:16px; color: var(--text-main); font-weight: 700; }
    .sidebar-menu { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:8px; }
    .sidebar-menu a { 
        display:flex; align-items:center; gap:10px; 
        padding:10px 12px; border-radius:10px; 
        color: var(--text-muted); font-size:14px; font-weight:500;
        transition: all .2s;
    }
    .sidebar-menu a:hover { background: #f8fafc; color: var(--text-main); }
    .sidebar-menu a.active { background: #eff6ff; color: var(--accent); font-weight: 600; }
    
    .card-header {
        padding: 16px 24px;
        background: #f8fafc;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }
    .order-meta { display: flex; gap: 24px; align-items: center; }
    .meta-group { display: flex; flex-direction: column; gap: 2px; }
    .meta-label { font-size: 10px; text-transform: uppercase; font-weight: 700; color: var(--text-muted); letter-spacing: 0.5px; }
    .meta-value { font-size: 14px; font-weight: 600; color: var(--text-main); font-family: 'Roboto Mono', monospace; }
    .meta-value.date { font-family: 'Inter', sans-serif; }
    
    .status-badge { 
        padding:5px 12px; border-radius:6px; 
        font-size:12px; font-weight:600; text-transform: uppercase; letter-spacing: 0.5px;
        display:inline-flex; align-items:center; gap:6px;
    }
    /* Status Colors */
    .status-paid, .status-delivered { background: var(--success-bg); color: var(--success-text); border: 1px solid var(--success-border); }
    .status-pending, .status-hold { background: var(--warning-bg); color: var(--warning-text); border: 1px solid var(--warning-border); }
    .status-shipped { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
    .status-cancelled, .status-disputed { background: var(--danger-bg); color: var(--danger-text); border: 1px solid var(--danger-border); }
    .status-default { background: var(--neutral-bg); color: var(--neutral-text); border: 1px solid var(--neutral-border); }

    .card-body { padding: 24px; }
    
    .delivery-info {
        font-size: 13px; color: var(--text-muted); margin-bottom: 20px; 
        display: flex; align-items: flex-start; gap: 10px;
        background: #f8fafc; padding: 12px; border-radius: 12px; border: 1px dashed var(--border);
    }

    .item-list { display:grid; gap:16px; margin-bottom: 24px; }
    .item { display:flex; gap:16px; align-items:center; }
    .item img { 
        width:64px; height:64px; object-fit:cover; 
        border-radius:12px; border:1px solid var(--border); 
        background: #fff; padding: 4px;
        flex-shrink: 0;
    }
    .item-details { flex:1; min-width: 0; }
    .item-title { font-weight:600; font-size:15px; color:var(--text-main); margin-bottom: 2px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .item-variation { font-size: 12px; color: var(--text-muted); margin-bottom: 4px; display: block; }
    .item-meta { font-size:13px; color: var(--text-main); font-weight: 500; }
    .item-price { font-weight:700; font-size:15px; color:var(--text-main); text-align: right; white-space: nowrap; }

    .card-footer {
        padding-top: 20px;
        border-top: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
    }
    
    .total-price { display: flex; flex-direction: column; align-items: flex-end; }
    .total-label { font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; }
    .total-value { font-size: 18px; font-weight: 700; color: var(--accent); }

    /* Buttons */
    .btn, .btn-outline { 
        padding:10px 20px; border-radius:10px; 
        font-weight:600; font-size:14px;
        cursor:pointer; text-decoration:none; 
        display:inline-flex; align-items:center; justify-content:center;
        transition: all .2s; border:none;
    }
    .btn { background: #0f172a; color:#fff; }
    .btn:hover { background: #1e293b; color:#fff; transform: translateY(-1px); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
    .btn-green { background: #16a34a; color: white; }
    .btn-green:hover { background: #15803d; }
    
    .btn-outline { background: #fff; color: var(--text-main); border: 1px solid var(--border); }
    .btn-outline:hover { border-color: var(--accent); color: var(--accent); background: #f8fafc; }

    .empty-state {
        text-align: center;
        padding: 64px 20px;
        background: #fff;
        border-radius: 20px;
        border: 1px dashed var(--border);
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    }
    
    /* Alerts */
    .alert { padding: 12px 16px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; }
    .alert-success { background: var(--success-bg); color: var(--success-text); border: 1px solid var(--success-border); }
    .alert-danger { background: var(--danger-bg); color: var(--danger-text); border: 1px solid var(--danger-border); }

    @media (max-width: 600px) {
        .hero { padding: 24px; }
        .card-header { flex-direction: column; align-items: flex-start; gap: 12px; }
        .order-meta { width: 100%; justify-content: space-between; }
        .status-badge { width: 100%; justify-content: center; }
        .card-footer { flex-direction: column-reverse; align-items: stretch; }
        .total-price { align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 16px; width: 100%; }
        .btn, .btn-outline { width: 100%; }
    }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'orders'); ?>
  
  <div class="page">
    <section class="hero">
      <div>
        <div class="pill">📦 Istorija</div>
        <h1>Mano užsakymai</h1>
        <p>Sekite užsakymų būseną, valdykite turgelio pirkinius ir peržiūrėkite istoriją.</p>
      </div>
    </section>

    <div class="layout">
      <div>
          <?= $message ?>
          
          <div class="tabs">
              <a href="?tab=shop" class="tab-btn <?= $activeTab === 'shop' ? 'active' : '' ?>">Parduotuvė (<?= count($shopOrders) ?>)</a>
              <a href="?tab=community" class="tab-btn <?= $activeTab === 'community' ? 'active' : '' ?>">Turgelis (<?= count($communityOrders) ?>)</a>
          </div>

          <?php if ($activeTab === 'shop'): ?>
              <?php if (empty($shopOrders)): ?>
                <div class="empty-state">
                  <div style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;">🛒</div>
                  <h3 style="margin: 0 0 8px; font-size: 18px;">Parduotuvėje dar nieko nepirkote</h3>
                  <p style="color: var(--text-muted); margin: 0 0 24px; font-size: 15px;">Atraskite mūsų oficialų asortimentą.</p>
                  <a class="btn" href="/products.php">Eiti į parduotuvę</a>
                </div>
              <?php else: ?>
                <div class="order-list">
                  <?php foreach ($shopOrders as $order): ?>
                    <?php 
                      $shopItemStmt->execute([$order['id']]); 
                      $orderItems = $shopItemStmt->fetchAll(); 
                      $statusLower = mb_strtolower($order['status']);
                      $statusClass = 'status-default';
                      
                      if (strpos($statusLower, 'paid') !== false) $statusClass = 'status-paid';
                      elseif (strpos($statusLower, 'pending') !== false) $statusClass = 'status-pending';
                      elseif (strpos($statusLower, 'cancelled') !== false) $statusClass = 'status-cancelled';
                    ?>
                    <div class="card">
                      <div class="card-header">
                        <div class="order-meta">
                            <div class="meta-group">
                                <span class="meta-label">Užsakymas</span>
                                <span class="meta-value">#<?= (int)$order['id']; ?></span>
                            </div>
                            <div class="meta-group">
                                <span class="meta-label">Data</span>
                                <span class="meta-value date"><?= htmlspecialchars(date('Y-m-d', strtotime($order['created_at']))); ?></span>
                            </div>
                        </div>
                        <div class="<?= $statusClass; ?> status-badge">
                           <?= htmlspecialchars($order['status']); ?>
                        </div>
                      </div>

                      <div class="card-body">
                          <div class="item-list">
                            <?php foreach ($orderItems as $item): ?>
                              <?php $itemUrl = '/produktas/' . slugify($item['title']) . '-' . (int)$item['product_id']; ?>
                              <div class="item">
                                <a href="<?= htmlspecialchars($itemUrl); ?>">
                                  <img src="<?= htmlspecialchars($item['image_url'] ?? '/uploads/default.png'); ?>" alt="<?= htmlspecialchars($item['title']); ?>">
                                </a>
                                <div class="item-details">
                                  <a href="<?= htmlspecialchars($itemUrl); ?>" class="item-title"><?= htmlspecialchars($item['title']); ?></a>
                                  <div class="item-meta"><?= (int)$item['quantity']; ?> vnt. × <?= number_format((float)$item['price'], 2); ?> €</div>
                                </div>
                                <div class="item-price"><?= number_format((float)$item['price'] * (int)$item['quantity'], 2); ?> €</div>
                              </div>
                            <?php endforeach; ?>
                          </div>

                          <div class="card-footer">
                              <div style="flex-grow:1;">
                                 <a class="btn-outline" href="/products.php">Pirkti vėl</a>
                              </div>
                              <div class="total-price">
                                  <span class="total-label">Viso mokėti</span>
                                  <span class="total-value"><?= number_format((float)$order['total_price'] / 100, 2); ?> €</span>
                              </div>
                          </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
          
          <?php elseif ($activeTab === 'community'): ?>
              <?php if (empty($communityOrders)): ?>
                <div class="empty-state">
                  <div style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;">🤝</div>
                  <h3 style="margin: 0 0 8px; font-size: 18px;">Turgelyje dar nieko nepirkote</h3>
                  <p style="color: var(--text-muted); margin: 0 0 24px; font-size: 15px;">Palaikykite bendruomenės narius pirkdami iš jų.</p>
                  <a class="btn" href="/community_market.php">Naršyti turgelį</a>
                </div>
              <?php else: ?>
                <div class="order-list">
                  <?php foreach ($communityOrders as $order): ?>
                    <?php 
                      $items = json_decode($order['items_json'], true);
                      $statusClass = 'status-default';
                      $statusText = '';
                      
                      switch($order['status']) {
                          case 'paid': $statusClass = 'status-pending'; $statusText = 'Laukiama išsiuntimo'; break;
                          case 'shipped': $statusClass = 'status-shipped'; $statusText = 'Išsiųsta (Laukiama gavimo)'; break;
                          case 'delivered': $statusClass = 'status-delivered'; $statusText = 'Gauta / Užbaigta'; break;
                          case 'disputed': $statusClass = 'status-disputed'; $statusText = 'Ginčas'; break;
                          default: $statusText = $order['status'];
                      }
                    ?>
                    <div class="card">
                      <div class="card-header">
                        <div class="order-meta">
                            <div class="meta-group">
                                <span class="meta-label">Užsakymas</span>
                                <span class="meta-value">#C-<?= (int)$order['id']; ?></span>
                            </div>
                            <div class="meta-group">
                                <span class="meta-label">Pardavėjas</span>
                                <span class="meta-value date" style="color: var(--accent);"><?= htmlspecialchars($order['seller_name']); ?></span>
                            </div>
                        </div>
                        <div class="<?= $statusClass; ?> status-badge">
                           <?= htmlspecialchars($statusText); ?>
                        </div>
                      </div>

                      <div class="card-body">
                          <?php if (!empty($order['tracking_number'])): ?>
                              <div class="delivery-info">
                                  <div style="color: var(--accent);">🚚</div>
                                  <div>
                                      <strong>Siuntos sekimas:</strong><br>
                                      <?= htmlspecialchars($order['tracking_number']); ?>
                                  </div>
                              </div>
                          <?php endif; ?>

                          <div class="item-list">
                            <?php if($items): foreach ($items as $item): ?>
                              <div class="item">
                                <div class="item-details">
                                  <span class="item-title"><?= htmlspecialchars($item['title']); ?></span>
                                  <div class="item-meta"><?= (int)$item['qty']; ?> vnt.</div>
                                </div>
                                <div class="item-price"><?= number_format(($item['price'] * $item['qty']) / 100, 2); ?> €</div>
                              </div>
                            <?php endforeach; endif; ?>
                          </div>

                          <div class="card-footer">
                              <div style="flex-grow:1;">
                                 <?php if ($order['status'] === 'shipped'): ?>
                                    <form method="POST" onsubmit="return confirm('Ar tikrai gavote prekę? Tai leis išmokėti pinigus pardavėjui.');">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="confirm_delivery">
                                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                        <button type="submit" class="btn btn-green">
                                            Gavau prekę
                                        </button>
                                    </form>
                                 <?php elseif ($order['status'] === 'delivered'): ?>
                                    <span style="color: var(--success-text); font-weight: 600;">✓ Užsakymas sėkmingai užbaigtas</span>
                                 <?php else: ?>
                                    <span style="font-size: 13px; color: var(--text-muted);">Veiksmų nėra</span>
                                 <?php endif; ?>
                              </div>
                              
                              <div class="total-price">
                                  <span class="total-label">Viso mokėti</span>
                                  <span class="total-value"><?= number_format((float)$order['total_paid'] / 100, 2); ?> €</span>
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
          <div class="card sidebar-card">
              <h3>Vartotojo meniu</h3>
              <nav class="sidebar-menu">
                  <a href="/account.php">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                      Profilio nustatymai
                  </a>
                  <a href="/orders.php" class="active">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="2" width="12" height="20" rx="2"></rect><line x1="6" y1="12" x2="18" y2="12"></line></svg>
                      Mano užsakymai
                  </a>
                  <a href="/sales.php">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                      Mano pardavimai
                  </a>
                  <a href="/saved.php">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
                      Išsaugoti produktai
                  </a>
                  <a href="/messages.php">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                      Žinutės
                  </a>
                  <a href="/login.php?logout=1" style="color:#ef4444;">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                      Atsijungti
                  </a>
              </nav>
          </div>
          
          <div class="card sidebar-card" style="margin-top:20px; background: #f8fafc; border: 1px dashed var(--border);">
              <h3>Pagalba</h3>
              <p style="font-size:13px; color:var(--text-muted); line-height:1.5; margin-bottom:12px;">Kilo klausimų dėl užsakymo? Susisiekite su mumis.</p>
              <a href="mailto:info@cukrinukas.lt" style="font-size:13px; font-weight:600; color:var(--accent);">info@cukrinukas.lt</a>
          </div>
      </aside>
    </div>
  </div>

  <?php renderFooter($pdo); ?>
  
  <?php echo $newPurchaseScript; ?>
</body>
</html>
