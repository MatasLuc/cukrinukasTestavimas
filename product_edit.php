<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header('Location: /login.php');
    exit;
}

$pdo = getPdo();
ensureUsersTable($pdo);
// ensureCategoriesTable($pdo); // Jau turėtų būti sukurta su parent_id
ensureProductsTable($pdo);
ensureProductRelations($pdo);
ensureAdminAccount($pdo);
tryAutoLogin($pdo);

// Užtikrinam, kad turim parent_id
try { $pdo->query("SELECT parent_id FROM categories LIMIT 1"); } 
catch (Exception $e) { $pdo->exec("ALTER TABLE categories ADD COLUMN parent_id INT NULL DEFAULT NULL AFTER id"); }

function setPrimaryImageForProduct(PDO $pdo, int $productId, int $imageId): void {
    $pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$productId]);
    $pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ? AND product_id = ?')->execute([$imageId, $productId]);
    $path = $pdo->prepare('SELECT path FROM product_images WHERE id = ? AND product_id = ?');
    $path->execute([$imageId, $productId]);
    $file = $path->fetchColumn();
    if ($file) {
        $pdo->prepare('UPDATE products SET image_url = ? WHERE id = ?')->execute([$file, $productId]);
    }
}

function deleteProductImageForProduct(PDO $pdo, int $productId, int $imageId): void {
    $stmt = $pdo->prepare('SELECT path, is_primary FROM product_images WHERE id = ? AND product_id = ?');
    $stmt->execute([$imageId, $productId]);
    $image = $stmt->fetch();
    if (!$image) return;

    $pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imageId]);
    $filePath = __DIR__ . '/' . ltrim($image['path'], '/');
    if (is_file($filePath)) @unlink($filePath);

    if ((int)$image['is_primary'] === 1) {
        $fallback = $pdo->prepare('SELECT id, path FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id DESC LIMIT 1');
        $fallback->execute([$productId]);
        $newMain = $fallback->fetch();
        if ($newMain) {
            $pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ?')->execute([$newMain['id']]);
            $pdo->prepare('UPDATE products SET image_url = ? WHERE id = ?')->execute([$newMain['path'], $productId]);
        } else {
            $pdo->prepare('UPDATE products SET image_url = ? WHERE id = ?')->execute(['https://placehold.co/600x400?text=Preke', $productId]);
        }
    }
}

function storeProductUploads(PDO $pdo, int $productId, array $files): void {
    if (empty($files['name'][0])) return;
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = ? AND is_primary = 1');
    $countStmt->execute([$productId]);
    $hasPrimary = (int)$countStmt->fetchColumn();

    $allowedMimeMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        $file = [
            'name' => $files['name'][$i],
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i],
            'size' => $files['size'][$i] ?? 0,
        ];
        $relativePath = saveUploadedFile($file, $allowedMimeMap, 'img_');
        if (!$relativePath) continue;

        $isPrimary = ($hasPrimary === 0 && $i === 0) ? 1 : 0;
        $stmt = $pdo->prepare('INSERT INTO product_images (product_id, path, is_primary) VALUES (?, ?, ?)');
        $stmt->execute([$productId, $relativePath, $isPrimary]);
        if ($isPrimary) {
            $pdo->prepare('UPDATE products SET image_url = ? WHERE id = ?')->execute([$relativePath, $productId]);
            $hasPrimary = 1;
        }
    }
}

$productId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([$productId]);
$product = $stmt->fetch();
if (!$product) { http_response_code(404); echo 'Prekė nerasta'; exit; }

$stmtCats = $pdo->prepare("SELECT category_id FROM product_category_relations WHERE product_id = ?");
$stmtCats->execute([$productId]);
$currentCatIds = $stmtCats->fetchAll(PDO::FETCH_COLUMN);
if (empty($currentCatIds) && $product['category_id']) $currentCatIds[] = $product['category_id'];

$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['action'] ?? '';
    if ($action === 'edit_product') {
        $title = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $description = trim($_POST['description'] ?? ''); // HTML
        $ribbon = trim($_POST['ribbon_text'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $salePrice = isset($_POST['sale_price']) && $_POST['sale_price'] !== '' ? (float)$_POST['sale_price'] : null;
        $qty = (int)($_POST['quantity'] ?? 0);
        $metaTags = trim($_POST['meta_tags'] ?? '');
        
        $selectedCatIds = $_POST['categories'] ?? [];
        $primaryCatId = !empty($selectedCatIds) ? (int)$selectedCatIds[0] : null;

        if ($title) {
            $pdo->prepare('UPDATE products SET category_id = ?, title = ?, subtitle = ?, description = ?, ribbon_text = ?, price = ?, sale_price = ?, quantity = ?, meta_tags = ? WHERE id = ?')
                ->execute([$primaryCatId, $title, $subtitle ?: null, $description, $ribbon ?: null, $price, $salePrice, $qty, $metaTags ?: null, $productId]);
            
            $pdo->prepare("DELETE FROM product_category_relations WHERE product_id = ?")->execute([$productId]);
            if (!empty($selectedCatIds)) {
                $relStmt = $pdo->prepare('INSERT INTO product_category_relations (product_id, category_id) VALUES (?, ?)');
                foreach ($selectedCatIds as $cid) $relStmt->execute([$productId, (int)$cid]);
                $currentCatIds = $selectedCatIds;
            } else {
                $currentCatIds = [];
            }

            storeProductUploads($pdo, $productId, $_FILES['images'] ?? []);

            $related = array_filter(array_map('intval', $_POST['related_products'] ?? []));
            $pdo->prepare('DELETE FROM product_related WHERE product_id = ?')->execute([$productId]);
            if ($related) {
                $insertRel = $pdo->prepare('INSERT IGNORE INTO product_related (product_id, related_product_id) VALUES (?, ?)');
                foreach ($related as $rel) if ($rel !== $productId) $insertRel->execute([$productId, $rel]);
            }

            $pdo->prepare('DELETE FROM product_attributes WHERE product_id = ?')->execute([$productId]);
            $attrNames = $_POST['attr_label'] ?? [];
            $attrValues = $_POST['attr_value'] ?? [];
            $insertAttr = $pdo->prepare('INSERT INTO product_attributes (product_id, label, value) VALUES (?, ?, ?)');
            foreach ($attrNames as $idx => $label) {
                $label = trim($label);
                $val = trim($attrValues[$idx] ?? '');
                if ($label || $val) $insertAttr->execute([$productId, $label, $val]);
            }

            $pdo->prepare('DELETE FROM product_variations WHERE product_id = ?')->execute([$productId]);
            $varNames = $_POST['variation_name'] ?? [];
            $varPrices = $_POST['variation_price'] ?? [];
            $insertVar = $pdo->prepare('INSERT INTO product_variations (product_id, name, price_delta) VALUES (?, ?, ?)');
            foreach ($varNames as $idx => $vName) {
                $vName = trim($vName);
                $delta = isset($varPrices[$idx]) ? (float)$varPrices[$idx] : 0;
                if ($vName !== '') $insertVar->execute([$productId, $vName, $delta]);
            }
            $messages[] = 'Prekė atnaujinta';
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
        } else {
            $errors[] = 'Trūksta pavadinimo';
        }
    }
    if ($action === 'set_primary_image') {
        setPrimaryImageForProduct($pdo, $productId, (int)$_POST['image_id']);
        $messages[] = 'Pagrindinė nuotrauka atnaujinta';
    }
    if ($action === 'delete_image') {
        deleteProductImageForProduct($pdo, $productId, (int)$_POST['image_id']);
        $messages[] = 'Nuotrauka pašalinta';
    }
}

$imageStmt = $pdo->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id DESC');
$imageStmt->execute([$productId]);
$images = $imageStmt->fetchAll();
$relatedProducts = $pdo->prepare('SELECT related_product_id FROM product_related WHERE product_id = ?');
$relatedProducts->execute([$productId]);
$relatedIds = array_map('intval', $relatedProducts->fetchAll(PDO::FETCH_COLUMN));
$allProducts = $pdo->query('SELECT id, title FROM products ORDER BY title')->fetchAll();
$attributes = $pdo->prepare('SELECT * FROM product_attributes WHERE product_id = ?');
$attributes->execute([$productId]);
$attributes = $attributes->fetchAll();
$variations = $pdo->prepare('SELECT * FROM product_variations WHERE product_id = ?');
$variations->execute([$productId]);
$variations = $variations->fetchAll();

// KATEGORIJŲ MEDŽIO PARUOŠIMAS
$allCategories = $pdo->query('SELECT * FROM categories ORDER BY parent_id ASC, name ASC')->fetchAll();
$catTree = [];
foreach ($allCategories as $c) {
    if (empty($c['parent_id'])) {
        $catTree[$c['id']]['self'] = $c;
        $catTree[$c['id']]['children'] = [];
    }
}
foreach ($allCategories as $c) {
    if (!empty($c['parent_id']) && isset($catTree[$c['parent_id']])) {
        $catTree[$c['parent_id']]['children'][] = $c;
    }
}
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Redaguoti prekę | Cukrinukas</title>
  <?php echo headerStyles(); ?>
  <style>
    :root { --color-bg: #f7f7fb; --color-primary: #0b0b0b; }
    * { box-sizing: border-box; }
    a { color:inherit; text-decoration:none; }
    .page { max-width:1200px; margin:0 auto; padding:24px; }
    .card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 12px 24px rgba(0,0,0,0.08); }
    label { display:block; margin-bottom:8px; font-weight:600; }
    input, select { width:100%; padding:12px; border-radius:12px; border:1px solid #d7d7e2; margin-bottom:10px; }
    .btn { padding:10px 14px; border-radius:12px; border:1px solid #0b0b0b; background:#0b0b0b; color:#fff; font-weight:600; cursor:pointer; }
    .image-list { display:flex; gap:10px; flex-wrap:wrap; }
    .image-tile { border:1px solid #e6e6ef; border-radius:12px; padding:8px; width:160px; text-align:center; background:#f9f9ff; }
    .image-tile img { width:100%; height:110px; object-fit:cover; border-radius:10px; }
    
    .toolbar { position:sticky; top:0; z-index:10; background:#fff; padding:10px; border-bottom:1px solid #ddd; margin-bottom:10px; display:flex; gap:6px; flex-wrap:wrap; border-radius:8px 8px 0 0; }
    .toolbar button { border-radius:6px; padding:6px 10px; border:1px solid #d7d7e2; background:#f8f9fa; cursor:pointer; font-weight:600; font-size:13px; }
    .rich-editor { min-height:180px; padding:12px; border:1px solid #d7d7e2; border-radius:12px; background:#fff; line-height:1.6; margin-bottom:10px; }
    .rich-editor img { max-width:100%; height:auto; }
    .rich-editor ul, .rich-editor ol { padding-left:20px; }
    .mini-editor { min-height:60px; max-height:150px; overflow-y:auto; padding:8px; border:1px solid #d7d7e2; border-radius:8px; background:#fff; margin-bottom:0; }

    .cat-grid { border: 1px solid #d7d7e2; padding: 12px; border-radius: 12px; background: #fbfbff; max-height: 300px; overflow-y: auto; margin-bottom:12px; }
    .cat-group { margin-bottom:10px; }
    .cat-parent { font-weight:bold; display:block; margin-bottom:4px; }
    .cat-children { display:grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap:5px; padding-left:15px; }
    .cat-item { display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:normal; font-size:14px; }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'admin'); ?>
  <div class="page">
    <a href="/admin.php?view=products" style="display:inline-block; margin-bottom:12px; font-weight:600;">↩ Atgal į prekių sąrašą</a>
    <div class="card">
      <h1 style="margin-top:0;">Redaguoti prekę</h1>
      <?php foreach ($messages as $msg): ?>
        <div style="background:#edf9f0; border:1px solid #b8e2c4; padding:12px; border-radius:12px; color:#0f5132; margin-bottom:10px;">&check; <?php echo htmlspecialchars($msg); ?></div>
      <?php endforeach; ?>
      <?php foreach ($errors as $err): ?>
        <div style="background:#fff1f1; border:1px solid #f3b7b7; padding:12px; border-radius:12px; color:#991b1b; margin-bottom:10px;">&times; <?php echo htmlspecialchars($err); ?></div>
      <?php endforeach; ?>

      <div style="display:grid; grid-template-columns: 2fr 1fr; gap:16px; align-items:start;">
        
        <div>
          <form id="product-form" method="post" enctype="multipart/form-data" onsubmit="return syncAllEditors();">
              <?php echo csrfField(); ?>
              <input type="hidden" name="action" value="edit_product">
              
              <label>Pavadinimas</label>
              <input name="title" value="<?php echo htmlspecialchars($product['title']); ?>" required>
              
              <label>Paantraštė</label>
              <input name="subtitle" value="<?php echo htmlspecialchars($product['subtitle'] ?? ''); ?>">
              
              <label>Aprašymas ir turinys</label>
              <div class="toolbar">
                 <button type="button" onmousedown="event.preventDefault()" onclick="format('bold')"><b>B</b></button>
                 <button type="button" onmousedown="event.preventDefault()" onclick="format('italic')"><em>I</em></button>
                 <button type="button" onmousedown="event.preventDefault()" onclick="format('insertUnorderedList')">• Sąrašas</button>
                 <button type="button" onmousedown="event.preventDefault()" onclick="createLink()">🔗</button>
                 <button type="button" onmousedown="event.preventDefault()" onclick="format('removeFormat')">Išvalyti</button>
              </div>

              <div id="desc-editor" class="rich-editor" contenteditable="true"><?php echo $product['description']; ?></div>
              <textarea name="description" id="desc-textarea" hidden></textarea>

              <label>Juostelės tekstas</label>
              <input name="ribbon_text" value="<?php echo htmlspecialchars($product['ribbon_text'] ?? ''); ?>">
              
              <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap:10px;">
                <label>Kaina<input type="number" step="0.01" name="price" value="<?php echo htmlspecialchars($product['price']); ?>" required></label>
                <label>Kaina su nuolaida<input type="number" step="0.01" name="sale_price" value="<?php echo htmlspecialchars($product['sale_price'] ?? ''); ?>"></label>
                <label>Kiekis<input type="number" name="quantity" min="0" value="<?php echo (int)$product['quantity']; ?>" required></label>
              </div>

              <label>Kategorijos (Hierarchinis pasirinkimas)</label>
              <div class="cat-grid">
                <?php foreach ($catTree as $branch): ?>
                  <div class="cat-group">
                      <label class="cat-parent cat-item">
                          <input type="checkbox" name="categories[]" value="<?php echo (int)$branch['self']['id']; ?>" <?php echo in_array($branch['self']['id'], $currentCatIds) ? 'checked' : ''; ?>>
                          <?php echo htmlspecialchars($branch['self']['name']); ?>
                      </label>
                      
                      <?php if(!empty($branch['children'])): ?>
                        <div class="cat-children">
                            <?php foreach ($branch['children'] as $child): ?>
                                <label class="cat-item">
                                    <input type="checkbox" name="categories[]" value="<?php echo (int)$child['id']; ?>" <?php echo in_array($child['id'], $currentCatIds) ? 'checked' : ''; ?>>
                                    <?php echo htmlspecialchars($child['name']); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>

              <label>Pridėti nuotraukų</label>
              <input type="file" name="images[]" multiple accept="image/*">
              
              <label>SEO žymės</label>
              <textarea name="meta_tags" style="min-height:80px; width:100%; border-radius:12px; border:1px solid #d7d7e2; padding:10px;"><?php echo htmlspecialchars($product['meta_tags'] ?? ''); ?></textarea>

              <h3>Susijusios prekės</h3>
              <select name="related_products[]" multiple size="6" style="width:100%; padding:10px; border-radius:12px; border:1px solid #d7d7e2;">
                <?php foreach ($allProducts as $p): ?>
                  <?php if ((int)$p['id'] === (int)$productId) { continue; } ?>
                  <option value="<?php echo (int)$p['id']; ?>" <?php echo in_array((int)$p['id'], $relatedIds, true) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['title']); ?></option>
                <?php endforeach; ?>
              </select>

              <h3>Papildomi laukeliai</h3>
              <div id="attributes" style="display:flex; flex-direction:column; gap:12px;">
                <?php if ($attributes): foreach ($attributes as $i => $attr): ?>
                  <div style="display:grid; grid-template-columns:1fr 2fr; gap:8px;">
                    <input name="attr_label[]" placeholder="Pavadinimas" value="<?php echo htmlspecialchars($attr['label']); ?>">
                    <div class="mini-editor" contenteditable="true" data-target="attr-val-<?php echo $i; ?>"><?php echo $attr['value']; ?></div>
                    <input type="hidden" name="attr_value[]" id="attr-val-<?php echo $i; ?>" value="<?php echo htmlspecialchars($attr['value']); ?>">
                  </div>
                <?php endforeach; endif; ?>
                <div style="display:grid; grid-template-columns:1fr 2fr; gap:8px;">
                  <input name="attr_label[]" placeholder="Pavadinimas">
                  <div class="mini-editor" contenteditable="true" data-target="attr-val-new"></div>
                  <input type="hidden" name="attr_value[]" id="attr-val-new">
                </div>
              </div>
              <button type="button" class="btn" style="margin-top:10px; background:#fff; color:#0b0b0b;" onclick="addAttrRow()">+ Pridėti laukelį</button>

              <h3>Variacijos</h3>
              <div id="variations" style="display:flex; flex-direction:column; gap:10px;">
                <?php if ($variations): foreach ($variations as $var): ?>
                  <div style="display:grid; grid-template-columns:2fr 1fr; gap:8px;">
                    <input name="variation_name[]" value="<?php echo htmlspecialchars($var['name']); ?>">
                    <input name="variation_price[]" type="number" step="0.01" value="<?php echo htmlspecialchars($var['price_delta']); ?>">
                  </div>
                <?php endforeach; endif; ?>
                <div style="display:grid; grid-template-columns:2fr 1fr; gap:8px;">
                  <input name="variation_name[]" placeholder="Variacijos pavadinimas">
                  <input name="variation_price[]" type="number" step="0.01" placeholder="Δ kaina">
                </div>
              </div>
              <button class="btn" type="submit" style="margin-top:20px;">Išsaugoti pakeitimus</button>
          </form>
        </div>

        <div>
          <h3>Nuotraukos</h3>
          <div class="image-list">
            <?php foreach ($images as $img): ?>
              <div class="image-tile">
                <img src="<?php echo htmlspecialchars($img['path']); ?>">
                <form method="post" style="margin-top:6px;">
                  <?php echo csrfField(); ?>
                  <input type="hidden" name="action" value="set_primary_image">
                  <input type="hidden" name="image_id" value="<?php echo (int)$img['id']; ?>">
                  <button class="btn" type="submit" style="width:100%; <?php echo $img['is_primary'] ? '' : 'background:#fff; color:#0b0b0b;'; ?>">
                    <?php echo $img['is_primary'] ? 'Pagrindinė' : 'Padaryti pagr.'; ?>
                  </button>
                </form>
                <form method="post" onsubmit="return confirm('Trinti?');">
                  <?php echo csrfField(); ?>
                  <input type="hidden" name="action" value="delete_image">
                  <input type="hidden" name="image_id" value="<?php echo (int)$img['id']; ?>">
                  <button class="btn" type="submit" style="width:100%; background:#fff; color:#0b0b0b; margin-top:6px;">Trinti</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

    </div>
  </div>
  <?php renderFooter($pdo); ?>
  <script>
    function format(cmd, val=null){ document.execCommand(cmd,false,val); }
    function createLink(){ const u=prompt('URL:'); if(u) format('createLink',u); }
    function syncAllEditors(){
        document.getElementById('desc-textarea').value = document.getElementById('desc-editor').innerHTML;
        document.querySelectorAll('.mini-editor').forEach(e=>{
            const t=e.getAttribute('data-target'); if(t) document.getElementById(t).value=e.innerHTML;
        });
        return true;
    }
    function addAttrRow(){
        const c=document.getElementById('attributes'), id='attr-new-'+Date.now(), d=document.createElement('div');
        d.style.cssText="display:grid; grid-template-columns:1fr 2fr; gap:8px;";
        d.innerHTML=`<input name="attr_label[]" placeholder="Pavadinimas"><div class="mini-editor" contenteditable="true" data-target="${id}"></div><input type="hidden" name="attr_value[]" id="${id}">`;
        c.appendChild(d);
    }
  </script>
</body>
</html>
