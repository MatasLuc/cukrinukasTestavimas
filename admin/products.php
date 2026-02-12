<?php
// admin/products.php

// Rodyti sesijos pranešimus
if (isset($_SESSION['flash_success'])) {
    echo '<div class="alert success" style="margin-bottom:10px;">&check; '.htmlspecialchars($_SESSION['flash_success']).'</div>';
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    echo '<div class="alert error" style="margin-bottom:10px;">&times; '.htmlspecialchars($_SESSION['flash_error']).'</div>';
    unset($_SESSION['flash_error']);
}

// 2. DUOMENŲ SURINKIMAS

// --- PUSLAPIAVIMO IR PAIEŠKOS LOGIKA ---
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Paieškos kintamieji
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$searchParams = [];
$whereSQL = "";

if ($search) {
    $whereSQL = " WHERE p.title LIKE :search OR p.ribbon_text LIKE :search ";
    $searchParams[':search'] = "%$search%";
}

// Skaičiuojame bendrą kiekį (su filtru)
$countSql = "SELECT COUNT(*) FROM products p $whereSQL";
$countStmt = $pdo->prepare($countSql);
if($search) { $countStmt->execute($searchParams); } else { $countStmt->execute(); }
$totalItems = $countStmt->fetchColumn();
$totalPages = ceil($totalItems / $perPage);

// Featured prekės
$fProds = $pdo->query('
    SELECT p.*, fp.id as fp_id, fp.position
    FROM featured_products fp
    JOIN products p ON fp.product_id = p.id 
    ORDER BY fp.position ASC
')->fetchAll(PDO::FETCH_ASSOC);

// Pagrindinė prekių užklausa
$sql = "
    SELECT p.*, c.name AS category_name,
           (SELECT path FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as primary_image,
           (SELECT COUNT(*) FROM featured_products WHERE product_id = p.id) as is_featured_flag
    FROM products p 
    LEFT JOIN categories c ON c.id = p.category_id 
    $whereSQL
    ORDER BY p.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
if($search) {
    foreach($searchParams as $k => $v) $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Papildomi duomenys redagavimui (tik rodomoms prekėms)
foreach ($products as &$p) {
    $attrsStmt = $pdo->prepare("SELECT label, value FROM product_attributes WHERE product_id = ?");
    $attrsStmt->execute([$p['id']]);
    $p['attributes'] = $attrsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Variacijos (su track_price ir track_stock)
    $varsStmt = $pdo->prepare("SELECT * FROM product_variations WHERE product_id = ? ORDER BY id ASC");
    $varsStmt->execute([$p['id']]);
    $p['variations'] = $varsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $catsStmt = $pdo->prepare("SELECT category_id FROM product_category_relations WHERE product_id = ?");
    $catsStmt->execute([$p['id']]);
    $p['category_ids'] = $catsStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $imgsStmt = $pdo->prepare("SELECT id, path, is_primary FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id ASC");
    $imgsStmt->execute([$p['id']]);
    $p['all_images'] = $imgsStmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($p);

// Kategorijų medis
$allCats = $pdo->query('SELECT * FROM categories ORDER BY parent_id ASC, name ASC')->fetchAll();
$catTree = [];
foreach ($allCats as $c) {
    if (empty($c['parent_id'])) { $catTree[$c['id']]['self']=$c; $catTree[$c['id']]['children']=[]; }
}
foreach ($allCats as $c) {
    if (!empty($c['parent_id']) && isset($catTree[$c['parent_id']])) { $catTree[$c['parent_id']]['children'][]=$c; }
}
?>

<style>
    /* Tabai */
    .product-tabs { display: flex; background: #fff; border-bottom: 1px solid #eee; position: sticky; top: 0; z-index: 10; padding: 0 24px; }
    .tab-btn { padding: 16px 20px; background: transparent; border: none; border-bottom: 2px solid transparent; font-weight: 600; color: #6b7280; cursor: pointer; transition: 0.2s; font-size: 14px; }
    .tab-btn:hover { color: #4f46e5; background: #f9fafb; }
    .tab-btn.active { color: #4f46e5; border-bottom-color: #4f46e5; }
    .tab-content { display: none; padding: 24px; }
    .tab-content.active { display: block; animation: slideUp 0.3s ease; }
    
    /* Formos */
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
    .full-width { grid-column: span 2; }
    .input-group { margin-bottom: 15px; }
    .input-group label { display: block; font-size: 12px; font-weight: 700; text-transform: uppercase; color: #6b7280; margin-bottom: 6px; }
    .form-control { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
    
    /* Image Manager */
    .img-manager-item { position: relative; width: 100px; height: 120px; border: 1px solid #eee; border-radius: 6px; padding: 4px; display:flex; flex-direction:column; align-items:center; }
    .img-manager-item img { width: 100%; height: 80px; object-fit: cover; border-radius: 4px; }
    .img-actions { margin-top: 5px; display: flex; justify-content: space-between; width: 100%; align-items: center; font-size: 11px; }
    .star-btn { cursor: pointer; color: #ccc; font-size: 16px; border: none; background: none; }
    .star-btn.active { color: #f59e0b; }
    .del-btn { cursor: pointer; color: #ef4444; border: none; background: none; font-weight: bold; }

    /* Variacijų grupės */
    .var-group { border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 20px; background: #f9fafb; overflow: hidden; }
    .var-group-header { background: #f3f4f6; padding: 10px 15px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e5e7eb; }
    .var-group-body { padding: 15px; }
    .var-row { display: flex; gap: 10px; margin-bottom: 8px; align-items: flex-start; }
    .var-row input { margin: 0; }
    
    /* CUSTOM IMAGE SELECTOR */
    .cis-wrapper { position: relative; width: 100%; }
    .cis-trigger { 
        border: 1px solid #d1d5db; border-radius: 6px; padding: 4px 8px; 
        min-height: 38px; display: flex; align-items: center; justify-content: flex-start; 
        cursor: pointer; background: #fff; gap: 8px;
    }
    .cis-trigger:hover { border-color: #9ca3af; background: #f9fafb; }
    .cis-trigger img { width: 32px; height: 32px; object-fit: cover; border-radius: 4px; border: 1px solid #eee; }
    .cis-trigger span { font-size: 13px; color: #4b5563; }
    
    .cis-dropdown {
        display: none; position: absolute; top: 100%; left: 0; right: 0; margin-top: 4px;
        background: #fff; border: 1px solid #d1d5db; border-radius: 6px; 
        box-shadow: 0 4px 10px rgba(0,0,0,0.1); z-index: 100; 
        padding: 8px; max-height: 250px; overflow-y: auto; 
        grid-template-columns: repeat(auto-fill, minmax(45px, 1fr)); gap: 6px;
    }
    .cis-dropdown.open { display: grid; }
    .cis-item { 
        cursor: pointer; border: 2px solid transparent; border-radius: 6px; overflow: hidden; height: 45px; position: relative;
    }
    .cis-item:hover { border-color: #6366f1; opacity: 0.9; }
    .cis-item.selected { border-color: #4f46e5; box-shadow: 0 0 0 2px #e0e7ff; }
    .cis-item img { width: 100%; height: 100%; object-fit: cover; }
    
    .cis-remove { 
        grid-column: 1 / -1; text-align: center; font-size: 12px; color: #ef4444; font-weight: 500;
        padding: 6px; cursor: pointer; border-top: 1px solid #eee; margin-top: 4px; 
    }
    .cis-remove:hover { background: #fef2f2; }

    /* Kiti stiliai */
    .cat-box { border: 1px solid #d1d5db; border-radius: 6px; padding: 10px; max-height: 200px; overflow-y: auto; background: #fff; }
    .cat-item { display: block; margin-bottom: 6px; cursor: pointer; font-size: 14px; }
    .cat-child { margin-left: 20px; border-left: 2px solid #eee; padding-left: 8px; }
    .rich-editor-wrapper { border: 1px solid #d1d5db; border-radius: 6px; overflow: hidden; background: #fff; }
    .editor-toolbar { background: #f3f4f6; border-bottom: 1px solid #d1d5db; padding: 6px; display: flex; gap: 4px; flex-wrap: wrap; }
    
    .editor-btn { border: 1px solid transparent; background: transparent; cursor: pointer; padding: 4px 6px; border-radius: 4px; font-size: 14px; color: #374151; font-weight: 500; }
    .editor-btn:hover { background: #e5e7eb; border-color: #d1d5db; }
    
    .editor-content { min-height: 150px; padding: 12px; outline: none; overflow-y: auto; font-size: 14px; line-height: 1.5; }
    .editor-content ul, .editor-content ol { padding-left: 20px; margin-left: 10px; list-style-position: inside; }
    .editor-content ul { list-style-type: disc; }
    .editor-content ol { list-style-type: decimal; }
    
    .mini-editor .editor-content { min-height: 60px; }
    .attr-row { display: grid; grid-template-columns: 200px 1fr 40px; gap: 10px; margin-bottom: 12px; align-items: start; background: #fdfdfd; padding: 10px; border: 1px solid #eee; border-radius: 6px; }
    .bulk-actions { display: none; align-items: center; gap: 10px; background: #eff6ff; padding: 8px 16px; border-radius: 8px; border: 1px solid #dbeafe; margin-left: 16px; }
    .bulk-actions.visible { display: flex; }
    .product-thumb { width: 40px; height: 40px; border-radius: 4px; object-fit: cover; background: #eee; }
    .stock-badge { padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; }
    .in-stock { background: #dcfce7; color: #166534; }
    .out-of-stock { background: #fee2e2; color: #991b1b; }
    
    .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; }
    .page-link { padding: 8px 12px; border: 1px solid #d1d5db; background: #fff; color: #374151; border-radius: 6px; text-decoration: none; font-size: 14px; }
    .page-link:hover { background: #f3f4f6; }
    .page-link.active { background: #4f46e5; color: #fff; border-color: #4f46e5; }
    
    .new-product-section { margin-top: 40px; border-top: 2px solid #e5e7eb; padding-top: 30px; background: #fff; border-radius: 8px; border:1px solid #e5e7eb; scroll-margin-top: 20px; }
    .new-product-header { padding: 15px 24px; border-bottom: 1px solid #eee; background: #fcfcfc; border-radius: 8px 8px 0 0; display:flex; justify-content:space-between; align-items:center;}
    
    @keyframes slideUp { from {opacity:0; transform:translateY(10px)} to {opacity:1; transform:translateY(0)} }
</style>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <div style="display:flex; align-items:center;">
        <div>
            <h2>Prekių valdymas</h2>
            <p class="muted" style="margin:0;">
                Viso prekių: <?php echo $totalItems; ?> 
                <?php if($search): ?>(Filtruota pagal "<?php echo htmlspecialchars($search); ?>")<?php endif; ?>
            </p>
        </div>
        <div id="bulkActionsPanel" class="bulk-actions">
            <span style="font-weight:600; font-size:13px; color:#1d4ed8;">Pasirinkta: <span id="selectedCount">0</span></span>
            <button type="button" class="btn" style="background:#ef4444; border-color:#ef4444; padding:6px 12px; font-size:12px;" onclick="submitBulkDelete()">Ištrinti pasirinktus</button>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:20px; border:1px dashed #4f46e5; background:#f5f6ff;">
    <h4 style="margin-top:0; font-size:14px; text-transform:uppercase; color:#4338ca;">Pagrindinio puslapio prekės (Featured)</h4>
    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
        <?php foreach ($fProds as $fp): ?>
            <div style="background:#fff; border:1px solid #c7d2fe; padding:6px 12px; border-radius:20px; font-size:13px; display:flex; align-items:center; gap:8px; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                <span style="font-weight:600; color:#3730a3;"><?php echo htmlspecialchars($fp['title']); ?></span>
                <form method="post" action="/admin.php?view=products" style="margin:0;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="toggle_featured">
                    <input type="hidden" name="product_id" value="<?php echo $fp['id']; ?>">
                    <input type="hidden" name="set_featured" value="0">
                    <button type="submit" style="border:none; background:none; color:#ef4444; font-weight:bold; cursor:pointer; font-size:16px; line-height:1;" title="Pašalinti iš titulinio">&times;</button>
                </form>
            </div>
        <?php endforeach; ?>
        
        <form method="post" action="/admin.php?view=products" style="display:flex; gap:6px;">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="add_featured_by_name">
            <input name="featured_title" list="prodList" placeholder="Įveskite prekės pavadinimą..." class="form-control" style="width:250px; padding:6px 10px; font-size:13px; background:#fff;" required autocomplete="off">
            <datalist id="prodList">
                <?php foreach($products as $p) echo "<option value='".htmlspecialchars($p['title'])."'>"; ?>
            </datalist>
            <button class="btn secondary" style="padding:6px 12px; font-size:13px;">Pridėti</button>
        </form>
    </div>
</div>

<form id="productsListForm" method="post" action="/admin.php?view=products">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="bulk_delete_products">
    
    <div class="card" style="padding:0; overflow:hidden;">
        <div style="padding:15px; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0;">Prekių sąrašas</h3>
            <div style="display:flex; gap:5px;">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Paieška..." class="form-control" style="width:200px; padding:6px 10px;" onkeydown="if(event.key==='Enter'){event.preventDefault(); window.location.href='admin.php?view=products&search='+this.value;}">
                <button type="button" class="btn secondary" onclick="window.location.href='admin.php?view=products&search='+document.querySelector('input[name=search]').value">Ieškoti</button>
                <?php if($search): ?>
                    <button type="button" class="btn secondary" style="color:red;" onclick="window.location.href='admin.php?view=products'">&times;</button>
                <?php endif; ?>
            </div>
        </div>

        <table id="productsTable">
            <thead>
                <tr style="background:#f9fafb; font-size:12px; text-transform:uppercase; color:#6b7280;">
                    <th class="checkbox-col"><input type="checkbox" onchange="toggleAll(this)"></th>
                    <th style="width:60px; padding-left:10px;">Foto</th>
                    <th>Pavadinimas</th>
                    <th>Kategorija</th>
                    <th>Kaina</th>
                    <th>Likutis</th>
                    <th style="text-align:right; padding-right:20px;">Veiksmai</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($products)): ?>
                    <tr><td colspan="7" style="padding:20px; text-align:center; color:#999;">Prekių nerasta.</td></tr>
                <?php endif; ?>

                <?php foreach ($products as $p): ?>
                    <tr style="border-bottom:1px solid #eee;">
                        <td class="checkbox-col"><input type="checkbox" name="selected_ids[]" value="<?php echo $p['id']; ?>" class="prod-check" onchange="updateBulkUI()"></td>
                        <td style="padding:10px 0 10px 10px;">
                            <?php $imgSrc = $p['primary_image'] ?: ($p['image_url'] ?: 'https://placehold.co/100'); ?>
                            <img src="<?php echo htmlspecialchars($imgSrc); ?>" class="product-thumb" alt="">
                        </td>
                        <td>
                            <div style="font-weight:600; font-size:14px; color:#111;"><?php echo htmlspecialchars($p['title']); ?></div>
                            <?php if(isset($p['is_featured_flag']) && $p['is_featured_flag'] > 0): ?><span style="font-size:10px; color:#4f46e5; font-weight:700;">[Titulinio]</span><?php endif; ?>
                            <?php if($p['sale_price']): ?><span style="font-size:10px; color:#ef4444; font-weight:700;">[Akcija]</span><?php endif; ?>
                        </td>
                        <td style="font-size:13px; color:#666;"><?php echo htmlspecialchars($p['category_name'] ?? '-'); ?></td>
                        <td>
                            <?php if($p['sale_price']): ?>
                                <div style="color:#ef4444; font-weight:700;"><?php echo number_format($p['sale_price'], 2); ?> €</div>
                                <div style="text-decoration:line-through; font-size:11px; color:#999;"><?php echo number_format($p['price'], 2); ?> €</div>
                            <?php else: ?>
                                <div style="font-weight:600;"><?php echo number_format($p['price'], 2); ?> €</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($p['quantity'] > 0): ?>
                                <span class="stock-badge in-stock"><?php echo $p['quantity']; ?> vnt.</span>
                            <?php else: ?>
                                <span class="stock-badge out-of-stock">0 vnt.</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right; padding-right:20px;">
                            <button type="button" class="btn secondary" style="padding:4px 10px; font-size:12px;" 
                                onclick='editProduct(<?php echo htmlspecialchars(json_encode($p, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP), ENT_QUOTES, "UTF-8"); ?>)'>
                                Redaguoti
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</form>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if($page > 1): ?>
        <a href="?view=products&page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>" class="page-link">&laquo;</a>
    <?php endif; ?>
    <?php 
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    if($start > 1) echo '<span style="padding:8px;">...</span>';
    for($i=$start; $i<=$end; $i++): ?>
        <a href="?view=products&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>" class="page-link <?php echo ($i == $page) ? 'active' : ''; ?>"><?php echo $i; ?></a>
    <?php endfor; ?>
    <?php if($end < $totalPages) echo '<span style="padding:8px;">...</span>'; ?>
    <?php if($page < $totalPages): ?>
        <a href="?view=products&page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>" class="page-link">&raquo;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="new-product-section" id="productFormSection">
    <div class="new-product-header">
        <div>
            <h3 style="margin:0; color:#4f46e5;" id="formTitle">+ Pridėti naują prekę</h3>
            <p class="muted" style="margin:5px 0 0 0; font-size:12px;">Užpildykite pagrindinę informaciją ir įkelkite nuotraukas.</p>
        </div>
        <button type="button" class="btn secondary" id="cancelEditBtn" onclick="resetForm()" style="display:none;">Atšaukti redagavimą</button>
    </div>

    <form method="post" enctype="multipart/form-data" action="/admin.php?view=products" id="mainProductForm" onsubmit="return syncEditors()">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save_product">
        <input type="hidden" name="id" id="productId" value="">

        <div class="product-tabs" style="border-top:1px solid #eee;">
            <button type="button" class="tab-btn active" onclick="switchTab('basic')">Pagrindinė info</button>
            <button type="button" class="tab-btn" onclick="switchTab('specs')">Specifikacijos ir Variacijos</button>
            <button type="button" class="tab-btn" onclick="switchTab('seo')">SEO</button>
        </div>

        <div style="padding:24px; background:#fff; border-radius:0 0 8px 8px;">
            <div id="tab-basic" class="tab-content active" style="padding:0;">
                <div class="form-grid">
                    <div class="full-width input-group">
                        <label>Prekės pavadinimas *</label>
                        <input name="title" id="p_title" class="form-control" required placeholder="pvz. Gliukometras X">
                    </div>
                    
                    <div class="full-width input-group">
                        <label>Paantraštė</label>
                        <input name="subtitle" id="p_subtitle" class="form-control">
                    </div>

                    <div class="input-group">
                        <label>Kaina (€) *</label>
                        <input type="number" step="0.01" name="price" id="p_price" class="form-control" required>
                    </div>
                    <div class="input-group">
                        <label>Akcijos kaina (€)</label>
                        <input type="number" step="0.01" name="sale_price" id="p_sale_price" class="form-control">
                    </div>
                    <div class="full-width input-group">
                        <label>Bendras likutis (Jei nenaudojamos variacijos) *</label>
                        <input type="number" name="quantity" id="p_quantity" class="form-control" value="0" required>
                    </div>

                    <div class="full-width input-group">
                        <label>Išsamus aprašymas</label>
                        <div class="rich-editor-wrapper">
                            <div class="editor-toolbar" id="descToolbar"></div>
                            <div id="descEditor" class="editor-content" contenteditable="true"></div>
                            <textarea name="description" id="p_description" hidden></textarea>
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Kategorijos (galima kelias)</label>
                        <div class="cat-box">
                            <?php foreach ($catTree as $branch): ?>
                                <label class="cat-item" style="font-weight:700;">
                                    <input type="checkbox" name="categories[]" value="<?php echo $branch['self']['id']; ?>" class="cat-check">
                                    <?php echo htmlspecialchars($branch['self']['name']); ?>
                                </label>
                                <?php foreach ($branch['children'] as $child): ?>
                                    <label class="cat-item cat-child">
                                        <input type="checkbox" name="categories[]" value="<?php echo $child['id']; ?>" class="cat-check">
                                        <?php echo htmlspecialchars($child['name']); ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Etiketė (Ribbon)</label>
                        <input name="ribbon_text" id="p_ribbon" class="form-control" placeholder="pvz. Naujiena">
                    </div>
                    <div class="full-width input-group">
                        <label style="display:flex; align-items:center; gap:8px;">
                            <input type="checkbox" name="is_featured" id="p_featured" value="1">
                            Rodyti pagrindiniame puslapyje (Featured)
                        </label>
                    </div>

                    <div class="full-width" style="margin-top:20px; border-top:1px dashed #eee; padding-top:20px;">
                        <label style="font-size:14px; margin-bottom:10px; display:block;">Prekės nuotraukos</label>
                        
                        <div class="input-group">
                            <label>Įkelti naujas nuotraukas</label>
                            <input type="file" name="images[]" multiple accept="image/*" class="form-control" onchange="previewImages(this)">
                        </div>
                        
                        <div id="imgPreview" style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;"></div>
                        
                        <div id="existingImages" style="margin-top:20px; display:none;">
                            <label>Esamos nuotraukos (Žvaigždutė = Pagrindinė)</label>
                            <div id="existingImgContainer" style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;"></div>
                        </div>
                    </div>
                    </div>
            </div>

            <div id="tab-specs" class="tab-content" style="padding:0;">
                
                <div style="margin-bottom:30px; border-bottom:1px dashed #eee; padding-bottom:20px;">
                    <div style="margin-bottom:15px;">
                        <label style="font-size:16px; color:#111;">Variacijos (Grupavimas)</label>
                        <p class="muted" style="font-size:12px;">Sukurkite grupes (pvz. "Dydis") ir pridėkite joms reikšmes. Galite pasirinkti, ar variacija keičia kainą ir ar sekamas jos likutis.</p>
                    </div>

                    <div id="variationsWrapper"></div>
                    
                    <button type="button" class="btn secondary" onclick="addVariationGroup()" style="margin-top:10px;">+ Pridėti variacijų grupę</button>
                </div>

                <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                    <label>Techninės savybės (Atributai)</label>
                    <button type="button" class="btn secondary" style="font-size:12px;" onclick="addRichAttrRow()">+ Eilutė</button>
                </div>
                <div id="attributesContainer"></div>
            </div>

            <div id="tab-seo" class="tab-content" style="padding:0;">
                <div class="input-group">
                    <label>Meta žymos (Tags)</label>
                    <input name="meta_tags" id="p_meta_tags" class="form-control">
                </div>
                <div class="input-group">
                    <label>Susijusios prekės</label>
                    <select name="related_products[]" id="p_related" multiple class="form-control" style="height:150px;">
                        <?php foreach ($products as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div style="margin-top:20px; text-align:right;">
                <button type="submit" class="btn" style="padding:12px 24px; font-size:16px;" id="submitBtn">Sukurti prekę</button>
            </div>
        </div>
    </form>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if(window.createToolbar) {
            window.createToolbar('descToolbar');
        }
        addRichAttrRow();
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if(!e.target.closest('.cis-wrapper')) {
                document.querySelectorAll('.cis-dropdown').forEach(d => d.classList.remove('open'));
            }
        });
    });

    // --- SHARED HELPERS ---
    window.createToolbar = function(containerId) {
        const c = document.getElementById(containerId);
        if(!c) return;
        const tools = [ {c:'bold',l:'B'}, {c:'italic',l:'I'}, {c:'insertUnorderedList',l:'• List'}, {c:'createLink',l:'🔗'} ];
        let h=''; 
        tools.forEach(t=>{ 
            let md = 'onmousedown="event.preventDefault();"';
            if(t.c=='createLink') h+=`<button type="button" class="editor-btn" ${md} onclick="let u=prompt('URL:');if(u)document.execCommand('${t.c}',false,u)">${t.l}</button>`;
            else h+=`<button type="button" class="editor-btn" ${md} onclick="document.execCommand('${t.c}',false,null)">${t.l}</button>`; 
        });
        c.innerHTML = h;
    }

    window.addRichAttrRow = function(label='', val='') {
        const c = document.getElementById('attributesContainer');
        if(!c) return;
        const uid = 'ae_'+Date.now()+Math.random().toString(36).substr(2,9);
        const d = document.createElement('div');
        d.className = 'attr-row';
        const safeLabel = (label || '').replace(/"/g, '&quot;');
        
        d.innerHTML = `
            <input name="attr_label[]" class="form-control" placeholder="Savybė" value="${safeLabel}">
            <div class="rich-editor-wrapper mini-editor"><div class="editor-toolbar" id="tb_${uid}"></div><div class="editor-content" id="${uid}" contenteditable="true">${val}</div><textarea name="attr_value[]" hidden></textarea></div>
            <button type="button" onclick="this.parentElement.remove()" style="color:red; border:none; cursor:pointer; background:none;">&times;</button>
        `;
        c.appendChild(d);
        if(window.createToolbar) window.createToolbar('tb_'+uid);
    }

    // Globalus kintamasis saugoti esamoms nuotraukoms redagavimo metu
    window.currentProductAllImages = [];

    // --- VARIATIONS LOGIC (GROUPED) ---
    window.addVariationGroup = function(groupName = '', items = []) {
        const wrapper = document.getElementById('variationsWrapper');
        const groupId = Date.now() + Math.floor(Math.random()*1000);
        
        const groupDiv = document.createElement('div');
        groupDiv.className = 'var-group';
        groupDiv.dataset.id = groupId;
        
        groupDiv.innerHTML = `
            <div class="var-group-header">
                <input type="text" name="variations[${groupId}][group_name]" class="form-control" placeholder="Grupės pavadinimas (pvz. Dydis)" value="${groupName.replace(/"/g, '&quot;')}" style="width:70%; font-weight:bold;">
                <button type="button" class="del-btn" onclick="this.closest('.var-group').remove()">Ištrinti grupę</button>
            </div>
            <div class="var-group-body" id="group_body_${groupId}">
            </div>
            <div style="padding:0 15px 15px 15px; text-align:right;">
                <button type="button" class="btn secondary" style="font-size:11px;" onclick="addVariationRow('${groupId}')">+ Pridėti reikšmę</button>
            </div>
        `;
        wrapper.appendChild(groupDiv);

        if(items && items.length > 0) {
            items.forEach(item => addVariationRow(groupId, item.name, item.price_delta, item.quantity, item.image_id, item.track_price, item.track_stock));
        } else {
            addVariationRow(groupId);
        }
    }
    
    // ATNAUJINTA FUNKCIJA SU CHECKBOXAIS IR LOGIKA
    window.addVariationRow = function(groupId, name='', price='', qty='', imageId='', trackPrice=0, trackStock=0) {
        const body = document.getElementById('group_body_' + groupId);
        const rowId = Date.now() + Math.floor(Math.random()*1000);
        
        // Paveikslėlių logika
        let currentImgSrc = '';
        if (imageId && window.currentProductAllImages) {
            const found = window.currentProductAllImages.find(i => i.id == imageId);
            if(found) currentImgSrc = found.path;
        }

        let triggerHtml = currentImgSrc 
            ? `<img src="${currentImgSrc}"><span>Pakeisti</span>` 
            : `<span>Pasirinkti foto</span>`;

        let dropdownItems = '';
        if (window.currentProductAllImages && window.currentProductAllImages.length > 0) {
            window.currentProductAllImages.forEach(img => {
                const isSel = (img.id == imageId) ? 'selected' : '';
                dropdownItems += `
                    <div class="cis-item ${isSel}" onclick="selectCisImage(this, '${img.id}', '${img.path}')" title="Pasirinkti">
                        <img src="${img.path}">
                    </div>
                `;
            });
            dropdownItems += `<div class="cis-remove" onclick="selectCisImage(this, '', '')">Pašalinti nuotrauką</div>`;
        } else {
            dropdownItems = `<div style="padding:10px; font-size:11px; color:#999; text-align:center; grid-column:1/-1;">Nėra įkeltų nuotraukų.</div>`;
        }

        // Būsenos (checkbox ir input disabled)
        const priceChecked = (trackPrice == 1) ? 'checked' : '';
        const priceDisabled = (trackPrice == 1) ? '' : 'disabled';
        const priceStyle = (trackPrice == 1) ? '' : 'opacity:0.5;';
        
        const qtyChecked = (trackStock == 1) ? 'checked' : '';
        const qtyDisabled = (trackStock == 1) ? '' : 'disabled';
        const qtyStyle = (trackStock == 1) ? '' : 'opacity:0.5;';

        const row = document.createElement('div');
        row.className = 'var-row';
        row.style.alignItems = "flex-start"; // Kad checkboxai gražiai lygiuotųsi
        row.innerHTML = `
            <div style="flex:2;">
                <label style="font-size:10px; color:#999; text-transform:uppercase; display:block; margin-bottom:2px;">Reikšmė</label>
                <input type="text" name="variations[${groupId}][items][${rowId}][name]" class="form-control" placeholder="Pvz. Raudona" value="${(name||'').replace(/"/g, '&quot;')}" required>
            </div>
            
            <div style="flex:1;">
                 <label style="font-size:10px; color:#999; text-transform:uppercase; display:flex; align-items:center; gap:4px; margin-bottom:2px; cursor:pointer;">
                    <input type="checkbox" name="variations[${groupId}][items][${rowId}][track_price]" value="1" ${priceChecked} 
                           onchange="toggleInput(this)">
                    Keisti kainą?
                 </label>
                <input type="number" step="0.01" name="variations[${groupId}][items][${rowId}][price]" class="form-control" placeholder="+/- €" value="${price}" ${priceDisabled} style="${priceStyle}">
            </div>
            
             <div style="flex:1;">
                <label style="font-size:10px; color:#999; text-transform:uppercase; display:flex; align-items:center; gap:4px; margin-bottom:2px; cursor:pointer;">
                    <input type="checkbox" name="variations[${groupId}][items][${rowId}][track_stock]" value="1" ${qtyChecked}
                           onchange="toggleInput(this)">
                    Sekti likutį?
                 </label>
                <input type="number" name="variations[${groupId}][items][${rowId}][qty]" class="form-control" placeholder="Vnt." value="${qty}" ${qtyDisabled} style="${qtyStyle}">
            </div>
            
            <div style="flex:1.5;">
                 <label style="font-size:10px; color:#999; text-transform:uppercase; display:block; margin-bottom:2px;">Nuotrauka</label>
                 <div class="cis-wrapper">
                    <input type="hidden" name="variations[${groupId}][items][${rowId}][image_id]" class="cis-input" value="${imageId}">
                    <div class="cis-trigger" onclick="toggleCisDropdown(this)">${triggerHtml}</div>
                    <div class="cis-dropdown">
                        ${dropdownItems}
                    </div>
                 </div>
            </div>
            <button type="button" class="del-btn" style="margin-top:20px;" onclick="this.parentElement.remove()">&times;</button>
        `;
        body.appendChild(row);
    }
    
    // Pagalbinė funkcija inputų įjungimui/išjungimui
    window.toggleInput = function(checkbox) {
        const input = checkbox.closest('div').querySelector('input[type="number"]');
        if(checkbox.checked) {
            input.disabled = false;
            input.style.opacity = '1';
            if(input.value === '') input.value = '0'; 
        } else {
            input.disabled = true;
            input.style.opacity = '0.5';
            input.value = ''; 
        }
    }

    // --- CUSTOM IMAGE DROPDOWN LOGIC ---
    window.toggleCisDropdown = function(trigger) {
        const dropdown = trigger.nextElementSibling;
        const isOpen = dropdown.classList.contains('open');
        // Close others
        document.querySelectorAll('.cis-dropdown').forEach(d => d.classList.remove('open'));
        if(!isOpen) dropdown.classList.add('open');
    }

    window.selectCisImage = function(item, id, path) {
        const wrapper = item.closest('.cis-wrapper');
        const input = wrapper.querySelector('input[type=hidden]');
        const trigger = wrapper.querySelector('.cis-trigger');
        
        input.value = id;
        
        // Update trigger visuals
        if(id && path) {
            trigger.innerHTML = `<img src="${path}"><span>Pakeisti</span>`;
        } else {
            trigger.innerHTML = `<span>Pasirinkti foto</span>`;
        }
        
        // Update selected state in dropdown
        wrapper.querySelectorAll('.cis-item').forEach(i => i.classList.remove('selected'));
        if(item.classList.contains('cis-item')) {
            item.classList.add('selected');
        }
        
        wrapper.querySelector('.cis-dropdown').classList.remove('open');
    }

    // --- MAIN FORM LOGIC ---
    window.switchTab = function(id) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        
        const content = document.getElementById('tab-' + id);
        if(content) content.classList.add('active');
        
        const btns = document.querySelectorAll('.tab-btn');
        if(id=='basic' && btns[0]) btns[0].classList.add('active');
        if(id=='specs' && btns[1]) btns[1].classList.add('active');
        if(id=='seo' && btns[2]) btns[2].classList.add('active');
    }

    window.syncEditors = function() {
        document.getElementById('p_description').value = document.getElementById('descEditor').innerHTML;
        const container = document.getElementById('attributesContainer');
        container.querySelectorAll('.attr-row').forEach(row => {
            row.querySelector('textarea').value = row.querySelector('.editor-content').innerHTML;
        });
        return true;
    }

    window.previewImages = function(input) {
        const c = document.getElementById('imgPreview');
        c.innerHTML = '';
        if(input.files) {
            Array.from(input.files).forEach(f => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.style.cssText = 'width:60px; height:60px; object-fit:cover; border-radius:4px;';
                    c.appendChild(img);
                }
                reader.readAsDataURL(f);
            });
        }
    }

    window.updateStars = function(radio) {
        document.querySelectorAll('.star-btn').forEach(b => b.classList.remove('active'));
        radio.parentElement.classList.add('active');
    }

    // --- EDIT & RESET LOGIC ---
    window.editProduct = function(data) {
        resetForm(false); 
        
        // Išsaugome nuotraukas globaliai
        window.currentProductAllImages = data.all_images || [];
        
        // 1. Basic Info
        document.getElementById('productId').value = data.id;
        document.getElementById('p_title').value = data.title;
        document.getElementById('p_subtitle').value = data.subtitle||'';
        document.getElementById('descEditor').innerHTML = data.description||'';
        document.getElementById('p_ribbon').value = data.ribbon_text||'';
        document.getElementById('p_price').value = data.price;
        document.getElementById('p_sale_price').value = data.sale_price||'';
        document.getElementById('p_quantity').value = data.quantity;

        if(data.is_featured_flag > 0) document.getElementById('p_featured').checked = true;
        
        if(data.category_ids) {
            data.category_ids.forEach(cid => {
                const cb = document.querySelector(`.cat-check[value="${cid}"]`);
                if(cb) cb.checked = true;
            });
        }

        // 2. Images (Now in Basic tab)
        const imgC = document.getElementById('existingImgContainer');
        imgC.innerHTML = '';
        document.getElementById('existingImages').style.display = 'none';
        
        if(data.all_images && data.all_images.length > 0) {
            document.getElementById('existingImages').style.display = 'block';
            data.all_images.forEach(img => {
                const div = document.createElement('div');
                div.className = 'img-manager-item';
                const isPrim = img.is_primary == 1 ? 'active' : '';
                div.innerHTML = `
                    <img src="${img.path}">
                    <div class="img-actions">
                        <label class="star-btn ${isPrim}" title="Padaryti pagrindine">
                            ★ <input type="radio" name="primary_image_id" value="${img.id}" ${img.is_primary==1?'checked':''} style="display:none;" onchange="updateStars(this)">
                        </label>
                        <label class="del-btn" title="Ištrinti">
                            &times; <input type="checkbox" name="delete_images[]" value="${img.id}" style="display:none;" onchange="this.parentElement.style.color = this.checked ? 'black' : 'red'; this.closest('.img-manager-item').style.opacity = this.checked ? 0.5 : 1;">
                        </label>
                    </div>
                `;
                imgC.appendChild(div);
            });
        }

        // 3. Attributes & Variations (Specs tab)
        const attrC = document.getElementById('attributesContainer');
        attrC.innerHTML = '';
        if(data.attributes && data.attributes.length > 0) {
            data.attributes.forEach(a => addRichAttrRow(a.label, a.value));
        } else {
            addRichAttrRow();
        }

        const varWrapper = document.getElementById('variationsWrapper');
        varWrapper.innerHTML = '';
        if(data.variations && data.variations.length > 0) {
            const groups = {};
            data.variations.forEach(v => {
                const g = v.group_name || ''; 
                if(!groups[g]) groups[g] = [];
                groups[g].push(v);
            });
            
            for (const [gName, items] of Object.entries(groups)) {
                addVariationGroup(gName, items);
            }
        }

        // 4. SEO
        document.getElementById('p_meta_tags').value = data.meta_tags||'';
        
        // UI Updates
        document.getElementById('formTitle').innerText = 'Redaguoti prekę: ' + data.title;
        document.getElementById('submitBtn').innerText = 'Išsaugoti pakeitimus';
        document.getElementById('cancelEditBtn').style.display = 'inline-block';
        
        // Scroll
        document.getElementById('productFormSection').scrollIntoView({behavior: 'smooth'});
    }

    window.resetForm = function(scroll = true) {
        document.getElementById('mainProductForm').reset();
        document.getElementById('productId').value = '';
        window.currentProductAllImages = []; // Resetuojam nuotraukas

        document.getElementById('descEditor').innerHTML = '';
        document.getElementById('attributesContainer').innerHTML = '';
        addRichAttrRow(); 
        document.getElementById('variationsWrapper').innerHTML = '';
        document.getElementById('imgPreview').innerHTML = '';
        document.getElementById('existingImgContainer').innerHTML = '';
        document.getElementById('existingImages').style.display = 'none';
        
        document.querySelectorAll('.cat-check').forEach(c => c.checked = false);
        document.getElementById('formTitle').innerText = '+ Pridėti naują prekę';
        document.getElementById('submitBtn').innerText = 'Sukurti prekę';
        document.getElementById('cancelEditBtn').style.display = 'none';
        
        switchTab('basic');
        
        if(scroll) document.getElementById('productFormSection').scrollIntoView({behavior: 'smooth'});
    }
    
    // Bulk & Search
    window.toggleAll = function(s) { document.querySelectorAll('.prod-check').forEach(c=>c.checked=s.checked); updateBulkUI(); }
    window.updateBulkUI = function() {
        const n = document.querySelectorAll('.prod-check:checked').length;
        document.getElementById('selectedCount').innerText = n;
        document.getElementById('bulkActionsPanel').classList.toggle('visible', n>0);
    }
    window.submitBulkDelete = function() { if(confirm('Trinti?')) document.getElementById('productsListForm').submit(); }
</script>
