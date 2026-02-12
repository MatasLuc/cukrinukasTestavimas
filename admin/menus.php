<?php
// admin/menus.php

// 1. Gauname visus meniu elementus, surikiuotus pagal tvarką
$menuItems = $pdo->query('SELECT * FROM navigation_items ORDER BY parent_id ASC, sort_order ASC')->fetchAll();

// 2. Sukuriame hierarchinį medį
$menuTree = [];
foreach ($menuItems as $item) {
    $menuTree[$item['parent_id'] ?? 0][] = $item;
}

// 3. Rekursinė funkcija atvaizdavimui
function renderMenuTree($pdo, $tree, $parentId = 0, $level = 0) {
    if (!isset($tree[$parentId])) return;

    foreach ($tree[$parentId] as $index => $item) {
        $hasChildren = isset($tree[$item['id']]);
        $padding = $level * 40; // Atitraukimas subkategorijoms
        $isFirst = $index === 0;
        $isLast = $index === count($tree[$parentId]) - 1;
        
        ?>
        <div class="menu-item-row" style="margin-left: <?php echo $padding; ?>px;">
            <div class="menu-handle">
                <?php if ($level > 0): ?>
                    <span style="color:#cbd5e1; margin-right:8px;">└──</span>
                <?php endif; ?>
                <span class="menu-icon"><?php echo $level === 0 ? '📂' : '🔗'; ?></span>
                
                <div style="display:flex; flex-direction:column;">
                    <span class="menu-label"><?php echo htmlspecialchars($item['label']); ?></span>
                    <span class="menu-url"><?php echo htmlspecialchars($item['url']); ?></span>
                </div>
            </div>

            <div class="menu-actions">
                <div class="sort-controls">
                    <?php if (!$isFirst): ?>
                    <form method="post" style="margin:0;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="move_menu_item">
                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                        <input type="hidden" name="direction" value="up">
                        <button type="submit" class="btn-icon" title="Pakelti aukštyn">↑</button>
                    </form>
                    <?php endif; ?>
                    
                    <?php if (!$isLast): ?>
                    <form method="post" style="margin:0;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="move_menu_item">
                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                        <input type="hidden" name="direction" value="down">
                        <button type="submit" class="btn-icon" title="Nuleisti žemyn">↓</button>
                    </form>
                    <?php endif; ?>
                </div>

                <button class="btn secondary btn-sm" onclick='openMenuModal(<?php echo json_encode($item); ?>)'>Redaguoti</button>
                
                <form method="post" onsubmit="return confirm('Ar tikrai trinti? Jei tai tėvinė kategorija, dings ir vaikai.');" style="margin:0;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="delete_menu_item">
                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-danger">&times;</button>
                </form>
            </div>
        </div>
        <?php
        
        // Kviečiame vaikus
        renderMenuTree($pdo, $tree, $item['id'], $level + 1);
    }
}

// Paruošiame galimų tėvų sąrašą (tik 0 lygio elementai, kad nebūtų per daug gilu)
$possibleParents = $menuTree[0] ?? [];
?>

<style>
    .menu-container {
        display: flex; flex-direction: column; gap: 8px;
    }
    .menu-item-row {
        background: #fff;
        border: 1px solid #e1e3ef;
        border-radius: 12px;
        padding: 12px 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: 0.2s;
        position: relative;
    }
    .menu-item-row:hover {
        border-color: #4f46e5;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
        z-index: 2;
    }
    .menu-handle {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .menu-icon {
        font-size: 20px;
        background: #f8fafc;
        width: 40px; height: 40px;
        display: flex; align-items: center; justify-content: center;
        border-radius: 8px;
    }
    .menu-label {
        font-weight: 700;
        color: #1f2937;
        font-size: 15px;
    }
    .menu-url {
        font-size: 12px;
        color: #6b6b7a;
        font-family: monospace;
        background: #f1f5f9;
        padding: 2px 6px;
        border-radius: 4px;
        width: fit-content;
        margin-top: 2px;
    }
    .menu-actions {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .sort-controls {
        display: flex; gap: 2px; margin-right: 8px; background: #f1f5f9; padding: 2px; border-radius: 6px;
    }
    .btn-icon {
        border: none; background: transparent; cursor: pointer;
        width: 24px; height: 24px; display: flex; align-items: center; justify-content: center;
        border-radius: 4px; font-weight: 700; color: #64748b;
        font-size: 14px;
    }
    .btn-icon:hover { background: #fff; color: #0f172a; box-shadow: 0 1px 2px rgba(0,0,0,0.1); }
    .btn-sm { padding: 6px 12px; font-size: 13px; }
    .btn-danger { background: #fff1f1; color: #ef4444; border-color: #fecaca; padding: 6px 10px; font-size: 16px; line-height: 1; }
    .btn-danger:hover { background: #fee2e2; }

    /* Modal Styles (Reuse) */
    .modal-overlay {
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.5); backdrop-filter: blur(2px); z-index: 1000;
        display: none; align-items: center; justify-content: center;
    }
    .modal-overlay.open { display: flex; }
    .modal-window {
        background: #fff; width: 100%; max-width: 500px;
        border-radius: 16px; padding: 24px; box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 12px; font-weight: 700; color: #6b6b7a; margin-bottom: 6px; text-transform: uppercase; }
</style>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
    <div>
        <h2>Meniu valdymas</h2>
        <p class="muted" style="margin:0;">Tvarkykite svetainės navigaciją, kurkite subkategorijas.</p>
    </div>
    <button class="btn" onclick="openMenuModal()">+ Naujas punktas</button>
</div>

<div class="card">
    <div class="menu-container">
        <?php if (empty($menuTree)): ?>
            <div style="text-align:center; padding:30px; color:#94a3b8;">Meniu tuščias. Pridėkite pirmąjį punktą.</div>
        <?php else: ?>
            <?php renderMenuTree($pdo, $menuTree); ?>
        <?php endif; ?>
    </div>
</div>

<div id="menuModal" class="modal-overlay">
    <div class="modal-window">
        <div style="display:flex; justify-content:space-between; margin-bottom:16px;">
            <h3 style="margin:0;" id="menuModalTitle">Meniu punktas</h3>
            <button onclick="closeMenuModal()" style="border:none; background:none; font-size:24px; cursor:pointer;">&times;</button>
        </div>
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_menu_item">
            <input type="hidden" name="id" id="m_id" value="">
            
            <div class="form-group">
                <label>Pavadinimas</label>
                <input type="text" name="label" id="m_label" required placeholder="pvz. Parduotuvė" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px;">
            </div>

            <div class="form-group">
                <label>Nuoroda (URL)</label>
                <div style="display:flex; gap:8px;">
                    <input type="text" name="url" id="m_url" required placeholder="/products.php" style="flex:1; padding:10px; border:1px solid #ddd; border-radius:8px;">
                    <select onchange="document.getElementById('m_url').value = this.value" style="width:140px; padding:10px; border-radius:8px; border:1px solid #ddd;">
                        <option value="">Paruoštukai...</option>
                        <option value="/products.php">Parduotuvė</option>
                        <option value="/news.php">Naujienos</option>
                        <option value="/recipes.php">Receptai</option>
                        <option value="/community.php">Bendruomenė</option>
                        <option value="/contact.php">Kontaktai</option>
                        <option value="/faq.php">DUK</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Tėvinė kategorija (kuriam punktui priklauso?)</label>
                <select name="parent_id" id="m_parent" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px;">
                    <option value="">-- Pagrindinis lygis --</option>
                    <?php foreach ($possibleParents as $p): ?>
                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="muted" style="font-size:11px; margin-top:4px;">Jei pasirinksite kitą punktą, šis punktas taps jo subkategorija.</p>
            </div>
            
            <div style="text-align:right; margin-top:20px;">
                <button type="button" class="btn secondary" onclick="closeMenuModal()">Atšaukti</button>
                <button type="submit" class="btn">Išsaugoti</button>
            </div>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('menuModal');
    const title = document.getElementById('menuModalTitle');
    
    function openMenuModal(data = null) {
        modal.style.display = 'flex';
        // Animacija
        setTimeout(() => modal.classList.add('open'), 10);
        
        if (data) {
            title.innerText = 'Redaguoti punktą';
            document.getElementById('m_id').value = data.id;
            document.getElementById('m_label').value = data.label;
            document.getElementById('m_url').value = data.url;
            document.getElementById('m_parent').value = data.parent_id || '';
        } else {
            title.innerText = 'Naujas punktas';
            document.getElementById('m_id').value = '';
            document.getElementById('m_label').value = '';
            document.getElementById('m_url').value = '';
            document.getElementById('m_parent').value = '';
        }
    }

    function closeMenuModal() {
        modal.classList.remove('open');
        setTimeout(() => modal.style.display = 'none', 200);
    }
    
    modal.addEventListener('click', e => {
        if(e.target === modal) closeMenuModal();
    });
</script>
