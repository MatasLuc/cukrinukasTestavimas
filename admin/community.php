<?php
// admin/community.php

// --------------------------------------------------------
// 1. DUOMENŲ SURINKIMAS
// --------------------------------------------------------

// --- DISKUSIJOS ---
$threadCats = $pdo->query('SELECT * FROM community_thread_categories ORDER BY name ASC')->fetchAll();
$recentThreads = $pdo->query('
    SELECT t.*, u.name as author_name, u.email as author_email, c.name as cat_name 
    FROM community_threads t 
    LEFT JOIN users u ON t.user_id = u.id 
    LEFT JOIN community_thread_categories c ON t.category_id = c.id
    ORDER BY t.created_at DESC LIMIT 20
')->fetchAll();
$recentComments = $pdo->query('
    SELECT cm.*, u.name as author_name, t.title as thread_title
    FROM community_comments cm
    LEFT JOIN users u ON cm.user_id = u.id
    LEFT JOIN community_threads t ON cm.thread_id = t.id
    ORDER BY cm.created_at DESC LIMIT 20
')->fetchAll();

// --- TURGELIS ---
$listingCats = $pdo->query('SELECT * FROM community_listing_categories ORDER BY name ASC')->fetchAll();
$listings = $pdo->query('
    SELECT l.*, u.name as seller_name, u.email as seller_email, c.name as cat_name 
    FROM community_listings l
    LEFT JOIN users u ON l.user_id = u.id
    LEFT JOIN community_listing_categories c ON l.category_id = c.id
    ORDER BY l.created_at DESC LIMIT 50
')->fetchAll();
$marketOrders = $pdo->query('
    SELECT o.*, l.title as item_title, b.name as buyer_name, s.name as seller_name
    FROM community_orders o
    LEFT JOIN community_listings l ON o.listing_id = l.id
    LEFT JOIN users b ON o.buyer_id = b.id
    LEFT JOIN users s ON l.user_id = s.id
    ORDER BY o.created_at DESC LIMIT 30
')->fetchAll();

// --- MODERAVIMAS (BLOKAVIMAI) ---
$blocks = $pdo->query('
    SELECT b.*, u.name, u.email 
    FROM community_blocks b
    LEFT JOIN users u ON b.user_id = u.id
    WHERE b.banned_until > NOW() OR b.banned_until IS NULL
    ORDER BY b.created_at DESC
')->fetchAll();

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
    
    .status-active { background:#ecfdf5; color:#065f46; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:700; border:1px solid #a7f3d0; }
    .status-sold { background:#f3f4f6; color:#374151; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:700; border:1px solid #e5e7eb; }
</style>

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
                        <?php echo csrfField(); ?>
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
                            <?php echo csrfField(); ?>
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
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="delete_listing_category">
                        <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                        <button class="btn" style="padding:4px 8px; font-size:11px; background:#fff1f1; color:#b91c1c; border-color:#fecaca;">&times;</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card" style="margin-bottom:24px;">
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
                            <?php echo csrfField(); ?>
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
                            <?php echo csrfField(); ?>
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
    
    <div class="card">
        <h3>Paskutiniai sandoriai (Užsakymai)</h3>
        <table>
            <thead><tr><th>ID</th><th>Prekė</th><th>Pirkėjas</th><th>Pardavėjas</th><th>Statusas</th><th>Data</th></tr></thead>
            <tbody>
                <?php foreach ($marketOrders as $ord): ?>
                <tr>
                    <td>#<?php echo $ord['id']; ?></td>
                    <td><?php echo htmlspecialchars($ord['item_title']); ?></td>
                    <td><?php echo htmlspecialchars($ord['buyer_name']); ?></td>
                    <td><?php echo htmlspecialchars($ord['seller_name']); ?></td>
                    <td><span class="pill"><?php echo htmlspecialchars($ord['status']); ?></span></td>
                    <td><?php echo date('Y-m-d', strtotime($ord['created_at'])); ?></td>
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
                            <?php echo csrfField(); ?>
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


<div id="catModal" class="modal-overlay">
    <div class="modal-window">
        <div style="display:flex; justify-content:space-between; margin-bottom:16px;">
            <h3 style="margin:0;" id="catModalTitle">Kategorija</h3>
            <button onclick="closeModal('catModal')" style="border:none; background:none; font-size:24px; cursor:pointer;">&times;</button>
        </div>
        <form method="post">
            <?php echo csrfField(); ?>
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
            <?php echo csrfField(); ?>
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
        
        // Išsaugome pasirinkimą (nebūtina, bet patogu)
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

        // Nustatome tipą (Diskusijos vs Skelbimai)
        if (type === 'thread') {
            formAction.value = 'save_community_category';
            title.innerText = data ? 'Redaguoti diskusijų kategoriją' : 'Nauja diskusijų kategorija';
        } else {
            formAction.value = 'save_listing_category';
            title.innerText = data ? 'Redaguoti skelbimų kategoriją' : 'Nauja skelbimų kategorija';
        }

        // Užpildome duomenis
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

    // Uždaryti paspaudus fone
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) closeModal(this.id);
        });
    });
</script>
