<?php
// admin/users.php

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
$users = $stmt->fetchAll();

// Užsakymų istorijos gavimui paruošiame statement
$ordersStmt = $pdo->prepare("SELECT id, created_at, total, status FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");

foreach ($users as &$u) {
    $ordersStmt->execute([$u['id']]);
    $u['recent_orders'] = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
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
        background: #fff; width: 100%; max-width: 600px;
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
    .info-value { font-size: 15px; font-weight: 500; color: #111; }

    /* Užsakymų sąrašas modale */
    .mini-orders-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
    .mini-orders-table th { text-align: left; color: #666; font-weight: 600; padding-bottom: 8px; border-bottom: 1px solid #eee; }
    .mini-orders-table td { padding: 8px 0; border-bottom: 1px solid #f9f9f9; }
    .status-dot { height: 8px; width: 8px; background-color: #ccc; border-radius: 50%; display: inline-block; margin-right: 5px; }
    .status-dot.completed { background-color: #10b981; }
    .status-dot.pending { background-color: #f59e0b; }
    .status-dot.cancelled { background-color: #ef4444; }
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
                            Peržiūrėti
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
            <div class="info-row">
                <span class="info-label">Vardas</span>
                <div class="info-value" id="u_name"></div>
            </div>
            <div class="info-row">
                <span class="info-label">El. paštas</span>
                <div class="info-value" id="u_email"></div>
            </div>
            <div class="info-row">
                <span class="info-label">Telefonas</span>
                <div class="info-value" id="u_phone"></div>
            </div>
            <div class="info-row">
                <span class="info-label">Adresas</span>
                <div class="info-value" id="u_address" style="white-space:pre-line;"></div>
            </div>
            
            <div class="info-row" style="border:none;">
                <span class="info-label">Paskutiniai užsakymai</span>
                <div id="u_orders_container"></div>
            </div>
            
            <div style="margin-top:24px; padding-top:16px; border-top:1px solid #eee;">
                <h4 style="margin:0 0 12px 0;">Administravimo veiksmai</h4>
                <form method="post" style="display:flex; gap:10px; align-items:flex-end;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="update_user_role">
                    <input type="hidden" name="user_id" id="u_formId">
                    
                    <div style="flex:1;">
                        <label style="font-size:12px; font-weight:600;">Pakeisti rolę:</label>
                        <select name="is_admin" id="u_roleSelect" style="margin:0;">
                            <option value="0">Paprastas vartotojas</option>
                            <option value="1">Administratorius</option>
                        </select>
                    </div>
                    <button type="submit" class="btn">Išsaugoti</button>
                </form>
                
                <form method="post" style="margin-top:16px;" onsubmit="return confirm('Ar tikrai norite ištrinti šį vartotoją? Visi jo užsakymai taip pat bus ištrinti.');">
                     <?php echo csrfField(); ?>
                     <input type="hidden" name="action" value="delete_user">
                     <input type="hidden" name="user_id" id="u_deleteId">
                     <button type="submit" class="btn" style="background:#fee2e2; color:#b91c1c; border-color:#fecaca; width:100%;">Ištrinti vartotoją</button>
                </form>
            </div>
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
            
            document.getElementById('u_id').innerText = data.id;
            document.getElementById('u_formId').value = data.id;
            document.getElementById('u_deleteId').value = data.id;
            
            document.getElementById('u_name').innerText = data.name;
            document.getElementById('u_email').innerText = data.email;
            document.getElementById('u_phone').innerText = data.phone || '-';
            document.getElementById('u_address').innerText = data.address || '-';
            
            document.getElementById('u_roleSelect').value = data.is_admin == 1 ? '1' : '0';
            
            // Užsakymų renderinimas
            const ordersBox = document.getElementById('u_orders_container');
            if (data.recent_orders && data.recent_orders.length > 0) {
                let html = '<table class="mini-orders-table"><thead><tr><th>ID</th><th>Data</th><th>Suma</th><th>Statusas</th></tr></thead><tbody>';
                data.recent_orders.forEach(o => {
                    let dotClass = 'pending';
                    const st = o.status.toLowerCase();
                    if (st.includes('įvykdyt') || st.includes('apmokėt') || st.includes('išsiųst')) dotClass = 'completed';
                    if (st.includes('atšauk') || st.includes('atmest')) dotClass = 'cancelled';
                    
                    html += `<tr>
                        <td>#${o.id}</td>
                        <td>${o.created_at.substring(0, 10)}</td>
                        <td>${parseFloat(o.total).toFixed(2)} €</td>
                        <td><span class="status-dot ${dotClass}"></span>${o.status}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
                ordersBox.innerHTML = html;
            } else {
                ordersBox.innerHTML = '<div style="color:#999; font-size:13px; padding-top:5px;">Užsakymų nėra.</div>';
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
