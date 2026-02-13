<?php
// admin/orders.php

// 0. IŠTRYNIMO LOGIKA
if (isset($_POST['delete_id'])) {
    $deleteId = (int)$_POST['delete_id'];
    try {
        // Ištriname užsakymą
        $stmtDel = $pdo->prepare("DELETE FROM orders WHERE id = ?");
        $stmtDel->execute([$deleteId]);
        
        // Ištriname susijusias prekes (jei nėra automatinio ON DELETE CASCADE DB lygmenyje)
        $stmtDelItems = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
        $stmtDelItems->execute([$deleteId]);

        // Perkrovimas, kad dingtų iš sąrašo
        echo "<script>window.location.href=window.location.href;</script>";
        exit;
    } catch (PDOException $e) {
        echo "<div class='alert alert-danger'>Klaida trinant: " . $e->getMessage() . "</div>";
    }
}

// 1. Surenkame duomenis
// PATAISYMAS: Pašalintas u.phone, nes jo nėra users lentelėje. Naudojame orders.customer_phone
try {
    $allOrders = $pdo->query('
        SELECT o.*, 
               u.name AS user_name, 
               u.email AS user_email
        FROM orders o 
        LEFT JOIN users u ON u.id = o.user_id 
        ORDER BY o.created_at DESC
    ')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Duomenų bazės klaida: " . $e->getMessage() . "</div>";
    $allOrders = [];
}

$orderItemsStmt = $pdo->prepare('
    SELECT oi.*, p.title, p.image_url 
    FROM order_items oi 
    JOIN products p ON p.id = oi.product_id 
    WHERE order_id = ?
');

// Iš anksto paruošiame prekes kiekvienam užsakymui, kad galėtume perduoti į Modalą
foreach ($allOrders as &$order) {
    $orderItemsStmt->execute([$order['id']]);
    $order['items'] = $orderItemsStmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($order); // Nutraukiame nuorodą
?>

<style>
    /* Statuso ženkleliai */
    .status-badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        display: inline-block;
    }
    
    /* Spalvos pagal būseną */
    .status-laukiama.apmokėjimo { background: #fff7ed; color: #c2410c; border: 1px solid #ffedd5; }
    .status-apmokėta { background: #ecfdf5; color: #047857; border: 1px solid #d1fae5; }
    .status-apdorojama { background: #eff6ff; color: #1d4ed8; border: 1px solid #dbeafe; }
    .status-išsiųsta { background: #f0fdf4; color: #15803d; border: 1px solid #dcfce7; }
    .status-įvykdyta { background: #ecfdf5; color: #047857; border: 1px solid #d1fae5; }
    .status-atšaukta { background: #fef2f2; color: #b91c1c; border: 1px solid #fee2e2; }
    .status-atmesta { background: #fef2f2; color: #b91c1c; border: 1px solid #fee2e2; }

    /* Modal (Iššokantis langas) */
    .modal-overlay {
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.5);
        backdrop-filter: blur(4px);
        z-index: 1000;
        display: none; /* Paslėpta pagal nutylėjimą */
        align-items: center;
        justify-content: center;
        padding: 20px;
        opacity: 0;
        transition: opacity 0.2s ease;
    }
    .modal-overlay.open { display: flex; opacity: 1; }
    
    .modal-window {
        background: #fff;
        width: 100%;
        max-width: 900px;
        max-height: 90vh;
        border-radius: 16px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.2);
        overflow-y: auto;
        position: relative;
        display: flex;
        flex-direction: column;
    }
    
    .modal-header {
        padding: 15px 24px;
        border-bottom: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #fcfcfc;
        position: sticky; top: 0;
        z-index: 10;
    }
    .modal-title { font-size: 18px; font-weight: 700; margin: 0; }
    .modal-close {
        background: none; border: none; font-size: 24px; cursor: pointer; color: #888;
        line-height: 1; padding: 0;
    }
    .modal-body { padding: 24px; }
    
    .modal-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
        margin-bottom: 24px;
    }

    .info-section {
        background: #f9f9f9;
        border-radius: 8px;
        padding: 15px;
        border: 1px solid #eee;
    }
    
    .info-group h4 { margin: 0 0 10px 0; font-size: 12px; text-transform: uppercase; color: #888; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
    .info-row { display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 13px; }
    .info-row label { font-weight: 600; color: #555; }
    .info-row span { text-align: right; color: #111; max-width: 60%; word-break: break-word; }
    
    .item-list { border: 1px solid #eee; border-radius: 12px; overflow: hidden; margin-top: 20px; }
    .item-row {
        display: flex; align-items: center; gap: 12px;
        padding: 10px 12px; border-bottom: 1px solid #eee;
    }
    .item-row:last-child { border-bottom: none; }
    .item-img { width: 40px; height: 40px; border-radius: 6px; object-fit: cover; background: #eee; }
    .item-details { flex: 1; }
    .item-title { font-weight: 600; font-size: 14px; }
    .item-meta { font-size: 12px; color: #777; margin-top:2px; }
    .item-price { font-weight: 700; font-size: 14px; }

    .modal-footer {
        padding: 16px 24px;
        background: #f9f9ff;
        border-top: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    /* Input style for tracking */
    .form-control {
        padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; width: 100%;
    }

    /* Mygtukas ištrynimui */
    .btn-delete {
        background: #fee2e2; 
        color: #b91c1c; 
        border: 1px solid #fca5a5; 
        padding: 6px 10px; 
        font-weight: bold;
        margin-left: 5px;
        cursor: pointer;
        border-radius: 4px;
    }
    .btn-delete:hover {
        background: #fecaca;
    }
    
    /* Bool badges */
    .badge-bool { padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; }
    .badge-yes { background: #dcfce7; color: #166534; }
    .badge-no { background: #f3f4f6; color: #6b7280; }

    @media (max-width: 768px) {
        .modal-grid { grid-template-columns: 1fr; }
        .modal-footer { flex-direction: column; gap: 10px; }
        .modal-footer form { flex-direction: column; align-items: stretch; }
        .modal-footer .btn { width: 100%; }
    }
</style>

<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <h3>Visi užsakymai</h3>
      <span class="muted" style="font-size:13px;">Viso: <?php echo count($allOrders); ?></span>
  </div>
  
  <div style="overflow-x:auto;">
      <table style="width:100%; min-width: 800px;">
        <thead>
            <tr>
                <th>ID</th>
                <th>Klientas</th>
                <th>Data</th>
                <th>Suma</th>
                <th>Statusas</th>
                <th style="text-align:right;">Veiksmai</th>
            </tr>
        </thead>
        <tbody>
          <?php foreach ($allOrders as $order): 
                $statusClass = 'status-' . str_replace(' ', '.', mb_strtolower($order['status']));
          ?>
            <tr>
              <td><strong>#<?php echo (int)$order['id']; ?></strong></td>
              <td>
                <div style="font-weight:600;"><?php echo htmlspecialchars($order['customer_name']); ?></div>
                <div class="muted" style="font-size:12px;"><?php echo htmlspecialchars($order['customer_email']); ?></div>
                <?php if(!empty($order['customer_phone'])): ?>
                    <div class="muted" style="font-size:11px; color:#666;">Tel: <?php echo htmlspecialchars($order['customer_phone']); ?></div>
                <?php endif; ?>
              </td>
              <td><?php echo date('Y-m-d H:i', strtotime($order['created_at'])); ?></td>
              <td><strong><?php echo number_format((float)$order['total'], 2); ?> €</strong></td>
              <td>
                <span class="status-badge <?php echo $statusClass; ?>">
                    <?php echo ucfirst($order['status']); ?>
                </span>
                <?php if(!empty($order['tracking_number'])): ?>
                    <div style="font-size:10px; color:#666; margin-top:2px;">📦 <?php echo htmlspecialchars($order['tracking_number']); ?></div>
                <?php endif; ?>
              </td>
              <td style="text-align:right;">
                <button class="btn secondary open-order-modal" 
                        type="button"
                        data-order='<?php echo htmlspecialchars(json_encode($order, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>'>
                  Peržiūrėti
                </button>
                <form method="POST" onsubmit="return confirm('Ar tikrai norite negrįžtamai ištrinti šį užsakymą?');" style="display:inline;">
                    <input type="hidden" name="delete_id" value="<?php echo $order['id']; ?>">
                    <button type="submit" class="btn-delete" title="Ištrinti užsakymą">X</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$allOrders): ?>
            <tr><td colspan="6" class="muted" style="text-align:center; padding:20px;">Užsakymų nerasta.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
  </div>
</div>

<div id="orderModal" class="modal-overlay">
    <div class="modal-window">
        <div class="modal-header">
            <h3 class="modal-title">Užsakymas #<span id="m_orderId"></span></h3>
            <div style="margin-left: 10px; font-size:12px; color:#888;">
                Sukurtas: <span id="m_createdAt"></span>
            </div>
            <button type="button" class="modal-close" onclick="closeModal()" style="margin-left:auto;">&times;</button>
        </div>
        
        <form method="post" style="display:contents;">
        <div class="modal-body">
            
            <div class="modal-grid">
                
                <div style="display:flex; flex-direction:column; gap:20px;">
                    
                    <div class="info-section">
                        <div class="info-group">
                            <h4>Pirkėjo informacija</h4>
                            <div class="info-row"><label>Vardas:</label> <span id="m_customerName"></span></div>
                            <div class="info-row"><label>El. paštas:</label> <span id="m_customerEmail"></span></div>
                            <div class="info-row"><label>Telefonas:</label> <span id="m_customerPhone"></span></div>
                            <div class="info-row"><label>User ID:</label> <span id="m_userId"></span></div>
                        </div>
                    </div>

                    <div class="info-section">
                        <div class="info-group">
                            <h4>Pristatymo duomenys</h4>
                            <div class="info-row"><label>Būdas:</label> <span id="m_deliveryMethod"></span></div>
                            <div class="info-row"><label>Adresas:</label> <span id="m_address"></span></div>
                            
                            <div style="margin-top:10px; font-size:12px; background:#fff; padding:8px; border:1px dashed #ccc; display:none;" id="m_deliveryDetailsBox">
                                <strong>Papildoma info (JSON):</strong><br>
                                <span id="m_deliveryDetailsText" style="word-break:break-all; color:#555;"></span>
                            </div>

                            <div style="margin-top:15px; padding-top:10px; border-top:1px solid #eee;">
                                <label style="font-size:12px; font-weight:700; color:#444; display:block; margin-bottom:4px;">Siuntos sekimo numeris:</label>
                                <input type="text" name="tracking_number" id="m_trackingInput" class="form-control" placeholder="Įveskite kodą...">
                                <div style="font-size:11px; color:#888; margin-top:2px;">Atnaujinus į "Išsiųsta", kodas bus išsiųstas klientui.</div>
                            </div>
                        </div>
                    </div>

                </div>

                <div style="display:flex; flex-direction:column; gap:20px;">
                    
                    <div class="info-section">
                        <div class="info-group">
                            <h4>Finansinė informacija</h4>
                            <div class="info-row"><label>Nuolaidos kodas:</label> <span id="m_discountCode"></span></div>
                            <div class="info-row"><label>Nuolaidos suma:</label> <span id="m_discountAmount"></span></div>
                            <div class="info-row"><label>Pristatymo kaina:</label> <span id="m_shippingAmount"></span></div>
                            <div class="info-row" style="font-size:16px; border-top:1px solid #ddd; padding-top:5px; margin-top:5px;">
                                <label>VISO:</label> <strong id="m_total" style="color:#000;"></strong>
                            </div>
                            <div style="margin-top:10px; font-size:11px; color:#666;">
                                Stripe Session ID: <br>
                                <span id="m_stripeSession" style="font-family:monospace; word-break:break-all;"></span>
                            </div>
                        </div>
                    </div>

                    <div class="info-section">
                        <div class="info-group">
                            <h4>Sistemos būsenos (Email & Logs)</h4>
                            <div class="info-row"><label>Atnaujinta:</label> <span id="m_updatedAt"></span></div>
                            <div class="info-row"><label>Laiškas (Thank You):</label> <span id="m_emailThankYou"></span></div>
                            <div class="info-row"><label>Laiškas (Išsiųsta):</label> <span id="m_emailShipped"></span></div>
                            <div class="info-row"><label>Laiškas (Review):</label> <span id="m_emailReview"></span></div>
                            <div class="info-row"><label>Prim. (1h):</label> <span id="m_emailRem1h"></span></div>
                            <div class="info-row"><label>Prim. (24h):</label> <span id="m_emailRem24h"></span></div>
                        </div>
                    </div>

                </div>
            </div>

            <h4 style="margin-bottom:10px; color:#666; font-size:13px; text-transform:uppercase;">Užsakytos prekės</h4>
            <div id="m_itemsList" class="item-list"></div>
            
        </div>

        <div class="modal-footer">
                <?php if(function_exists('csrfField')) echo csrfField(); ?>
                <input type="hidden" name="action" value="order_status">
                <input type="hidden" name="order_id" id="m_formOrderId">
                
                <div style="display:flex; gap:8px; align-items:center; flex:1;">
                    <label style="font-weight:600; font-size:14px;">Būsena:</label>
                    <select name="status" id="m_statusSelect" style="margin:0; width:auto; flex:1; max-width:200px;">
                        <option value="laukiama apmokėjimo">Laukiama apmokėjimo</option>
                        <option value="apmokėta">Apmokėta</option>
                        <option value="apdorojama">Apdorojama</option>
                        <option value="išsiųsta">Išsiųsta</option>
                        <option value="įvykdyta">Įvykdyta</option>
                        <option value="atšaukta">Atšaukta</option>
                        <option value="atmesta">Atmesta</option>
                    </select>
                    <button class="btn" type="submit">Atnaujinti</button>
                </div>
            <button type="button" class="btn secondary" onclick="closeModal()">Uždaryti</button>
        </div>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('orderModal');
    
    function boolBadge(val) {
        return val == 1 
            ? '<span class="badge-bool badge-yes">TAIP</span>' 
            : '<span class="badge-bool badge-no">NE</span>';
    }

    function formatDate(dateStr) {
        if(!dateStr) return '-';
        return dateStr;
    }

    // Atidaryti modalą
    document.querySelectorAll('.open-order-modal').forEach(btn => {
        btn.addEventListener('click', function() {
            let data = {};
            try {
                data = JSON.parse(this.getAttribute('data-order'));
            } catch(e) { console.error("JSON Error", e); return; }
            
            // Header
            document.getElementById('m_orderId').innerText = data.id;
            document.getElementById('m_createdAt').innerText = data.created_at;
            document.getElementById('m_formOrderId').value = data.id;
            
            // Customer
            document.getElementById('m_customerName').innerText = data.customer_name;
            document.getElementById('m_customerEmail').innerText = data.customer_email;
            document.getElementById('m_customerPhone').innerText = data.customer_phone || '-';
            document.getElementById('m_userId').innerText = data.user_id ? ('#' + data.user_id) : 'Svečias';
            
            // Delivery
            let methodMap = {
                'courier': 'Kurjeris',
                'locker': 'Paštomatas',
                'pickup': 'Atsiėmimas'
            };
            document.getElementById('m_deliveryMethod').innerText = methodMap[data.delivery_method] || data.delivery_method;
            document.getElementById('m_address').innerText = data.customer_address || '-';
            document.getElementById('m_trackingInput').value = data.tracking_number || '';
            
            // Delivery Details (JSON)
            const detailsBox = document.getElementById('m_deliveryDetailsBox');
            const detailsText = document.getElementById('m_deliveryDetailsText');
            if (data.delivery_details) {
                detailsBox.style.display = 'block';
                // Jei tai JSON, pabandom gražiau atvaizduoti, jei ne - tiesiog tekstą
                try {
                    let obj = JSON.parse(data.delivery_details);
                    detailsText.innerText = JSON.stringify(obj, null, 2); 
                } catch(e) {
                    detailsText.innerText = data.delivery_details;
                }
            } else {
                detailsBox.style.display = 'none';
            }

            // Financials
            document.getElementById('m_discountCode').innerText = data.discount_code ? data.discount_code : '-';
            document.getElementById('m_discountAmount').innerText = parseFloat(data.discount_amount || 0).toFixed(2) + ' €';
            document.getElementById('m_shippingAmount').innerText = parseFloat(data.shipping_amount || 0).toFixed(2) + ' €';
            document.getElementById('m_total').innerText = parseFloat(data.total || 0).toFixed(2) + ' €';
            document.getElementById('m_stripeSession').innerText = data.stripe_session_id || '-';

            // System Flags
            document.getElementById('m_updatedAt').innerText = formatDate(data.updated_at);
            document.getElementById('m_emailThankYou').innerHTML = boolBadge(data.email_thankyou);
            document.getElementById('m_emailShipped').innerHTML = boolBadge(data.email_shipped_sent);
            document.getElementById('m_emailReview').innerHTML = boolBadge(data.email_review_sent);
            document.getElementById('m_emailRem1h').innerHTML = boolBadge(data.email_rem_1h);
            document.getElementById('m_emailRem24h').innerHTML = boolBadge(data.email_rem_24h);

            // Status select
            document.getElementById('m_statusSelect').value = data.status;

            // Items List
            const itemsContainer = document.getElementById('m_itemsList');
            itemsContainer.innerHTML = '';
            
            if (data.items && data.items.length > 0) {
                data.items.forEach(item => {
                    const price = parseFloat(item.price).toFixed(2);
                    const total = (item.price * item.quantity).toFixed(2);
                    const imgUrl = item.image_url ? item.image_url : 'https://placehold.co/100?text=Foto';
                    
                    let varInfo = item.variation_info 
                        ? `<div style="font-size:12px; color:#2563eb; margin-top:2px;">${item.variation_info}</div>` 
                        : '';

                    const html = `
                        <div class="item-row">
                            <img src="${imgUrl}" class="item-img" alt="">
                            <div class="item-details">
                                <div class="item-title">${item.title || 'Prekė ištrinta'}</div>
                                ${varInfo}
                                <div class="item-meta">Kiekis: ${item.quantity} vnt.</div>
                            </div>
                            <div class="item-price">${total} €</div>
                        </div>
                    `;
                    itemsContainer.insertAdjacentHTML('beforeend', html);
                });
            } else {
                itemsContainer.innerHTML = '<div style="padding:12px; text-align:center; color:#999;">Prekių sąrašas tuščias</div>';
            }

            // Show
            modal.style.display = 'flex';
            setTimeout(() => { modal.classList.add('open'); }, 10);
        });
    });

    function closeModal() {
        modal.classList.remove('open');
        setTimeout(() => { modal.style.display = 'none'; }, 200);
    }
    
    modal.addEventListener('click', function(e) {
        if (e.target === modal) closeModal();
    });
</script>
