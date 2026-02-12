<?php
// admin/discounts.php

// 1. Veiksmų apdorojimas
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['action'] ?? '';

    // --- GLOBALŪS NUSTATYMAI ---
    if ($action === 'update_global') {
        $type = $_POST['type'] ?? 'none';
        $value = (float)($_POST['value'] ?? 0);
        $freeShipping = isset($_POST['free_shipping']);
        
        saveGlobalDiscount($pdo, $type, $value, $freeShipping);
        $_SESSION['flash_success'] = 'Globalūs nustatymai atnaujinti.';
        header('Location: /admin.php?view=discounts');
        exit;
    }

    // --- KATEGORIJŲ NUOLAIDOS ---
    if ($action === 'save_cat_discount') {
        $catId = (int)$_POST['category_id'];
        $type = $_POST['type'] ?? 'none';
        $value = (float)($_POST['value'] ?? 0);
        $freeShipping = isset($_POST['free_shipping']);
        $active = isset($_POST['active']);

        if ($catId > 0) {
            saveCategoryDiscount($pdo, $catId, $type, $value, $freeShipping, $active);
            $_SESSION['flash_success'] = 'Kategorijos nuolaida išsaugota.';
        }
        header('Location: /admin.php?view=discounts&tab=categories');
        exit;
    }

    if ($action === 'delete_cat_discount') {
        $catId = (int)$_POST['category_id'];
        deleteCategoryDiscount($pdo, $catId);
        $_SESSION['flash_success'] = 'Kategorijos nuolaida pašalinta.';
        header('Location: /admin.php?view=discounts&tab=categories');
        exit;
    }

    // --- NUOLAIDŲ KODAI ---
    if ($action === 'save_code') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $code = trim($_POST['code'] ?? '');
        $type = $_POST['type'] ?? 'percent';
        $value = (float)($_POST['value'] ?? 0);
        $limit = (int)($_POST['usage_limit'] ?? 0);
        $active = isset($_POST['active']);
        $freeShipping = isset($_POST['free_shipping']);

        if ($code) {
            try {
                saveDiscountCodeEntry($pdo, $id, $code, $type, $value, $limit, $active, $freeShipping);
                $_SESSION['flash_success'] = 'Kodas išsaugotas.';
            } catch (Exception $e) {
                $_SESSION['flash_error'] = 'Klaida: Toks kodas galbūt jau egzistuoja.';
            }
        } else {
            $_SESSION['flash_error'] = 'Įveskite kodą.';
        }
        header('Location: /admin.php?view=discounts&tab=codes');
        exit;
    }

    if ($action === 'delete_code') {
        $id = (int)$_POST['id'];
        deleteDiscountCode($pdo, $id);
        $_SESSION['flash_success'] = 'Kodas ištrintas.';
        header('Location: /admin.php?view=discounts&tab=codes');
        exit;
    }
}

// 2. Duomenų gavimas
$globalDiscount = getGlobalDiscount($pdo);
$categoryDiscounts = getCategoryDiscounts($pdo); // Tik aktyvios, bet mums reikės ir redagavimui
// Gauname visas kategorijas
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll();
// Gauname visus kodus
$discountCodes = getAllDiscountCodes($pdo);

// Kadangi getCategoryDiscounts grąžina tik aktyvias arba suformatuotą masyvą, 
// pasidarome pilną sąrašą atvaizdavimui lentelėje.
$catDiscountsRaw = $pdo->query("SELECT * FROM category_discounts")->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);

$currentTab = $_GET['tab'] ?? 'global';
?>

<style>
    .tabs { display: flex; border-bottom: 1px solid #e5e7eb; margin-bottom: 24px; background: #fff; border-radius: 8px 8px 0 0; padding: 0 16px; }
    .tab { padding: 12px 20px; cursor: pointer; font-weight: 600; color: #6b7280; border-bottom: 2px solid transparent; transition: all 0.2s; font-size: 14px; }
    .tab:hover { color: #2563eb; }
    .tab.active { color: #2563eb; border-bottom-color: #2563eb; }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    
    .panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; align-items: start; }
    
    .badge { padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .badge-green { background: #dcfce7; color: #166534; }
    .badge-gray { background: #f3f4f6; color: #374151; }
    
    .code-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
</style>

<h2 style="margin-bottom: 20px;">Nuolaidų valdymas</h2>

<div class="tabs">
    <div class="tab <?php echo $currentTab === 'global' ? 'active' : ''; ?>" onclick="showTab('global')">Globali nuolaida</div>
    <div class="tab <?php echo $currentTab === 'categories' ? 'active' : ''; ?>" onclick="showTab('categories')">Kategorijų nuolaidos</div>
    <div class="tab <?php echo $currentTab === 'codes' ? 'active' : ''; ?>" onclick="showTab('codes')">Nuolaidų kodai</div>
</div>

<div id="tab-global" class="tab-content <?php echo $currentTab === 'global' ? 'active' : ''; ?>">
    <div class="panel" style="max-width: 600px;">
        <h3 style="margin-top:0;">Globalūs nustatymai</h3>
        <p class="muted" style="margin-bottom: 20px;">Ši nuolaida taikoma visam krepšeliui automatiškai.</p>
        
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_global">
            
            <div class="form-group">
                <label>Nuolaidos tipas</label>
                <select name="type" class="form-control" onchange="toggleValInput(this, 'globalValRow')">
                    <option value="none" <?php echo ($globalDiscount['type'] ?? '') === 'none' ? 'selected' : ''; ?>>Nėra</option>
                    <option value="percent" <?php echo ($globalDiscount['type'] ?? '') === 'percent' ? 'selected' : ''; ?>>Procentinė (%)</option>
                    <option value="amount" <?php echo ($globalDiscount['type'] ?? '') === 'amount' ? 'selected' : ''; ?>>Fiksuota suma (€)</option>
                </select>
            </div>
            
            <div class="form-group" id="globalValRow" style="display: <?php echo ($globalDiscount['type'] ?? 'none') === 'none' ? 'none' : 'block'; ?>;">
                <label>Reikšmė</label>
                <input type="number" step="0.01" name="value" class="form-control" value="<?php echo htmlspecialchars($globalDiscount['value'] ?? 0); ?>">
            </div>
            
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="free_shipping" value="1" <?php echo !empty($globalDiscount['free_shipping']) ? 'checked' : ''; ?>>
                    Suteikti nemokamą pristatymą
                </label>
            </div>
            
            <button type="submit" class="btn">Išsaugoti</button>
        </form>
    </div>
</div>

<div id="tab-categories" class="tab-content <?php echo $currentTab === 'categories' ? 'active' : ''; ?>">
    <div class="panel">
        <h3 style="margin-top:0;">Kategorijų nuolaidos</h3>
        <div style="margin-bottom: 20px;">
            <table class="w-full">
                <thead>
                    <tr>
                        <th>Kategorija</th>
                        <th>Nuolaida</th>
                        <th>Statusas</th>
                        <th style="text-align:right;">Veiksmai</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat): 
                        $d = $catDiscountsRaw[$cat['id']] ?? null;
                        $hasDiscount = $d && ($d['type'] !== 'none' || $d['free_shipping']);
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($cat['name']); ?></strong></td>
                        <td>
                            <?php if ($hasDiscount): ?>
                                <?php if ($d['type'] === 'percent'): ?>
                                    -<?php echo (float)$d['value']; ?>%
                                <?php elseif ($d['type'] === 'amount'): ?>
                                    -<?php echo (float)$d['value']; ?> €
                                <?php endif; ?>
                                <?php if ($d['free_shipping']): ?>
                                    <span style="color:#16a34a; font-size:12px;">(+ Nemokamas siuntimas)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($hasDiscount): ?>
                                <span class="badge <?php echo $d['active'] ? 'badge-green' : 'badge-gray'; ?>">
                                    <?php echo $d['active'] ? 'Aktyvi' : 'Neaktyvi'; ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <button class="btn secondary" onclick='editCat(<?php echo json_encode(array_merge($cat, (array)$d)); ?>)'>Redaguoti</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div id="catEditForm" style="display:none; background:#f9fafb; padding:20px; border-radius:12px; margin-top:20px; border:1px dashed #ccc;">
            <h4 style="margin-top:0;">Redaguoti: <span id="catEditName"></span></h4>
            <form method="post">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save_cat_discount">
                <input type="hidden" name="category_id" id="catEditId">
                
                <div class="form-row">
                    <div>
                        <label>Tipas</label>
                        <select name="type" class="form-control" id="catEditType" onchange="toggleValInput(this, 'catValRow')">
                            <option value="none">Nėra</option>
                            <option value="percent">Procentinė (%)</option>
                            <option value="amount">Fiksuota suma (€)</option>
                        </select>
                    </div>
                    <div id="catValRow">
                        <label>Reikšmė</label>
                        <input type="number" step="0.01" name="value" id="catEditValue" class="form-control">
                    </div>
                </div>
                
                <div style="display:flex; gap:20px; margin-bottom:16px;">
                    <label class="checkbox-label">
                        <input type="checkbox" name="free_shipping" id="catEditFree" value="1">
                        Nemokamas pristatymas
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="active" id="catEditActive" value="1">
                        Aktyvuoti nuolaidą
                    </label>
                </div>
                
                <div style="display:flex; justify-content:space-between;">
                    <button type="submit" class="btn">Išsaugoti</button>
                    <button type="submit" name="action" value="delete_cat_discount" class="btn" style="background:#fee2e2; color:#b91c1c; border-color:#fecaca;" onclick="return confirm('Panaikinti nuolaidą?');">Panaikinti</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="tab-codes" class="tab-content <?php echo $currentTab === 'codes' ? 'active' : ''; ?>">
    <div class="shipping-grid">
        <div class="panel">
            <h3 style="margin-top:0;">Aktyvūs kodai</h3>
            <?php if (!$discountCodes): ?>
                <p class="muted">Nėra sukurtų kodų.</p>
            <?php else: ?>
                <?php foreach ($discountCodes as $c): ?>
                    <div class="code-card">
                        <div>
                            <div style="font-weight:700; color:#2563eb; font-family:monospace; font-size:16px;"><?php echo htmlspecialchars($c['code']); ?></div>
                            <div style="font-size:13px; color:#666;">
                                <?php 
                                    if ($c['type'] === 'percent') echo "-" . (float)$c['value'] . "%";
                                    elseif ($c['type'] === 'amount') echo "-" . (float)$c['value'] . " €";
                                    
                                    if ($c['free_shipping']) echo " + 🚚";
                                    if (!$c['active']) echo " (Neaktyvus)";
                                ?>
                                <span style="margin-left:8px; background:#e0e7ff; padding:1px 6px; border-radius:4px; font-size:11px;">
                                    Panaudota: <?php echo (int)$c['used_count']; ?><?php echo $c['usage_limit'] > 0 ? '/' . $c['usage_limit'] : ''; ?>
                                </span>
                            </div>
                        </div>
                        <div style="display:flex; gap:5px;">
                            <button type="button" class="btn secondary" style="padding:4px 8px; font-size:12px;" onclick='editCode(<?php echo json_encode($c); ?>)'>✏️</button>
                            <form method="post" style="margin:0;" onsubmit="return confirm('Ištrinti kodą?');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete_code">
                                <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                <button type="submit" class="btn secondary" style="padding:4px 8px; font-size:12px; color:#ef4444;">&times;</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="panel">
            <h3 style="margin-top:0;" id="codeFormTitle">Naujas kodas</h3>
            <form method="post" id="codeForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save_code">
                <input type="hidden" name="id" id="codeId">
                
                <div class="form-group">
                    <label>Kodas</label>
                    <input type="text" name="code" id="codeCode" class="form-control" placeholder="pvz. VASARA2024" required>
                </div>
                
                <div class="form-row">
                    <div>
                        <label>Tipas</label>
                        <select name="type" id="codeType" class="form-control">
                            <option value="percent">Procentinė (%)</option>
                            <option value="amount">Fiksuota suma (€)</option>
                        </select>
                    </div>
                    <div>
                        <label>Reikšmė</label>
                        <input type="number" step="0.01" name="value" id="codeValue" class="form-control">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Panaudojimo limitas (0 = neribota)</label>
                    <input type="number" name="usage_limit" id="codeLimit" class="form-control" value="0">
                </div>
                
                <div style="display:flex; flex-direction:column; gap:8px; margin-bottom:16px;">
                    <label class="checkbox-label">
                        <input type="checkbox" name="free_shipping" id="codeFree" value="1">
                        Suteikia nemokamą pristatymą
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="active" id="codeActive" value="1" checked>
                        Kodas aktyvus
                    </label>
                </div>
                
                <div style="display:flex; justify-content:space-between;">
                    <button type="submit" class="btn" style="width:100%;">Išsaugoti kodą</button>
                    <button type="button" class="btn secondary" id="codeCancel" style="display:none; margin-left:10px;" onclick="resetCodeForm()">Atšaukti</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function showTab(name) {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        
        const selectedTab = Array.from(document.querySelectorAll('.tab')).find(t => t.innerText.toLowerCase().includes(name === 'global' ? 'global' : (name === 'codes' ? 'kodai' : 'kategor')));
        if(selectedTab) selectedTab.classList.add('active'); // fallback selection logic if needed
        // Simpler: just match indexes if strict, but here using class toggle manually
        // Let's rely on the onclick updating classes correctly if we grab by index or ID logic.
        // Actually, just simpler to reload page with GET param for persistent tabs in PHP, but for JS tab switching:
        
        // Reset classes
        const tabs = document.querySelectorAll('.tab');
        const contents = document.querySelectorAll('.tab-content');
        tabs[0].classList.toggle('active', name === 'global');
        tabs[1].classList.toggle('active', name === 'categories');
        tabs[2].classList.toggle('active', name === 'codes');
        
        document.getElementById('tab-global').classList.toggle('active', name === 'global');
        document.getElementById('tab-categories').classList.toggle('active', name === 'categories');
        document.getElementById('tab-codes').classList.toggle('active', name === 'codes');
    }

    function toggleValInput(select, rowId) {
        const row = document.getElementById(rowId);
        if (select.value === 'none') {
            row.style.display = 'none';
        } else {
            row.style.display = 'block';
        }
    }

    function editCat(data) {
        document.getElementById('catEditForm').style.display = 'block';
        document.getElementById('catEditName').innerText = data.name;
        document.getElementById('catEditId').value = data.id; // data.id is cat ID
        
        document.getElementById('catEditType').value = data.type || 'none';
        document.getElementById('catEditValue').value = data.value || '';
        document.getElementById('catEditFree').checked = (data.free_shipping == 1);
        document.getElementById('catEditActive').checked = (data.active == 1);
        
        toggleValInput(document.getElementById('catEditType'), 'catValRow');
        document.getElementById('catEditForm').scrollIntoView({behavior: 'smooth'});
    }

    function editCode(data) {
        document.getElementById('codeFormTitle').innerText = 'Redaguoti kodą';
        document.getElementById('codeId').value = data.id;
        document.getElementById('codeCode').value = data.code;
        document.getElementById('codeType').value = data.type;
        document.getElementById('codeValue').value = data.value;
        document.getElementById('codeLimit').value = data.usage_limit;
        document.getElementById('codeFree').checked = (data.free_shipping == 1);
        document.getElementById('codeActive').checked = (data.active == 1);
        document.getElementById('codeCancel').style.display = 'inline-block';
    }

    function resetCodeForm() {
        document.getElementById('codeForm').reset();
        document.getElementById('codeId').value = '';
        document.getElementById('codeFormTitle').innerText = 'Naujas kodas';
        document.getElementById('codeCancel').style.display = 'none';
    }
</script>
