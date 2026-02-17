<?php
// admin/community.php

require_once __DIR__ . '/../db.php';
// Įkeliame Stripe biblioteką veiksmams (Refund)
if (file_exists(__DIR__ . '/../lib/stripe/init.php')) {
    require_once __DIR__ . '/../lib/stripe/init.php';
}

$pdo = getPdo();
$message = '';
$error = '';

// --------------------------------------------------------
// 1. VEIKSMŲ APDOROJIMAS (POST)
// --------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- 1.1 KOMISINIO MOKESČIO IŠSAUGOJIMAS ---
    if ($action === 'save_commission') {
        $rate = (float)($_POST['commission_rate'] ?? 0);
        // Užtikriname, kad lentelė egzistuoja ir įrašome
        try {
            // Patikriname ar settings lentelė yra, jei ne - sukuriame (pagal jūsų schemą)
            $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
                id int(11) NOT NULL AUTO_INCREMENT,
                setting_key varchar(50) NOT NULL,
                setting_value varchar(255) NOT NULL,
                updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (id),
                UNIQUE KEY unique_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('market_commission_rate', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([$rate]);
            $message = "Komisinis mokestis atnaujintas į {$rate}%";
        } catch (PDOException $e) {
            $error = "Klaida saugant nustatymus: " . $e->getMessage();
        }
    }

    // --- 1.2 GRĄŽINIMAS (REFUND) ---
    elseif ($action === 'refund_order') {
        $orderId = (int)$_POST['order_id'];
        
        // Gauname užsakymo info ir Stripe Payment Intent ID
        $stmt = $pdo->prepare("SELECT stripe_payment_intent_id, status, total_price FROM community_orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if ($order && $order['status'] !== 'refunded') {
            try {
                // Jei yra Stripe ID, darome refund per Stripe
                if (!empty($order['stripe_payment_intent_id']) && function_exists('requireEnv')) {
                    $stripeKey = requireEnv('STRIPE_SECRET_KEY');
                    if ($stripeKey) {
                        $stripe = new \Stripe\StripeClient($stripeKey);
                        $stripe->refunds->create(['payment_intent' => $order['stripe_payment_intent_id']]);
                    }
                }

                // Atnaujiname statusą DB
                $pdo->prepare("UPDATE community_orders SET status = 'refunded' WHERE id = ?")->execute([$orderId]);
                $message = "Užsakymas #{$orderId} sėkmingai atšauktas ir pinigai grąžinti.";
            } catch (Exception $e) {
                $error = "Nepavyko atlikti grąžinimo: " . $e->getMessage();
            }
        } else {
            $error = "Užsakymas nerastas arba jau grąžintas.";
        }
    }

    // --- 1.3 PRIVERSTINIS PERVEDIMAS (FORCE PAYOUT) ---
    elseif ($action === 'force_payout') {
        $orderId = (int)$_POST['order_id'];
        // Pakeičiame statusą į 'completed' (arba jūsų sistemos atitikmenį), kad pardavėjas gautų pinigus
        $pdo->prepare("UPDATE community_orders SET status = 'completed' WHERE id = ?")->execute([$orderId]);
        $message = "Užsakymas #{$orderId} pažymėtas kaip įvykdytas. Lėšos bus įskaitytos pardavėjui.";
    }

    // --- 1.4 KITI VEIKSMAI (Iš senojo failo) ---
    elseif ($action === 'delete_community_category') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM community_thread_categories WHERE id = ?")->execute([$id]);
        $message = "Kategorija ištrinta.";
    }
    elseif ($action === 'delete_listing_category') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM community_listing_categories WHERE id = ?")->execute([$id]);
        $message = "Kategorija ištrinta.";
    }
    elseif ($action === 'delete_community_thread') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM community_threads WHERE id = ?")->execute([$id]);
        $message = "Tema ištrinta.";
    }
    elseif ($action === 'update_listing_status') {
        $id = (int)$_POST['id'];
        $status = $_POST['status'];
        $pdo->prepare("UPDATE community_listings SET status = ? WHERE id = ?")->execute([$status, $id]);
        $message = "Skelbimo statusas atnaujintas.";
    }
    elseif ($action === 'delete_listing') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM community_listings WHERE id = ?")->execute([$id]);
        $message = "Skelbimas ištrintas.";
    }
    elseif ($action === 'block_user') {
        $userId = (int)$_POST['user_id'];
        $duration = $_POST['duration'];
        $reason = $_POST['reason'];
        
        $until = null;
        if($duration === '24h') $until = date('Y-m-d H:i:s', strtotime('+24 hours'));
        elseif($duration === '7d') $until = date('Y-m-d H:i:s', strtotime('+7 days'));
        elseif($duration === '30d') $until = date('Y-m-d H:i:s', strtotime('+30 days'));
        
        $stmt = $pdo->prepare("INSERT INTO community_blocks (user_id, reason, banned_until) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $reason, $until]);
        $message = "Vartotojas užblokuotas.";
    }
    elseif ($action === 'unblock_user') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM community_blocks WHERE id = ?")->execute([$id]);
        $message = "Vartotojas atblokuotas.";
    }
    elseif ($action === 'save_community_category') {
        $name = trim($_POST['name']);
        if (!empty($_POST['id'])) {
            $pdo->prepare("UPDATE community_thread_categories SET name = ? WHERE id = ?")->execute([$name, $_POST['id']]);
        } else {
            $pdo->prepare("INSERT INTO community_thread_categories (name) VALUES (?)")->execute([$name]);
        }
        $message = "Diskusijų kategorija išsaugota.";
    }
    elseif ($action === 'save_listing_category') {
        $name = trim($_POST['name']);
        if (!empty($_POST['id'])) {
            $pdo->prepare("UPDATE community_listing_categories SET name = ? WHERE id = ?")->execute([$name, $_POST['id']]);
        } else {
            $pdo->prepare("INSERT INTO community_listing_categories (name) VALUES (?)")->execute([$name]);
        }
        $message = "Skelbimų kategorija išsaugota.";
    }
}

// --------------------------------------------------------
// 2. DUOMENŲ SURINKIMAS
// --------------------------------------------------------

// --- NUSTATYMAI ---
// Bandome gauti nustatymus, jei lentelė dar nesukurta - grąžiname 0
try {
    $commissionRate = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'market_commission_rate'")->fetchColumn();
} catch (Exception $e) {
    $commissionRate = 0;
}
if (!$commissionRate) $commissionRate = 0;

// --- DISKUSIJOS ---
$threadCats = $pdo->query('SELECT * FROM community_thread_categories ORDER BY name ASC')->fetchAll();
$recentThreads = $pdo->query('
    SELECT t.*, u.name as author_name, u.email as author_email, c.name as cat_name 
    FROM community_threads t 
    LEFT JOIN users u ON t.user_id = u.id 
    LEFT JOIN community_thread_categories c ON t.category_id = c.id
    ORDER BY t.created_at DESC LIMIT 20
')->fetchAll();

// --- TURGELIS (Skelbimai) ---
$listingCats = $pdo->query('SELECT * FROM community_listing_categories ORDER BY name ASC')->fetchAll();
$listings = $pdo->query('
    SELECT l.*, u.name as seller_name, u.email as seller_email, c.name as cat_name 
    FROM community_listings l
    LEFT JOIN users u ON l.user_id = u.id
    LEFT JOIN community_listing_categories c ON l.category_id = c.id
    ORDER BY l.created_at DESC LIMIT 50
')->fetchAll();

// --- TURGELIS (Užsakymai - Išplėstinė užklausa) ---
// Pastaba: darome prielaidą, kad community_orders turi commission_amount stulpelį. 
// Jei ne, galima jį apskaičiuoti, bet čia imame iš DB.
$marketOrders = $pdo->query('
    SELECT o.*, 
           l.title as item_title, 
           b.name as buyer_name, b.email as buyer_email,
           s.name as seller_name, s.email as seller_email
    FROM community_orders o
    LEFT JOIN community_listings l ON o.listing_id = l.id
    LEFT JOIN users b ON o.buyer_id = b.id
    LEFT JOIN users s ON l.user_id = s.id
    ORDER BY o.created_at DESC LIMIT 50
')->fetchAll();

// --- MODERAVIMAS (BLOKAVIMAI) ---
$blocks = $pdo->query('
    SELECT b.*, u.name, u.email 
    FROM community_blocks b
    LEFT JOIN users u ON b.user_id = u.id
    WHERE b.banned_until > NOW() OR b.banned_until IS NULL
    ORDER BY b.created_at DESC
')->fetchAll();

require_once 'header.php';
?>

<style>
    /* Tab'ų navigacija */
    .tab-nav { display:flex; gap:0; border-bottom:1px solid #e1e3ef; margin-bottom:24px; }
    .tab-btn {
        padding: 12px 24px; background:transparent; border:none; border-bottom:2px solid transparent;
        font-weight:600; color:#6b6b7a; cursor:pointer; font-size:14px; transition:0.2s;
    }
    .tab-btn:hover { color:#4f46e5; background:#f9f9ff; }
    .tab-btn.active { color:#4f46e5; border-bottom-color:#4f46e5; }

    /* Turinys */
    .tab-content { display:none; animation: fadeIn 0.3s ease; }
    .tab-content.active { display:block; }
    @keyframes fadeIn { from { opacity:0; transform:translateY(5px); } to { opacity:1; transform:translateY(0); } }

    /* Kortelės tinklelis */
    .cat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; margin-bottom: 24px; }
    .cat-card { background: #fff; border: 1px solid #e1e3ef; border-radius: 12px; padding: 14px; position: relative; }
    .cat-title { font-weight: 700; margin-bottom: 8px; color:#1f2937; }
    
    /* Modal styles */
    .modal-overlay {
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.5); backdrop-filter: blur(2px); z-index: 1000;
        display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s;
    }
    .modal-overlay.open { display: flex; opacity: 1; }
    .modal-window {
        background: #fff; width: 100%; max-width: 450px;
        border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); padding: 24px;
    }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 12px; font-weight: 700; color: #6b6b7a; margin-bottom: 6px; text-transform: uppercase; }
    
    .status-pill { padding:2px 8px; border-radius:12px; font-size:11px; font-weight:700; border:1px solid transparent; display:inline-block; }
    .status-active, .status-completed { background:#ecfdf5; color:#065f46; border-color:#a7f3d0; }
    .status-sold, .status-paid { background:#f3f4f6; color:#374151; border-color:#e5e7eb; }
    .status-refunded { background:#fff1f1; color:#991b1b; border-color:#fecaca; }
    .status-pending { background:#fffbeb; color:#92400e; border-color:#fde68a; }
</style>

<div class="page">
    <?php if ($message): ?>
        <div class="alert success" style="margin-bottom:20px;"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert error" style="margin-bottom:20px;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <h2>Bendruomenės valdymas</h2>
        <button class="btn" style="background:#ef4444; border-color:#ef4444;" onclick="openBlockModal()">🚨 Blokuoti vartotoją</button>
    </div>

    <div class="tab-nav">
        <button class="tab-btn active" onclick="switchTab('discussions')">💬 Diskusijos</button>
        <button class="tab-btn" onclick="switchTab('market')">🛍️ Turgelis</button>
        <button class="tab-btn" onclick="switchTab('moderation')">🛡️ Blokavimai</button>
    </div>

    <div id="tab-discussions" class="tab-content active">
        
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h3 class="muted" style="margin:0; font-size:14px; text-transform:uppercase;">Kategorijos</h3>
            <button class="btn secondary" style="padding:6px 12px; font-size:12px;" onclick="openCatModal('thread')">+ Pridėti</button>
        </div>
        <div class="cat-grid">
            <?php foreach ($threadCats as $cat): ?>
                <div class="cat-card">
                    <div class="cat-title"><?php echo htmlspecialchars($cat['name']); ?></div>
                    <div style="display:flex; justify-content:flex-end; gap:6px;">
                        <button class="btn secondary" style="padding:4px 8px; font-size:11px;" onclick='openCatModal("thread", <?php echo json_encode($cat); ?>)'>Redaguoti</button>
                        <form method="post" onsubmit="return confirm('Trinti kategoriją?');" style="margin:0;">
                            <input type="hidden" name="action" value="delete_community_category">
                            <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                            <button class="btn" style="padding:4px 8px; font-size:11px; background:#fff1f1; color:#b91c1c; border-color:#fecaca;">&times;</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card" style="margin-bottom:24px;">
            <h3>Naujausios temos</h3>
            <table>
                <thead><tr><th>Tema</th><th>Kategorija</th><th>Autorius</th><th>Data</th><th>Veiksmai</th></tr></thead>
                <tbody>
                    <?php foreach ($recentThreads as $thread): ?>
                    <tr>
                        <td><a href="/community_thread.php?id=<?php echo $thread['id']; ?>" target="_blank" style="font-weight:600;"><?php echo htmlspecialchars($thread['title']); ?></a></td>
                        <td><span class="muted" style="font-size:12px;"><?php echo htmlspecialchars($thread['cat_name'] ?? '-'); ?></span></td>
                        <td>
                            <?php echo htmlspecialchars($thread['author_name']); ?>
                            <button type="button" style="border:none; background:none; cursor:pointer; font-size:12px;" onclick="openBlockModal(<?php echo $thread['user_id']; ?>, '<?php echo htmlspecialchars($thread['author_email']); ?>')">🚫</button>
                        </td>
                        <td><?php echo date('Y-m-d', strtotime($thread['created_at'])); ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Trinti temą?');">
                                <input type="hidden" name="action" value="delete_community_thread">
                                <input type="hidden" name="id" value="<?php echo $thread['id']; ?>">
                                <button class="btn" style="padding:4px 10px; font-size:11px; background:#f3f4f6; color:#111;">Ištrinti</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="tab-market" class="tab-content">
        
        <div class="card" style="margin-bottom:24px; background:#f0f9ff; border-color:#bae6fd;">
            <h3 style="color:#0369a1;">⚙️ Turgelio Nustatymai</h3>
            <form method="post" class="input-row" style="align-items:flex-end;">
                <input type="hidden" name="action" value="save_commission">
                <div style="flex:1; max-width:300px;">
                    <label style="font-size:12px; font-weight:700; color:#0369a1;">Sistemos komisinis mokestis (%)</label>
                    <input type="number" step="0.1" min="0" max="100" name="commission_rate" value="<?php echo htmlspecialchars($commissionRate); ?>" style="margin:0;">
                </div>
                <button class="btn" style="background:#0284c7; border-color:#0284c7;">Išsaugoti</button>
            </form>
            <div style="font-size:12px; color:#0369a1; margin-top:8px;">Šis procentas bus taikomas naujiems sandoriams.</div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h3 class="muted" style="margin:0; font-size:14px; text-transform:uppercase;">Skelbimų Kategorijos</h3>
            <button class="btn secondary" style="padding:6px 12px; font-size:12px;" onclick="openCatModal('listing')">+ Pridėti</button>
        </div>
        <div class="cat-grid">
            <?php foreach ($listingCats as $cat): ?>
                <div class="cat-card">
                    <div class="cat-title"><?php echo htmlspecialchars($cat['name']); ?></div>
                    <div style="display:flex; justify-content:flex-end; gap:6px;">
                        <button class="btn secondary" style="padding:4px 8px; font-size:11px;" onclick='openCatModal("listing", <?php echo json_encode($cat); ?>)'>Redaguoti</button>
                        <form method="post" onsubmit="return confirm('Trinti kategoriją?');" style="margin:0;">
                            <input type="hidden" name="action" value="delete_listing_category">
                            <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                            <button class="btn" style="padding:4px 8px; font-size:11px; background:#fff1f1; color:#b91c1c; border-color:#fecaca;">&times;</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card" style="margin-bottom:24px;">
            <h3>📦 Paskutiniai Sandoriai (Užsakymai)</h3>
            <div style="overflow-x:auto;">
                <table style="min-width:1000px;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Prekė</th>
                            <th>Pirkėjas</th>
                            <th>Pardavėjas</th>
                            <th>Suma</th>
                            <th>Komisinis</th>
                            <th>Statusas</th>
                            <th>Veiksmai</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($marketOrders as $ord): 
                            $commission = isset($ord['commission_amount']) ? $ord['commission_amount'] : 0;
                            // Jei komisinio nėra DB, galima bandyti skaičiuoti, bet rodome 0
                        ?>
                        <tr>
                            <td>#<?php echo $ord['id']; ?></td>
                            <td>
                                <?php echo htmlspecialchars($ord['item_title']); ?>
                                <div class="muted" style="font-size:10px;"><?php echo date('Y-m-d H:i', strtotime($ord['created_at'])); ?></div>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($ord['buyer_name']); ?></div>
                                <div class="muted" style="font-size:11px;"><?php echo htmlspecialchars($ord['buyer_email']); ?></div>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($ord['seller_name']); ?></div>
                                <div class="muted" style="font-size:11px;"><?php echo htmlspecialchars($ord['seller_email']); ?></div>
                            </td>
                            <td style="font-weight:bold;"><?php echo number_format($ord['total_price'] ?? 0, 2); ?> €</td>
                            <td style="color:#6b6b7a;"><?php echo number_format($commission, 2); ?> €</td>
                            <td>
                                <span class="status-pill status-<?php echo htmlspecialchars($ord['status']); ?>">
                                    <?php echo htmlspecialchars(ucfirst($ord['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <div style="display:flex; gap:4px; flex-wrap:wrap;">
                                    <?php if ($ord['status'] !== 'refunded'): ?>
                                        <form method="post" onsubmit="return confirm('AR TIKRAI norite atšaukti užsakymą ir grąžinti pinigus pirkėjui?');" style="margin:0;">
                                            <input type="hidden" name="action" value="refund_order">
                                            <input type="hidden" name="order_id" value="<?php echo $ord['id']; ?>">
                                            <button class="btn" style="padding:4px 8px; font-size:10px; background:#fff1f1; color:#991b1b; border-color:#fecaca;">Refund</button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <?php if ($ord['status'] !== 'completed' && $ord['status'] !== 'refunded'): ?>
                                        <form method="post" onsubmit="return confirm('AR TIKRAI norite priverstinai užbaigti užsakymą? Lėšos bus laikomos gautomis.');" style="margin:0;">
                                            <input type="hidden" name="action" value="force_payout">
                                            <input type="hidden" name="order_id" value="<?php echo $ord['id']; ?>">
                                            <button class="btn" style="padding:4px 8px; font-size:10px; background:#ecfdf5; color:#065f46; border-color:#a7f3d0;">Force Payout</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h3>Naujausi skelbimai</h3>
            <table>
                <thead><tr><th>Foto</th><th>Pavadinimas</th><th>Kaina</th><th>Pardavėjas</th><th>Statusas</th><th>Veiksmai</th></tr></thead>
                <tbody>
                    <?php foreach ($listings as $item): ?>
                    <tr>
                        <td>
                            <?php if($item['image_url']): ?>
                                <img src="<?php echo htmlspecialchars($item['image_url']); ?>" style="width:32px; height:32px; border-radius:4px; object-fit:cover;">
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="/community_listing.php?id=<?php echo $item['id']; ?>" target="_blank" style="font-weight:600;"><?php echo htmlspecialchars($item['title']); ?></a>
                            <div class="muted" style="font-size:11px;"><?php echo htmlspecialchars($item['cat_name'] ?? '-'); ?></div>
                        </td>
                        <td><?php echo number_format($item['price'], 2); ?> €</td>
                        <td>
                            <?php echo htmlspecialchars($item['seller_name']); ?>
                            <button type="button" style="border:none; background:none; cursor:pointer; font-size:12px;" onclick="openBlockModal(<?php echo $item['user_id']; ?>, '<?php echo htmlspecialchars($item['seller_email']); ?>')">🚫</button>
                        </td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="action" value="update_listing_status">
                                <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                <select name="status" onchange="this.form.submit()" style="margin:0; padding:4px; font-size:12px; border-radius:6px;">
                                    <option value="active" <?php echo $item['status'] === 'active' ? 'selected' : ''; ?>>Aktyvus</option>
                                    <option value="sold" <?php echo $item['status'] === 'sold' ? 'selected' : ''; ?>>Parduota</option>
                                </select>
                            </form>
                        </td>
                        <td>
                            <form method="post" onsubmit="return confirm('Trinti skelbimą?');">
                                <input type="hidden" name="action" value="delete_listing">
                                <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                <button class="btn" style="padding:4px 8px; font-size:11px; background:#fff1f1; color:#b91c1c; border-color:#fecaca;">&times;</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="tab-moderation" class="tab-content">
        <div class="card">
            <h3>Aktyvūs blokavimai</h3>
            <table>
                <thead><tr><th>Vartotojas</th><th>Priežastis</th><th>Iki kada</th><th>Veiksmai</th></tr></thead>
                <tbody>
                    <?php foreach ($blocks as $block): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($block['name']); ?></strong><br>
                            <span class="muted"><?php echo htmlspecialchars($block['email']); ?></span>
                        </td>
                        <td><?php echo htmlspecialchars($block['reason']); ?></td>
                        <td><?php echo $block['banned_until'] ? date('Y-m-d H:i', strtotime($block['banned_until'])) : 'Neribotai'; ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Atblokuoti?');">
                                <input type="hidden" name="action" value="unblock_user">
                                <input type="hidden" name="id" value="<?php echo $block['id']; ?>">
                                <button class="btn" style="padding:4px 10px; font-size:12px;">Atblokuoti</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(!$blocks): ?>
                        <tr><td colspan="4" class="muted">Aktyvių blokavimų nėra.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="catModal" class="modal-overlay">
    <div class="modal-window">
        <div style="display:flex; justify-content:space-between; margin-bottom:16px;">
            <h3 style="margin:0;" id="catModalTitle">Kategorija</h3>
            <button onclick="closeModal('catModal')" style="border:none; background:none; font-size:24px; cursor:pointer;">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" id="catFormAction" value="">
            <input type="hidden" name="id" id="catId" value="">
            
            <div class="form-group">
                <label>Pavadinimas</label>
                <input type="text" name="name" id="catName" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px;">
            </div>
            
            <button type="submit" class="btn" style="width:100%;">Išsaugoti</button>
        </form>
    </div>
</div>

<div id="blockModal" class="modal-overlay">
    <div class="modal-window">
        <div style="display:flex; justify-content:space-between; margin-bottom:16px;">
            <h3 style="margin:0;">Blokuoti vartotoją</h3>
            <button onclick="closeModal('blockModal')" style="border:none; background:none; font-size:24px; cursor:pointer;">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="block_user">
            
            <div class="form-group">
                <label>Vartotojo ID</label>
                <input type="number" name="user_id" id="blockUserId" required placeholder="Įveskite ID" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px;">
                <div class="muted" style="font-size:11px; margin-top:4px;" id="blockUserEmail"></div>
            </div>

            <div class="form-group">
                <label>Laikotarpis</label>
                <select name="duration" style="width:100%; padding:10px; border-radius:8px; border:1px solid #ddd;">
                    <option value="24h">24 Valandos</option>
                    <option value="7d">7 Dienos</option>
                    <option value="30d">30 Dienų</option>
                    <option value="permanent">Visam laikui</option>
                </select>
            </div>

            <div class="form-group">
                <label>Priežastis</label>
                <textarea name="reason" rows="3" required placeholder="Pvz. Įžeidinėjimai, reklama..." style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px;"></textarea>
            </div>
            
            <button type="submit" class="btn" style="width:100%; background:#ef4444; border-color:#ef4444;">Blokuoti</button>
        </form>
    </div>
</div>

<script>
    // Tab'ų logika
    function switchTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        
        document.getElementById('tab-' + tabId).classList.add('active');
        // Randame mygtuką pagal onClick
        const btns = document.querySelectorAll('.tab-btn');
        if(tabId === 'discussions') btns[0].classList.add('active');
        if(tabId === 'market') btns[1].classList.add('active');
        if(tabId === 'moderation') btns[2].classList.add('active');
        
        localStorage.setItem('admin_community_tab', tabId);
    }
    
    // Atstatome tabą po refresh
    const savedTab = localStorage.getItem('admin_community_tab');
    if(savedTab) { switchTab(savedTab); }

    // Modalų logika
    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
        setTimeout(() => document.getElementById(id).style.display = 'none', 200);
    }

    function openCatModal(type, data = null) {
        const modal = document.getElementById('catModal');
        const title = document.getElementById('catModalTitle');
        const formAction = document.getElementById('catFormAction');
        const idInput = document.getElementById('catId');
        const nameInput = document.getElementById('catName');

        modal.style.display = 'flex';
        setTimeout(() => modal.classList.add('open'), 10);

        if (type === 'thread') {
            formAction.value = 'save_community_category';
            title.innerText = data ? 'Redaguoti diskusijų kategoriją' : 'Nauja diskusijų kategorija';
        } else {
            formAction.value = 'save_listing_category';
            title.innerText = data ? 'Redaguoti skelbimų kategoriją' : 'Nauja skelbimų kategorija';
        }

        if (data) {
            idInput.value = data.id;
            nameInput.value = data.name;
        } else {
            idInput.value = '';
            nameInput.value = '';
        }
    }

    function openBlockModal(userId = null, userEmail = null) {
        const modal = document.getElementById('blockModal');
        modal.style.display = 'flex';
        setTimeout(() => modal.classList.add('open'), 10);
        
        if (userId) {
            document.getElementById('blockUserId').value = userId;
        } else {
            document.getElementById('blockUserId').value = '';
        }
        
        if (userEmail) {
            document.getElementById('blockUserEmail').innerText = 'Vartotojas: ' + userEmail;
        } else {
            document.getElementById('blockUserEmail').innerText = '';
        }
    }

    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) closeModal(this.id);
        });
    });
</script>
