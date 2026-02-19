<?php
// sales.php - Pardavėjo užsakymai (Turgelis)
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mailer.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$pdo = getPdo();
tryAutoLogin($pdo);

$userId = (int) $_SESSION['user_id'];
$message = "";

// -----------------------------------------------------------------------------
// 1. VEIKSMAI: Pardavėjas pažymi kaip išsiųstą
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_shipped') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = '<div class="alert alert-danger">Saugumo klaida.</div>';
    } else {
        $orderId = (int)$_POST['order_id'];
        $trackingNumber = trim($_POST['tracking_number']);

        if (empty($trackingNumber)) {
            $message = '<div class="alert alert-danger">Būtina įvesti sekimo numerį!</div>';
        } else {
            // Tikriname, ar užsakymas priklauso šiam pardavėjui
            $stmt = $pdo->prepare("SELECT id, status, buyer_id FROM community_orders WHERE id = ? AND seller_id = ? AND status = 'apmokėta'");
            $stmt->execute([$orderId, $userId]);
            $order = $stmt->fetch();

            if ($order) {
                // Atnaujiname statusą
                $stmt = $pdo->prepare("UPDATE community_orders SET status = 'shipped', tracking_number = ?, shipped_at = NOW() WHERE id = ?");
                $stmt->execute([$trackingNumber, $orderId]);
                
                $message = '<div class="alert alert-success">Užsakymas pažymėtas kaip išsiųstas!</div>';

                // Siunčiame laišką pirkėjui
                $buyerStmt = $pdo->prepare("SELECT email, name FROM users WHERE id = ?");
                $buyerStmt->execute([$order['buyer_id']]);
                $buyer = $buyerStmt->fetch();
                
                if ($buyer) {
                    $subject = "Jūsų turgelio prekė išsiųsta!";
                    $body = "Sveiki, {$buyer['name']},<br><br>";
                    $body .= "Pardavėjas išsiuntė jūsų užsakymą #C-{$orderId}.<br>";
                    $body .= "Sekimo numeris: <strong>{$trackingNumber}</strong><br><br>";
                    $body .= "Kai gausite prekę, prašome prisijungti prie paskyros ir patvirtinti gavimą.";
                    sendEmail($buyer['email'], $subject, $body);
                }
            } else {
                $message = '<div class="alert alert-warning">Veiksmas negalimas.</div>';
            }
        }
    }
}

// -----------------------------------------------------------------------------
// 2. DUOMENŲ GAVIMAS
// -----------------------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT co.*, cl.title, cl.image_url, u.name as buyer_name, u.email as buyer_email, u.city as buyer_city
    FROM community_orders co
    LEFT JOIN community_listings cl ON co.item_id = cl.id
    LEFT JOIN users u ON co.buyer_id = u.id
    WHERE co.seller_id = ? 
    ORDER BY co.created_at DESC
");
$stmt->execute([$userId]);
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mano pardavimai | Cukrinukas.lt</title>
  <?php echo headerStyles(); ?>
  <style>
    /* Išsaugomas identiškas stilius kaip orders.php */
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

    .hero { 
        background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); /* Greenish for sales */
        border:1px solid #bbf7d0; border-radius:24px; padding:32px; 
        display:flex; align-items:center; justify-content:space-between; gap:24px; flex-wrap:wrap; 
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }
    .hero h1 { margin:0 0 8px; font-size:28px; color:#166534; letter-spacing:-0.5px; }
    .hero p { margin:0; color:#15803d; line-height:1.5; max-width:520px; font-size:15px; }
    .pill { 
        display:inline-flex; align-items:center; gap:8px; padding:6px 12px; border-radius:999px; 
        background:#fff; border:1px solid #86efac; font-weight:600; font-size:13px; color:#166534; margin-bottom: 12px;
    }

    .layout { display:grid; grid-template-columns: 1fr 320px; gap:24px; align-items:start; }
    @media(max-width: 900px){ .layout { grid-template-columns:1fr; } }

    .section-header { display:flex; align-items:center; justify-content:space-between; margin-bottom: 16px; }
    .section-header h2 { margin:0; font-size:20px; color: var(--text-main); font-weight: 700; }
    .section-header span { font-size: 13px; color: var(--text-muted); font-weight: 500; background: #e2e8f0; padding: 2px 8px; border-radius: 12px; }

    .order-list { display:flex; flex-direction: column; gap:20px; }
    .card { background:var(--card); border:1px solid var(--border); border-radius:20px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
    
    .card-header { padding: 16px 24px; background: #f8fafc; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
    .order-meta { display: flex; gap: 24px; align-items: center; }
    .meta-group { display: flex; flex-direction: column; gap: 2px; }
    .meta-label { font-size: 10px; text-transform: uppercase; font-weight: 700; color: var(--text-muted); letter-spacing: 0.5px; }
    .meta-value { font-size: 14px; font-weight: 600; color: var(--text-main); font-family: 'Roboto Mono', monospace; }
    
    .status-badge { padding:5px 12px; border-radius:6px; font-size:12px; font-weight:600; text-transform: uppercase; letter-spacing: 0.5px; }
    .st-paid { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; } /* Reikia siųsti - raudona */
    .st-shipped { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d; } /* Išsiųsta - geltona */
    .st-delivered { background: #ecfdf5; color: #065f46; border: 1px solid #6ee7b7; } /* Atlikta - žalia */

    .card-body { padding: 24px; }
    .delivery-info { font-size: 13px; color: var(--text-muted); margin-bottom: 20px; display: flex; align-items: flex-start; gap: 10px; background: #f8fafc; padding: 12px; border-radius: 12px; border: 1px dashed var(--border); }

    .item-list { display:grid; gap:16px; margin-bottom: 24px; }
    .item { display:flex; gap:16px; align-items:center; }
    .item img { width:64px; height:64px; object-fit:cover; border-radius:12px; border:1px solid var(--border); background: #fff; padding: 4px; flex-shrink: 0; }
    .item-details { flex:1; min-width: 0; }
    .item-title { font-weight:600; font-size:15px; color:var(--text-main); margin-bottom: 2px; }
    .item-meta { font-size:13px; color: var(--text-main); font-weight: 500; }
    .item-price { font-weight:700; font-size:15px; color:var(--text-main); text-align: right; white-space: nowrap; }

    .card-footer { padding-top: 20px; border-top: 1px solid var(--border); display: block; }
    .total-price { display: flex; flex-direction: column; align-items: flex-end; }
    .total-label { font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; }
    .total-value { font-size: 18px; font-weight: 700; color: var(--accent); }

    .btn { padding:10px 20px; border-radius:10px; font-weight:600; font-size:14px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; transition: all .2s; border:none; background: #0f172a; color:#fff; }
    .btn:hover { background: #1e293b; }
    
    .sidebar-card { padding: 24px; background:var(--card); border:1px solid var(--border); border-radius:20px; margin-bottom: 20px; }
    .sidebar-card h3 { margin:0 0 16px; font-size:16px; font-weight: 700; }
    .sidebar-menu { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:8px; }
    .sidebar-menu a { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:10px; color: var(--text-muted); font-size:14px; font-weight:500; transition: all .2s; }
    .sidebar-menu a:hover { background: #f8fafc; color: var(--text-main); }
    .sidebar-menu a.active { background: #eff6ff; color: var(--accent); font-weight: 600; }

    .alert { padding: 12px 16px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; }
    .alert-success { background: var(--success-bg); color: var(--success-text); border: 1px solid var(--success-border); }
    .alert-danger { background: var(--danger-bg); color: var(--danger-text); border: 1px solid var(--danger-border); }
    .empty-state { text-align: center; padding: 64px 20px; background: #fff; border-radius: 20px; border: 1px dashed var(--border); }
    .input-group { display: flex; gap: 8px; }
    .form-control { flex: 1; padding: 10px; border-radius: 8px; border: 1px solid var(--border); }

    @media (max-width: 600px) { .card-header { flex-direction: column; align-items: flex-start; } .input-group { flex-direction:column; } .btn { width: 100%; } }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'sales'); ?>
  
  <div class="page">
    <section class="hero">
      <div>
        <div class="pill">📈 Prekyba</div>
        <h1>Mano pardavimai</h1>
        <p>Čia matote visas parduotas prekes. Nepamirškite įvesti siuntos numerio, kai išsiųsite prekę.</p>
      </div>
      <div>
          <a href="/community_listing_new.php" class="btn" style="background:#fff; color:#166534; border:1px solid #bbf7d0;">+ Naujas skelbimas</a>
      </div>
    </section>

    <div class="layout">
      <div>
          <?= $message ?>
          
          <div class="section-header">
             <h2>Pardavimų istorija</h2>
             <span><?= count($sales); ?></span>
          </div>

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
                    $isPending = ($sale['status'] === 'apmokėta');
                    $statusText = '';
                    $statusClass = '';
                    
                    switch($sale['status']) {
                        case 'apmokėta': $statusText = 'REIKIA IŠSIŲSTI'; $statusClass='st-paid'; break;
                        case 'shipped': $statusText = 'IŠSIŲSTA (Laukiama)'; $statusClass='st-shipped'; break;
                        case 'delivered': $statusText = 'UŽBAIGTA'; $statusClass='st-delivered'; break;
                        default: $statusText = $sale['status']; $statusClass='st-pending';
                    }
                ?>
                <div class="card">
                  <div class="card-header">
                    <div class="order-meta">
                        <div class="meta-group"><span class="meta-label">Užsakymas</span><span class="meta-value">#C-<?= (int)$sale['id']; ?></span></div>
                        <div class="meta-group"><span class="meta-label">Data</span><span class="meta-value date"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($sale['created_at']))); ?></span></div>
                    </div>
                    <div class="status-badge <?= $statusClass; ?>"><?= htmlspecialchars($statusText); ?></div>
                  </div>

                  <div class="card-body">
                      <div class="delivery-info">
                          <span style="margin-right:8px; color:var(--accent);">👤</span>
                          <div>
                              <strong>Pirkėjas: <?= htmlspecialchars($sale['buyer_name']); ?></strong><br>
                              Miestas: <?= htmlspecialchars($sale['buyer_city'] ?? '-'); ?><br>
                              El. paštas: <?= htmlspecialchars($sale['buyer_email']); ?><br>
                              <small class="text-muted">(Pilnas adresas išsiųstas jums el. paštu)</small>
                          </div>
                      </div>

                      <div class="item-list">
                          <div class="item">
                            <img src="<?= htmlspecialchars($sale['image_url'] ?? '/uploads/default.png'); ?>" alt="">
                            <div class="item-details">
                              <span class="item-title"><?= htmlspecialchars($sale['title']); ?></span>
                              <div class="item-meta">1 vnt.</div>
                            </div>
                            <div class="item-price"><?= number_format((float)$sale['total_amount'], 2); ?> €</div>
                          </div>
                      </div>

                      <div class="card-footer">
                          <div style="width: 100%;">
                            <?php if ($isPending): ?>
                                <form method="POST" style="margin-bottom:12px;">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="action" value="mark_shipped">
                                    <input type="hidden" name="order_id" value="<?= $sale['id'] ?>">
                                    
                                    <label class="meta-label" style="display:block; margin-bottom:6px;">Įveskite siuntos sekimo numerį:</label>
                                    <div class="input-group">
                                        <input type="text" name="tracking_number" class="form-control" placeholder="pvz. LP123456789LT" required>
                                        <button class="btn" type="submit">Patvirtinti</button>
                                    </div>
                                </form>
                            <?php elseif (!empty($sale['tracking_number'])): ?>
                                <div style="font-size: 14px;"><strong>Sekimo nr.:</strong> <?= htmlspecialchars($sale['tracking_number']); ?></div>
                            <?php endif; ?>
                            
                            <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-top:12px; padding-top:12px; border-top:1px dashed var(--border);">
                                <div style="font-size:12px; color:var(--text-muted);">
                                    <?php if ($sale['payout_status'] == 'hold'): ?>
                                        <span style="color:#d97706;">⏳ Pinigai įšaldyti (Escrow)</span>
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
      </div>

      <aside>
          <div class="card sidebar-card">
              <h3>Vartotojo meniu</h3>
              <nav class="sidebar-menu">
                  <a href="/account.php">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                      Profilio nustatymai
                  </a>
                  <a href="/orders.php">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="2" width="12" height="20" rx="2"></rect><line x1="6" y1="12" x2="18" y2="12"></line></svg>
                      Mano užsakymai
                  </a>
                  <a href="/sales.php" class="active">
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
              <p style="font-size:13px; color:var(--text-muted); line-height:1.5; margin-bottom:12px;">Kilo problemų dėl pardavimo? Susisiekite su mumis.</p>
              <a href="mailto:info@cukrinukas.lt" style="font-size:13px; font-weight:600; color:var(--accent);">info@cukrinukas.lt</a>
          </div>
      </aside>
    </div>
  </div>

  <?php renderFooter($pdo); ?>
</body>
</html>
