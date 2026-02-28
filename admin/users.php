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

// Užsakymų istorijos gavimui paruošiame statement (parduotuvės užsakymai)
$ordersStmt = $pdo->prepare("SELECT id, created_at, total, status FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");

foreach ($users as &$u) {
    // Parduotuvės užsakymai
    try {
        $ordersStmt->execute([$u['id']]);
        $u['recent_orders'] = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $u['recent_orders'] = [];
    }

    // Bendruomenės užblokavimai
    $blockStmt = $pdo->prepare("SELECT * FROM community_blocks WHERE user_id = ? AND (banned_until IS NULL OR banned_until > NOW()) ORDER BY created_at DESC LIMIT 1");
    $blockStmt->execute([$u['id']]);
    $u['active_block'] = $blockStmt->fetch(PDO::FETCH_ASSOC);

    // Bendruomenės statistika
    $u['listings_count'] = $pdo->query("SELECT COUNT(*) FROM community_listings WHERE user_id = " . (int)$u['id'])->fetchColumn();
    $u['community_orders_bought'] = $pdo->query("SELECT COUNT(*) FROM community_orders WHERE buyer_id = " . (int)$u['id'])->fetchColumn();
    $u['community_orders_sold'] = $pdo->query("SELECT COUNT(*) FROM community_orders WHERE seller_id = " . (int)$u['id'])->fetchColumn();
    $u['reports_count'] = $pdo->query("SELECT COUNT(*) FROM community_reports WHERE reporter_id = " . (int)$u['id'])->fetchColumn();
    $u['threads_count'] = $pdo->query("SELECT COUNT(*) FROM community_threads WHERE user_id = " . (int)$u['id'])->fetchColumn();
    $u['tickets_count'] = $pdo->query("SELECT COUNT(*) FROM community_tickets WHERE user_id = " . (int)$u['id'])->fetchColumn();
    
    try {
        $u['comments_count'] = $pdo->query("SELECT COUNT(*) FROM community_comments WHERE user_id = " . (int)$u['id'])->fetchColumn();
    } catch (Exception $e) {
        $u['comments_count'] = 0;
    }
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
        background: #fff; width: 100%; max-width: 650px;
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
    .info-row { margin-bottom: 16px; border-bottom: 1px dashed #eee; padding-bottom: 8px; }
    .info-label { font-size: 12px; text-transform: uppercase; color: #888; font-weight: 600; display: block; margin-bottom: 4px; }

    /* Forma modale */
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; font-size: 13px; font-weight: 600; color: #555; }
    .form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
    .row-split { display: flex; gap: 15px; }
    .row-split > div { flex: 1; }

    /* Užsakymų sąrašas modale */
    .mini-orders-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
    .mini-orders-table th { text-align: left; color: #666; font-weight: 600; padding-bottom: 8px; border-bottom: 1px solid #eee; }
    .mini-orders-table td { padding: 8px 0; border-bottom: 1px solid #f9f9f9; }
    .status-dot { height: 8px; width: 8px; background-color: #ccc; border-radius: 50%; display: inline-block; margin-right: 5px; }
    .status-dot.completed { background-color: #10b981; }
    .status-dot.pending { background-color: #f59e0b; }
    .status-dot.cancelled { background-color: #ef4444; }

    .community-stats { padding:0; margin:0; list-style:none; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .community-stats li { background: #fff; padding: 10px; border: 1px solid #eee; border-radius: 6px; }
    .community-stats strong { display: block; font-size: 16px; color: #111; }
    .community-stats span { font-size: 12px; color: #666; text-transform: uppercase; }
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
                            Redaguoti / Peržiūrėti
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
        <div class="modal-body">

            <h4 style="margin-top:0; margin-bottom:15px;">Redaguoti informaciją</h4>
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

                <button type="submit" class="btn">Išsaugoti pakeitimus</button>
            </form>

            <hr style="margin: 24px 0; border: none; border-top: 1px solid #eee;">

            <h4 style="margin-bottom: 15px;">Bendruomenės veikla</h4>
            <div id="u_community_info" style="background: #f9fafb; padding: 15px; border-radius: 8px;"></div>
            
            <hr style="margin: 24px 0; border: none; border-top: 1px solid #eee;">

            <div class="info-row" style="border:none;">
                <h4 style="margin-top:0; margin-bottom:10px;">Paskutiniai Parduotuvės Užsakymai</h4>
                <div id="u_orders_container"></div>
            </div>

            <hr style="margin: 24px 0; border: none; border-top: 1px solid #eee;">
            
            <form method="post" onsubmit="return confirm('Ar tikrai norite ištrinti šį vartotoją? Šis veiksmas negrįžtamas.');">
                <?php echo function_exists('csrfField') ? csrfField() : ''; ?>
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" id="u_deleteId">
                <button type="submit" class="btn" style="background:#fee2e2; color:#b91c1c; border-color:#fecaca; width:100%;">Ištrinti vartotoją iš sistemos</button>
            </form>

        </div>
    </div>
</div>

<script>
    const userModal = document.getElementById('userModal');
    
    document.querySelectorAll('.open-user-modal').forEach(btn => {
        btn.addEventListener('click', function() {
            let data = {};
            try {
                data = JSON.parse(this.getAttribute('data-user'));
            } catch(e) { console.error(e); return; }
            
            // Užpildome formos laukus
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
            
            // Bendruomenės statisitika
            const commBox = document.getElementById('u_community_info');
            let commHtml = '';

            if (data.active_block) {
                let until = data.active_block.banned_until ? data.active_block.banned_until : 'Visam laikui';
                commHtml += `<div style="background:#fee2e2; color:#b91c1c; padding:10px; border-radius:6px; margin-bottom:15px;">
                    <strong>UŽBLOKUOTAS</strong><br>Priežastis: ${data.active_block.reason}<br>Iki: ${until}
                </div>`;
            } else {
                commHtml += `<div style="color:#10b981; font-weight:600; margin-bottom:15px;">Vartotojas nėra užblokuotas bendruomenėje</div>`;
            }

            commHtml += `
                <ul class="community-stats">
                    <li><span>Skelbimai</span><strong>${data.listings_count}</strong></li>
                    <li><span>Nupirkti daiktai</span><strong>${data.community_orders_bought}</strong></li>
                    <li><span>Parduoti daiktai</span><strong>${data.community_orders_sold}</strong></li>
                    <li><span>Pranešimai (Reports)</span><strong>${data.reports_count}</strong></li>
                    <li><span>Sukurtos temos</span><strong>${data.threads_count}</strong></li>
                    <li><span>Pagalbos bilietai</span><strong>${data.tickets_count}</strong></li>
                    <li><span>Komentarai</span><strong>${data.comments_count}</strong></li>
                </ul>
            `;
            commBox.innerHTML = commHtml;

            // Užsakymų renderinimas
            const ordersBox = document.getElementById('u_orders_container');
            if (data.recent_orders && data.recent_orders.length > 0) {
                let html = '<table class="mini-orders-table"><thead><tr><th>ID</th><th>Data</th><th>Suma</th><th>Statusas</th></tr></thead><tbody>';
                data.recent_orders.forEach(o => {
                    let dotClass = 'pending';
                    const st = o.status ? o.status.toLowerCase() : '';
                    if (st.includes('įvykdyt') || st.includes('apmokėt') || st.includes('išsiųst')) dotClass = 'completed';
                    if (st.includes('atšauk') || st.includes('atmest')) dotClass = 'cancelled';
                    
                    html += `<tr>
                        <td>#${o.id}</td>
                        <td>${o.created_at ? o.created_at.substring(0, 10) : '-'}</td>
                        <td>${o.total ? parseFloat(o.total).toFixed(2) : '0.00'} €</td>
                        <td><span class="status-dot ${dotClass}"></span>${o.status || '-'}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
                ordersBox.innerHTML = html;
            } else {
                ordersBox.innerHTML = '<div style="color:#999; font-size:13px; padding-top:5px;">Parduotuvės užsakymų nėra.</div>';
            }
            
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
