<?php
// admin/orders.php

// 1. Surenkame duomenis
// Prijungiame ir delivery_details, kad galėtume rodyti paštomatą
$allOrders = $pdo->query('
    SELECT o.*, u.name AS user_name, u.email AS user_email 
    FROM orders o 
    LEFT JOIN users u ON u.id = o.user_id 
    ORDER BY o.created_at DESC
')->fetchAll();

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
        max-width: 800px;
        max-height: 90vh;
        border-radius: 16px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.2);
        overflow-y: auto;
        position: relative;
        display: flex;
        flex-direction: column;
    }
    
    .modal-header {
        padding: 20px 24px;
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
    
    .info-group h4 { margin: 0 0 8px 0; font-size: 13px; text-transform: uppercase; color: #666; }
    .info-group p { margin: 0; font-size: 15px; font-weight: 500; line-height: 1.4; }
    
    .item-list { border: 1px solid #eee; border-radius: 12px; overflow: hidden; }
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

    @media (max-width: 700px) {
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
  
  <table>
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
          </td>
          <td><?php echo date('Y-m-d H:i', strtotime($order['created_at'])); ?></td>
          <td><strong><?php echo number_format((float)$order['total'], 2); ?> €</strong></td>
          <td>
            <span class="status-badge <?php echo $statusClass; ?>">
                <?php echo ucfirst($order['status']); ?>
            </span>
          </td>
          <td style="text-align:right;">
            <button class="btn secondary open-order-modal" 
                    type="button"
                    data-order='<?php echo htmlspecialchars(json_encode($order, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>'>
              Peržiūrėti
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$allOrders): ?>
        <tr><td colspan="6" class="muted" style="text-align:center; padding:20px;">Užsakymų nerasta.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div id="orderModal" class="modal-overlay">
    <div class="modal-window">
        <div class="modal-header">
            <h3 class="modal-title">Užsakymas #<span id="m_orderId"></span></h3>
            <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        
        <div class="modal-body">
            <div class="modal-grid">
                <div class="info-group">
                    <h4>Pirkėjas</h4>
                    <p id="m_customerName"></p>
                    <p id="m_customerEmail" class="muted" style="font-size:14px; margin-top:2px;"></p>
                    <p id="m_customerPhone" class="muted" style="font-size:14px;"></p>
                </div>
                <div class="info-group">
                    <h4>Pristatymo informacija</h4>
                    <p id="m_address" style="white-space: pre-line;"></p>
                    <div id="m_deliveryMethod" style="margin-top:5px; font-size:13px; color:#2563eb; font-weight:600;"></div>
                </div>
            </div>

            <h4 style="margin-bottom:10px; color:#666; font-size:13px; text-transform:uppercase;">Užsakytos prekės</h4>
            <div id="m_itemsList" class="item-list">
                </div>
            
            <div style="text-align:right; margin-top:16px; font-size:18px;">
                Viso: <strong id="m_total"></strong>
            </div>
        </div>

        <div class="modal-footer">
            <form method="post" style="display:flex; gap:10px; align-items:center; width:100%;">
                <?php echo csrfField(); ?>
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
            </form>
            <button class="btn secondary" onclick="closeModal()">Uždaryti</button>
        </div>
    </div>
</div>

<script>
    const modal = document.getElementById('orderModal');
    
    // Atidaryti modalą
    document.querySelectorAll('.open-order-modal').forEach(btn => {
        btn.addEventListener('click', function() {
            let data = {};
            try {
                data = JSON.parse(this.getAttribute('data-order'));
            } catch(e) { console.error("JSON Error", e); return; }
            
            // Užpildome duomenis
            document.getElementById('m_orderId').innerText = data.id;
            document.getElementById('m_formOrderId').value = data.id;
            
            document.getElementById('m_customerName').innerText = data.customer_name;
            document.getElementById('m_customerEmail').innerText = data.customer_email;
            document.getElementById('m_customerPhone').innerText = data.customer_phone || '-';
            
            // Adresas ir paštomatas
            let addressText = data.customer_address;
            let deliveryText = '';

            if (data.delivery_method === 'locker') {
                deliveryText = '📦 Paštomatas';
                // Bandome ištraukti detales jei jos JSON
                try {
                    const details = JSON.parse(data.delivery_details);
                    if (details.address) addressText = details.address;
                    if (details.title) addressText = details.title + ' (' + details.address + ')';
                    if (details.manual_request) addressText = "Rankinis įvedimas: " + details.manual_request;
                } catch(e) {}
            } else {
                deliveryText = '🚚 Kurjeris';
            }
            
            document.getElementById('m_address').innerText = addressText;
            document.getElementById('m_deliveryMethod').innerText = deliveryText;

            document.getElementById('m_total').innerText = parseFloat(data.total).toFixed(2) + ' €';
            
            // Nustatome esamą statusą
            document.getElementById('m_statusSelect').value = data.status;

            // Sugeneruojame prekes
            const itemsContainer = document.getElementById('m_itemsList');
            itemsContainer.innerHTML = '';
            
            if (data.items && data.items.length > 0) {
                data.items.forEach(item => {
                    const price = parseFloat(item.price).toFixed(2);
                    const total = (item.price * item.quantity).toFixed(2);
                    // Jei nėra nuotraukos, dedame placeholder
                    const imgUrl = item.image_url ? item.image_url : 'https://placehold.co/100?text=Foto';
                    
                    // Variacijos
                    let varInfo = '';
                    if (item.variation_info) {
                        varInfo = `<div style="font-size:12px; color:#2563eb; margin-top:2px;">${item.variation_info}</div>`;
                    }

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

            // Parodome modalą
            modal.style.display = 'flex';
            // Mažas timeout animacijai
            setTimeout(() => { modal.classList.add('open'); }, 10);
        });
    });

    // Uždaryti modalą funkcijos
    function closeModal() {
        modal.classList.remove('open');
        setTimeout(() => { modal.style.display = 'none'; }, 200);
    }
    
    // Uždaryti paspaudus už lango ribų
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeModal();
        }
    });
</script>
