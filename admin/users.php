<?php
// admin/users.php

// Apdorojame formų pateikimus
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Vartotojo atnaujinimas
    if ($_POST['action'] === 'update_user') {
        $user_id = (int)$_POST['user_id'];
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $is_admin = isset($_POST['is_admin']) ? (int)$_POST['is_admin'] : 0;
        $birthdate = !empty($_POST['birthdate']) ? $_POST['birthdate'] : null;
        $gender = !empty($_POST['gender']) ? trim($_POST['gender']) : null;
        $city = !empty($_POST['city']) ? trim($_POST['city']) : null;
        $country = !empty($_POST['country']) ? trim($_POST['country']) : null;

        $stmt = $pdo->prepare("
            UPDATE users 
            SET name = ?, email = ?, is_admin = ?, birthdate = ?, gender = ?, city = ?, country = ? 
            WHERE id = ?
        ");
        $stmt->execute([$name, $email, $is_admin, $birthdate, $gender, $city, $country, $user_id]);
        
        $_SESSION['flash_success'] = "Vartotojo informacija atnaujinta.";
        header("Location: ?view=users");
        exit;
    }

    // Vartotojo ištrynimas
    if ($_POST['action'] === 'delete_user') {
        $user_id = (int)$_POST['user_id'];
        // Pastaba: priklausomai nuo ryšių (ON DELETE CASCADE), gali tekti ištrinti susijusius duomenis atskirai.
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        
        $_SESSION['flash_success'] = "Vartotojas ištrintas.";
        header("Location: ?view=users");
        exit;
    }
}

// Paieška
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$params = [];
$sql = "SELECT * FROM users";

if ($search) {
    $sql .= " WHERE name LIKE ? OR email LIKE ?";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Iš anksto paruošiame statement'us, kad ciklas suktųsi greitai
$ordersStmt = $pdo->prepare("SELECT id, created_at, total, status FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
$blockStmt = $pdo->prepare("SELECT * FROM community_blocks WHERE user_id = ? AND (banned_until IS NULL OR banned_until > NOW()) ORDER BY created_at DESC LIMIT 1");
$listingsStmt = $pdo->prepare("SELECT id, title, price, status, created_at FROM community_listings WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
$ordersBoughtStmt = $pdo->prepare("SELECT id, item_id, total_amount, status, created_at FROM community_orders WHERE buyer_id = ? ORDER BY created_at DESC LIMIT 50");
$ordersSoldStmt = $pdo->prepare("SELECT id, item_id, total_amount, status, created_at FROM community_orders WHERE seller_id = ? ORDER BY created_at DESC LIMIT 50");
$reportsStmt = $pdo->prepare("SELECT id, listing_id, reason, status, created_at FROM community_reports WHERE reporter_id = ? ORDER BY created_at DESC LIMIT 50");
$threadsStmt = $pdo->prepare("SELECT id, title, created_at FROM community_threads WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
$ticketsStmt = $pdo->prepare("SELECT id, type, status, created_at FROM community_tickets WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");

foreach ($users as &$u) {
    $uid = $u['id'];

    // Parduotuvės užsakymai
    try {
        $ordersStmt->execute([$uid]);
        $u['recent_orders'] = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $u['recent_orders'] = [];
    }

    // Bendruomenės užblokavimai
    $blockStmt->execute([$uid]);
    $u['active_block'] = $blockStmt->fetch(PDO::FETCH_ASSOC);

    // Skelbimai
    $listingsStmt->execute([$uid]);
    $u['listings'] = $listingsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Bendruomenės užsakymai (Nupirkti)
    $ordersBoughtStmt->execute([$uid]);
    $u['orders_bought'] = $ordersBoughtStmt->fetchAll(PDO::FETCH_ASSOC);

    // Bendruomenės užsakymai (Parduoti)
    $ordersSoldStmt->execute([$uid]);
    $u['orders_sold'] = $ordersSoldStmt->fetchAll(PDO::FETCH_ASSOC);

    // Pranešimai (Reports)
    $reportsStmt->execute([$uid]);
    $u['reports'] = $reportsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Temos (Threads)
    $threadsStmt->execute([$uid]);
    $u['threads'] = $threadsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Pagalbos bilietai (Tickets)
    $ticketsStmt->execute([$uid]);
    $u['tickets'] = $ticketsStmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($u);
?>

<style>
    /* Statuso ženkleliai */
    .role-badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        display: inline-block;
    }
    .role-admin { background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe; }
    .role-user { background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }

    /* Modal */
    .modal-overlay {
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.5);
        backdrop-filter: blur(4px);
        z-index: 1000;
        display: none; align-items: center; justify-content: center;
        padding: 20px; opacity: 0; transition: opacity 0.2s ease;
    }
    .modal-overlay.open { display: flex; opacity: 1; }
    
    .modal-window {
        background: #fff; width: 100%; max-width: 800px;
        border-radius: 16px; box-shadow: 0 20px 50px rgba(0,0,0,0.2);
        overflow-y: auto; max-height: 90vh; display: flex; flex-direction: column;
    }
    
    .modal-header {
        padding: 20px 24px; border-bottom: 1px solid #eee; display: flex;
        justify-content: space-between; align-items: center; background: #fcfcfc;
    }
    .modal-title { font-size: 18px; font-weight: 700; margin: 0; }
    .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #888; }
    
    .modal-body { padding: 24px; }

    /* Tabs */
    .tabs-nav {
        display: flex; gap: 10px; border-bottom: 1px solid #ddd; margin-bottom: 20px; overflow-x: auto;
    }
    .tab-btn {
        background: none; border: none; padding: 10px 15px; cursor: pointer; font-size: 14px;
        font-weight: 600; color: #666; border-bottom: 2px solid transparent; white-space: nowrap;
    }
    .tab-btn:hover { color: #111; }
    .tab-btn.active { color: #111; border-bottom-color: #3730a3; }
    .tab-content { display: none; }
    .tab-content.active { display: block; animation: fadeIn 0.3s; }

    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

    /* Lentelės viduje modalų */
    .data-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 15px; }
    .data-table th, .data-table td { padding: 10px 8px; border-bottom: 1px solid #eee; text-align: left; }
    .data-table th { font-weight: 600; color: #555; background: #f9fafb; }
    .data-table tr:hover { background: #fcfcfc; }

    .status-dot { height: 8px; width: 8px; background-color: #ccc; border-radius: 50%; display: inline-block; margin-right: 5px; }
    .status-dot.completed { background-color: #10b981; }
    .status-dot.pending { background-color: #f59e0b; }
    .status-dot.cancelled { background-color: #ef4444; }

    /* Forma modale */
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; font-size: 13px; font-weight: 600; color: #555; }
    .form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
    .row-split { display: flex; gap: 15px; }
    .row-split > div { flex: 1; }

    .muted-text { color: #999; font-size: 13px; font-style: italic; }
</style>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
        <div>
            <h3>Registruoti vartotojai</h3>
            <span class="muted" style="font-size:13px;">Viso: <?php echo count($users); ?></span>
        </div>
        
        <form method="get" style="display:flex; gap:8px;">
            <input type="hidden" name="view" value="users">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Vardas arba el. paštas" class="form-control" style="padding:6px 12px; font-size:13px; width:200px;">
            <button type="submit" class="btn secondary" style="padding:6px 12px; font-size:13px;">Ieškoti</button>
            <?php if($search): ?>
                <a href="?view=users" class="btn secondary" style="color:red; padding:6px 10px; font-size:13px;">&times;</a>
            <?php endif; ?>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Vartotojas</th>
                <th>El. paštas</th>
                <th>Rolė</th>
                <th>Registracija</th>
                <th style="text-align:right;">Veiksmai</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td>#<?php echo (int)$u['id']; ?></td>
                    <td>
                        <div style="font-weight:600;"><?php echo htmlspecialchars($u['name']); ?></div>
                    </td>
                    <td class="muted"><?php echo htmlspecialchars($u['email']); ?></td>
                    <td>
                        <?php if (!empty($u['is_admin'])): ?>
                            <span class="role-badge role-admin">Administratorius</span>
                        <?php else: ?>
                            <span class="role-badge role-user">Vartotojas</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('Y-m-d', strtotime($u['created_at'])); ?></td>
                    <td style="text-align:right;">
                        <button class="btn secondary open-user-modal" 
                                type="button" 
                                data-user='<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8'); ?>'>
                            Detali informacija
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if(empty($users)): ?>
                <tr><td colspan="6" style="text-align:center; padding:20px; color:#999;">Vartotojų nerasta.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="userModal" class="modal-overlay">
    <div class="modal-window">
        <div class="modal-header">
            <h3 class="modal-title">Vartotojas #<span id="u_id"></span></h3>
            <button type="button" class="modal-close" onclick="closeUserModal()">&times;</button>
        </div>
        
        <div class="modal-body" style="padding-top: 10px;">
            
            <div class="tabs-nav">
                <button type="button" class="tab-btn active" data-target="tab-info">Profilis</button>
                <button type="button" class="tab-btn" data-target="tab-listings">Skelbimai</button>
                <button type="button" class="tab-btn" data-target="tab-comm-orders">Bendruomenės Užsakymai</button>
                <button type="button" class="tab-btn" data-target="tab-store-orders">Parduotuvė</button>
                <button type="button" class="tab-btn" data-target="tab-reports">Pranešimai</button>
                <button type="button" class="tab-btn" data-target="tab-threads">Temos</button>
                <button type="button" class="tab-btn" data-target="tab-tickets">Pagalba</button>
            </div>

            <div id="tab-info" class="tab-content active">
                <div id="u_block_status" style="margin-bottom: 20px;"></div>

                <form method="post">
                    <?php echo function_exists('csrfField') ? csrfField() : ''; ?>
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="user_id" id="u_formId">
                    
                    <div class="row-split">
                        <div class="form-group">
                            <label>Vardas</label>
                            <input type="text" name="name" id="u_formName" required>
                        </div>
                        <div class="form-group">
                            <label>El. paštas</label>
                            <input type="email" name="email" id="u_formEmail" required>
                        </div>
                    </div>

                    <div class="row-split">
                        <div class="form-group">
                            <label>Gimimo data</label>
                            <input type="date" name="birthdate" id="u_formBirthdate">
                        </div>
                        <div class="form-group">
                            <label>Lytis</label>
                            <select name="gender" id="u_formGender">
                                <option value="">-</option>
                                <option value="male">Vyras</option>
                                <option value="female">Moteris</option>
                                <option value="other">Kita</option>
                            </select>
                        </div>
                    </div>

                    <div class="row-split">
                        <div class="form-group">
                            <label>Miestas</label>
                            <input type="text" name="city" id="u_formCity">
                        </div>
                        <div class="form-group">
                            <label>Šalis</label>
                            <input type="text" name="country" id="u_formCountry">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Sistemos rolė</label>
                        <select name="is_admin" id="u_formIsAdmin">
                            <option value="0">Paprastas vartotojas</option>
                            <option value="1">Administratorius</option>
                        </select>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; border-top:1px solid #eee; padding-top:20px;">
                        <button type="submit" class="btn">Išsaugoti pakeitimus</button>
                    </div>
                </form>

                <form method="post" onsubmit="return confirm('Ar tikrai norite ištrinti šį vartotoją? Šis veiksmas negrįžtamas.');" style="margin-top: 15px;">
                    <?php echo function_exists('csrfField') ? csrfField() : ''; ?>
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" id="u_deleteId">
                    <button type="submit" class="btn" style="background:#fee2e2; color:#b91c1c; border-color:#fecaca; width:100%;">Ištrinti vartotoją iš sistemos</button>
                </form>
            </div>

            <div id="tab-listings" class="tab-content">
                <div id="u_listings_container"></div>
            </div>

            <div id="tab-comm-orders" class="tab-content">
                <h4 style="margin-top:0;">Nupirkti daiktai</h4>
                <div id="u_orders_bought_container"></div>
                <hr style="border:0; border-top:1px dashed #ddd; margin:20px 0;">
                <h4 style="margin-top:0;">Parduoti daiktai</h4>
                <div id="u_orders_sold_container"></div>
            </div>

            <div id="tab-store-orders" class="tab-content">
                <div id="u_orders_container"></div>
            </div>

            <div id="tab-reports" class="tab-content">
                <div id="u_reports_container"></div>
            </div>

            <div id="tab-threads" class="tab-content">
                <div id="u_threads_container"></div>
            </div>

            <div id="tab-tickets" class="tab-content">
                <div id="u_tickets_container"></div>
            </div>

        </div>
    </div>
</div>

<script>
    const userModal = document.getElementById('userModal');
    
    // Tabų perjungimo logika
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            this.classList.add('active');
            document.getElementById(this.getAttribute('data-target')).classList.add('active');
        });
    });

    function escapeHtml(text) {
        if (!text) return '';
        return text.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    function getStatusDot(status) {
        if(!status) return '<span class="status-dot"></span>';
        let s = status.toLowerCase();
        if (s.includes('įvykdyt') || s.includes('apmokėt') || s.includes('išsiųst') || s.includes('paid') || s.includes('completed')) {
            return '<span class="status-dot completed"></span>';
        }
        if (s.includes('atšauk') || s.includes('atmest') || s.includes('failed') || s.includes('cancelled')) {
            return '<span class="status-dot cancelled"></span>';
        }
        return '<span class="status-dot pending"></span>';
    }

    document.querySelectorAll('.open-user-modal').forEach(btn => {
        btn.addEventListener('click', function() {
            let data = {};
            try {
                data = JSON.parse(this.getAttribute('data-user'));
            } catch(e) { console.error(e); return; }
            
            // Atstatome tabus į pirmąjį
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelector('.tab-btn[data-target="tab-info"]').classList.add('active');
            document.getElementById('tab-info').classList.add('active');

            // 1. Profilio Forma
            document.getElementById('u_id').innerText = data.id;
            document.getElementById('u_formId').value = data.id;
            document.getElementById('u_deleteId').value = data.id;
            
            document.getElementById('u_formName').value = data.name || '';
            document.getElementById('u_formEmail').value = data.email || '';
            document.getElementById('u_formBirthdate').value = data.birthdate || '';
            document.getElementById('u_formGender').value = data.gender || '';
            document.getElementById('u_formCity').value = data.city || '';
            document.getElementById('u_formCountry').value = data.country || '';
            document.getElementById('u_formIsAdmin').value = data.is_admin == 1 ? '1' : '0';
            
            // Blokavimo statusas
            const blockBox = document.getElementById('u_block_status');
            if (data.active_block) {
                let until = data.active_block.banned_until ? data.active_block.banned_until : 'Visam laikui';
                blockBox.innerHTML = `<div style="background:#fee2e2; color:#b91c1c; padding:12px; border-radius:6px; border:1px solid #fecaca;">
                    <strong style="font-size:14px;">⚠️ VARTOTOJAS UŽBLOKUOTAS BENDRUOMENĖJE</strong><br>
                    Priežastis: ${escapeHtml(data.active_block.reason)}<br>Iki: ${until}
                </div>`;
            } else {
                blockBox.innerHTML = '';
            }

            // 2. Skelbimai
            const lContainer = document.getElementById('u_listings_container');
            if (data.listings && data.listings.length > 0) {
                lContainer.innerHTML = `<table class="data-table">
                    <thead><tr><th>ID</th><th>Pavadinimas</th><th>Kaina</th><th>Statusas</th><th>Sukurta</th></tr></thead>
                    <tbody>
                        ${data.listings.map(i => `<tr>
                            <td>#${i.id}</td>
                            <td>${escapeHtml(i.title)}</td>
                            <td>${parseFloat(i.price).toFixed(2)} €</td>
                            <td>${getStatusDot(i.status)} ${escapeHtml(i.status)}</td>
                            <td class="muted-text">${i.created_at.substring(0, 16)}</td>
                        </tr>`).join('')}
                    </tbody>
                </table>`;
            } else { lContainer.innerHTML = '<p class="muted-text">Nėra skelbimų.</p>'; }

            // 3. Bendruomenės užsakymai (Nupirkti)
            const obContainer = document.getElementById('u_orders_bought_container');
            if (data.orders_bought && data.orders_bought.length > 0) {
                obContainer.innerHTML = `<table class="data-table">
                    <thead><tr><th>Užsakymo ID</th><th>Daikto ID</th><th>Suma</th><th>Statusas</th><th>Data</th></tr></thead>
                    <tbody>
                        ${data.orders_bought.map(i => `<tr>
                            <td>#${i.id}</td><td>#${i.item_id}</td>
                            <td>${parseFloat(i.total_amount).toFixed(2)} €</td>
                            <td>${getStatusDot(i.status)} ${escapeHtml(i.status)}</td>
                            <td class="muted-text">${i.created_at.substring(0, 16)}</td>
                        </tr>`).join('')}
                    </tbody>
                </table>`;
            } else { obContainer.innerHTML = '<p class="muted-text">Nėra nupirktų daiktų.</p>'; }

            // 4. Bendruomenės užsakymai (Parduoti)
            const osContainer = document.getElementById('u_orders_sold_container');
            if (data.orders_sold && data.orders_sold.length > 0) {
                osContainer.innerHTML = `<table class="data-table">
                    <thead><tr><th>Užsakymo ID</th><th>Daikto ID</th><th>Suma</th><th>Statusas</th><th>Data</th></tr></thead>
                    <tbody>
                        ${data.orders_sold.map(i => `<tr>
                            <td>#${i.id}</td><td>#${i.item_id}</td>
                            <td>${parseFloat(i.total_amount).toFixed(2)} €</td>
                            <td>${getStatusDot(i.status)} ${escapeHtml(i.status)}</td>
                            <td class="muted-text">${i.created_at.substring(0, 16)}</td>
                        </tr>`).join('')}
                    </tbody>
                </table>`;
            } else { osContainer.innerHTML = '<p class="muted-text">Nėra parduotų daiktų.</p>'; }

            // 5. Parduotuvės užsakymai
            const soContainer = document.getElementById('u_orders_container');
            if (data.recent_orders && data.recent_orders.length > 0) {
                soContainer.innerHTML = `<table class="data-table">
                    <thead><tr><th>ID</th><th>Suma</th><th>Statusas</th><th>Data</th></tr></thead>
                    <tbody>
                        ${data.recent_orders.map(i => `<tr>
                            <td>#${i.id}</td>
                            <td>${parseFloat(i.total).toFixed(2)} €</td>
                            <td>${getStatusDot(i.status)} ${escapeHtml(i.status)}</td>
                            <td class="muted-text">${i.created_at.substring(0, 16)}</td>
                        </tr>`).join('')}
                    </tbody>
                </table>`;
            } else { soContainer.innerHTML = '<p class="muted-text">Parduotuvės užsakymų nėra.</p>'; }

            // 6. Pranešimai (Reports)
            const rContainer = document.getElementById('u_reports_container');
            if (data.reports && data.reports.length > 0) {
                rContainer.innerHTML = `<table class="data-table">
                    <thead><tr><th>ID</th><th>Skelbimo ID</th><th>Priežastis</th><th>Statusas</th><th>Data</th></tr></thead>
                    <tbody>
                        ${data.reports.map(i => `<tr>
                            <td>#${i.id}</td><td>#${i.listing_id}</td>
                            <td>${escapeHtml(i.reason)}</td>
                            <td>${getStatusDot(i.status)} ${escapeHtml(i.status)}</td>
                            <td class="muted-text">${i.created_at.substring(0, 16)}</td>
                        </tr>`).join('')}
                    </tbody>
                </table>`;
            } else { rContainer.innerHTML = '<p class="muted-text">Nėra pranešimų apie nusižengimus.</p>'; }

            // 7. Temos (Threads)
            const tContainer = document.getElementById('u_threads_container');
            if (data.threads && data.threads.length > 0) {
                tContainer.innerHTML = `<table class="data-table">
                    <thead><tr><th>ID</th><th>Temos pavadinimas</th><th>Sukurta</th></tr></thead>
                    <tbody>
                        ${data.threads.map(i => `<tr>
                            <td>#${i.id}</td>
                            <td>${escapeHtml(i.title)}</td>
                            <td class="muted-text">${i.created_at.substring(0, 16)}</td>
                        </tr>`).join('')}
                    </tbody>
                </table>`;
            } else { tContainer.innerHTML = '<p class="muted-text">Nesukurta nei viena tema.</p>'; }

            // 8. Bilietai (Tickets)
            const tkContainer = document.getElementById('u_tickets_container');
            if (data.tickets && data.tickets.length > 0) {
                tkContainer.innerHTML = `<table class="data-table">
                    <thead><tr><th>ID</th><th>Tipas</th><th>Susijęs Užsakymas</th><th>Statusas</th><th>Data</th></tr></thead>
                    <tbody>
                        ${data.tickets.map(i => `<tr>
                            <td>#${i.id}</td>
                            <td>${escapeHtml(i.type)}</td>
                            <td>${i.order_id ? '#'+i.order_id : '-'}</td>
                            <td>${getStatusDot(i.status)} ${escapeHtml(i.status)}</td>
                            <td class="muted-text">${i.created_at.substring(0, 16)}</td>
                        </tr>`).join('')}
                    </tbody>
                </table>`;
            } else { tkContainer.innerHTML = '<p class="muted-text">Nėra pagalbos bilietų.</p>'; }
            
            // Parodome modalą
            userModal.style.display = 'flex';
            setTimeout(() => userModal.classList.add('open'), 10);
        });
    });

    function closeUserModal() {
        userModal.classList.remove('open');
        setTimeout(() => userModal.style.display = 'none', 200);
    }
    
    userModal.addEventListener('click', e => {
        if(e.target === userModal) closeUserModal();
    });
</script>
