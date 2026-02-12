<?php
// admin/categories.php

require_once __DIR__ . '/../helpers.php'; // Reikalinga slugify funkcijai

// 1. DB Migracija: Užtikriname, kad categories lentelė turi parent_id
try {
    $pdo->query("SELECT parent_id FROM categories LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE categories ADD COLUMN parent_id INT NULL DEFAULT NULL AFTER id");
    $pdo->exec("ALTER TABLE categories ADD INDEX (parent_id)");
}

// 2. Veiksmų apdorojimas
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['action'] ?? '';

    // --- NAUJA / ATNAUJINTI KATEGORIJA ---
    if ($action === 'save_category') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        
        // Jei slug tuščias, generuojame iš pavadinimo
        if (empty($slug)) {
            $slug = slugify($name);
        } else {
            $slug = slugify($slug); // Išvalome, jei vartotojas įvedė
        }
        
        // Parent ID
        $parentId = null;
        if (isset($_POST['parent_id']) && is_numeric($_POST['parent_id'])) {
            $pid = (int)$_POST['parent_id'];
            if ($pid > 0 && $pid !== $id) { // Negali būti tėvas pačiam sau
                $parentId = $pid;
            }
        }

        if ($name && $slug) {
            if ($id > 0) {
                // Atnaujinimas
                $stmt = $pdo->prepare("UPDATE categories SET name = ?, slug = ?, parent_id = ? WHERE id = ?");
                $stmt->execute([$name, $slug, $parentId, $id]);
            } else {
                // Kūrimas
                $stmt = $pdo->prepare("INSERT INTO categories (name, slug, parent_id) VALUES (?, ?, ?)");
                $stmt->execute([$name, $slug, $parentId]);
            }
            
            header('Location: /admin.php?view=categories');
            exit;
        }
    }

    // --- IŠTRYNIMAS ---
    if ($action === 'delete_category') {
        $id = (int)$_POST['id'];
        // Atkabiname vaikus (padarome juos pagrindiniais)
        $pdo->prepare("UPDATE categories SET parent_id = NULL WHERE parent_id = ?")->execute([$id]);
        // Ištriname ryšius su produktais
        $pdo->prepare("DELETE FROM product_category_relations WHERE category_id = ?")->execute([$id]);
        // Ištriname pačią kategoriją
        $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
        
        header('Location: /admin.php?view=categories');
        exit;
    }
}

// 3. Duomenų gavimas
$allCategories = $pdo->query("
    SELECT c.*, 
    (SELECT COUNT(*) FROM product_category_relations pcr WHERE pcr.category_id = c.id) as product_count 
    FROM categories c 
    ORDER BY c.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Sukuriame medžio struktūrą
$catsById = [];
$catsByParent = [];

foreach ($allCategories as $c) {
    $c['id'] = (int)$c['id'];
    $pid = !empty($c['parent_id']) ? (int)$c['parent_id'] : 0;
    $catsById[$c['id']] = $c;
    $catsByParent[$pid][] = $c;
}

// Funkcija select option'ams (rekursinė)
function buildCategoryOptions($catsByParent, $parentId = 0, $prefix = '', $excludeId = 0) {
    if (!isset($catsByParent[$parentId])) return;
    
    foreach ($catsByParent[$parentId] as $cat) {
        if ($cat['id'] === $excludeId) continue; // Nereikia rodyti savęs kaip tėvo
        
        echo '<option value="' . $cat['id'] . '">' . $prefix . htmlspecialchars($cat['name']) . '</option>';
        buildCategoryOptions($catsByParent, $cat['id'], $prefix . '&nbsp;&nbsp;&nbsp;↳ ', $excludeId);
    }
}

// Funkcija lentelės eilutėms (rekursinė)
function renderCategoryRows($catsByParent, $parentId = 0, $level = 0) {
    if (!isset($catsByParent[$parentId])) return;

    foreach ($catsByParent[$parentId] as $cat) {
        $padding = $level * 20;
        $arrow = $level > 0 ? '<span style="color:#ccc; margin-right:5px;">↳</span>' : '';
        $bgStyle = $level === 0 ? 'background:#fff;' : 'background:#fcfcfc;';
        $nameStyle = $level === 0 ? 'font-weight:600; color:#111;' : 'color:#555;';
        
        // Formuojame JSON duomenis redagavimui
        $jsonData = htmlspecialchars(json_encode($cat), ENT_QUOTES, 'UTF-8');
        ?>
        <tr style="<?php echo $bgStyle; ?> border-bottom:1px solid #eee;">
            <td style="padding-left: <?php echo 10 + $padding; ?>px;">
                <?php echo $arrow; ?>
                <span style="<?php echo $nameStyle; ?>"><?php echo htmlspecialchars($cat['name']); ?></span>
            </td>
            <td style="color:#666; font-size:13px; font-family:monospace;"><?php echo htmlspecialchars($cat['slug']); ?></td>
            <td style="text-align:center;">
                <?php if($cat['product_count'] > 0): ?>
                    <span style="background:#e0f2fe; color:#0369a1; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700;">
                        <?php echo (int)$cat['product_count']; ?>
                    </span>
                <?php else: ?>
                    <span style="color:#ccc; font-size:11px;">-</span>
                <?php endif; ?>
            </td>
            <td class="inline-actions" style="text-align:right;">
                <button type="button" class="btn secondary" 
                        onclick='editCategory(<?php echo $jsonData; ?>)' 
                        style="padding:4px 10px; font-size:12px; margin-right:4px;">
                    Redaguoti
                </button>
                <form method="post" onsubmit="return confirm('Ištrinti kategoriją? \n(Produktai liks, bet nebus priskirti šiai kategorijai)');" style="display:inline-block; margin:0;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                    <button class="btn" type="submit" style="background:#fee2e2; color:#991b1b; border-color:#fecaca; padding:4px 10px; font-size:12px;">&times;</button>
                </form>
            </td>
        </tr>
        <?php
        renderCategoryRows($catsByParent, $cat['id'], $level + 1);
    }
}
?>

<style>
    .admin-grid { display: grid; grid-template-columns: 300px 1fr; gap: 24px; align-items: start; }
    .cat-form-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; position: sticky; top: 20px; }
    .cat-table-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
    
    label { display: block; margin-bottom: 5px; font-size: 13px; font-weight: 600; color: #374151; }
    input, select { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; margin-bottom: 15px; }
    input:focus, select:focus { border-color: #2563eb; outline: none; ring: 2px solid rgba(37,99,235,0.2); }
    
    .btn { cursor: pointer; border: 1px solid transparent; border-radius: 6px; font-weight: 500; transition: all 0.2s; }
    .btn-primary { background: #0f172a; color: #fff; width: 100%; padding: 10px; }
    .btn-primary:hover { background: #1e293b; }
    
    .btn-cancel { background: #fff; border: 1px solid #d1d5db; color: #374151; width: 100%; padding: 8px; margin-top: 8px; display: none; }
    .btn-cancel:hover { background: #f3f4f6; }

    @media (max-width: 900px) { .admin-grid { grid-template-columns: 1fr; } .cat-form-card { position: static; } }
</style>

<div class="admin-grid">
  <div class="cat-form-card">
    <h3 style="margin-top:0; margin-bottom:16px;" id="formTitle">Nauja kategorija</h3>
    
    <form method="post" action="/admin.php?view=categories" id="categoryForm">
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" value="save_category">
      <input type="hidden" name="id" id="catId" value="0">
      
      <div>
          <label>Pavadinimas</label>
          <input name="name" id="catName" placeholder="Pvz.: Saldainiai" required oninput="generateSlug(this.value)">
      </div>
      
      <div>
          <label>Nuoroda (Slug)</label>
          <input name="slug" id="catSlug" placeholder="pvz-saldainiai" style="font-family:monospace; color:#666;">
          <small style="color:#888; display:block; margin-top:-10px; margin-bottom:15px; font-size:11px;">Jei paliksite tuščią, sugeneruos automatiškai.</small>
      </div>
      
      <div>
          <label>Tėvinė kategorija</label>
          <select name="parent_id" id="catParent">
            <option value="0">-- Pagrindinė kategorija --</option>
            <?php buildCategoryOptions($catsByParent, 0); ?>
          </select>
      </div>
      
      <button class="btn btn-primary" type="submit" id="submitBtn">Sukurti kategoriją</button>
      <button class="btn btn-cancel" type="button" id="cancelBtn" onclick="resetForm()">Atšaukti redagavimą</button>
    </form>
  </div>

  <div class="cat-table-card">
    <div style="padding:16px 20px; border-bottom:1px solid #eee; background:#f9fafb;">
        <h3 style="margin:0; font-size:16px;">Kategorijų struktūra</h3>
    </div>
    <table style="width:100%; border-collapse:collapse;">
      <thead>
          <tr style="background:#f9fafb; font-size:12px; text-transform:uppercase; color:#6b7280; text-align:left;">
              <th style="padding:12px 20px;">Pavadinimas</th>
              <th style="padding:12px;">Slug</th>
              <th style="padding:12px; text-align:center;">Prekės</th>
              <th style="padding:12px; text-align:right; padding-right:20px;">Veiksmai</th>
          </tr>
      </thead>
      <tbody>
        <?php if (empty($catsByParent[0])): ?>
            <tr><td colspan="4" style="text-align:center; padding:30px; color:#888;">Kategorijų kol kas nėra.</td></tr>
        <?php else: ?>
            <?php renderCategoryRows($catsByParent, 0); ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
    function slugify(text) {
        return text.toString().toLowerCase()
            .replace(/\s+/g, '-')           // Replace spaces with -
            .replace(/[^\w\-]+/g, '')       // Remove all non-word chars
            .replace(/\-\-+/g, '-')         // Replace multiple - with single -
            .replace(/^-+/, '')             // Trim - from start of text
            .replace(/-+$/, '');            // Trim - from end of text
    }

    function generateSlug(val) {
        // Tik jei redaguojama nauja kategorija arba slug laukelis tuščias
        if(document.getElementById('catId').value == '0') {
            document.getElementById('catSlug').value = slugify(val);
        }
    }

    function editCategory(data) {
        document.getElementById('formTitle').innerText = 'Redaguoti kategoriją';
        document.getElementById('submitBtn').innerText = 'Išsaugoti pakeitimus';
        document.getElementById('cancelBtn').style.display = 'block';
        
        document.getElementById('catId').value = data.id;
        document.getElementById('catName').value = data.name;
        document.getElementById('catSlug').value = data.slug;
        document.getElementById('catParent').value = data.parent_id || 0;
        
        // Scroll to form on mobile
        if(window.innerWidth < 900) {
            document.querySelector('.cat-form-card').scrollIntoView({behavior: 'smooth'});
        }
    }

    function resetForm() {
        document.getElementById('formTitle').innerText = 'Nauja kategorija';
        document.getElementById('submitBtn').innerText = 'Sukurti kategoriją';
        document.getElementById('cancelBtn').style.display = 'none';
        document.getElementById('categoryForm').reset();
        document.getElementById('catId').value = '0';
    }
</script>
