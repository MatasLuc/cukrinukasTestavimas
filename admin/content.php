<?php
// admin/content.php

// ---------------------------------------------------------
// 1. Kategorijų LOGIKA (Create / Update / Delete)
// ---------------------------------------------------------

// Užtikriname lenteles
if (function_exists('ensureNewsCategoriesTable')) ensureNewsCategoriesTable($pdo);
if (function_exists('ensureRecipeCategoriesTable')) ensureRecipeCategoriesTable($pdo);

// Apdorojame veiksmus
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();

    $action = $_POST['action'] ?? '';
    
    $generateSlug = function($name, $inputSlug) {
        if (empty($inputSlug)) {
            return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
        }
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $inputSlug)));
    };

    // --- NAUJIENŲ KATEGORIJOS ---
    if ($action === 'create_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            $slug = $generateSlug($name, $_POST['slug'] ?? '');
            $pdo->prepare("INSERT INTO news_categories (name, slug) VALUES (?, ?)")->execute([$name, $slug]);
        }
        header('Location: /admin.php?view=content'); exit;
    }
    if ($action === 'update_category') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        if ($id && $name) {
            $slug = $generateSlug($name, $_POST['slug'] ?? '');
            $pdo->prepare("UPDATE news_categories SET name = ?, slug = ? WHERE id = ?")->execute([$name, $slug, $id]);
        }
        header('Location: /admin.php?view=content'); exit;
    }
    if ($action === 'delete_category') {
        $id = (int)$_POST['id'];
        if ($id) $pdo->prepare("DELETE FROM news_categories WHERE id = ?")->execute([$id]);
        header('Location: /admin.php?view=content'); exit;
    }

    // --- RECEPTŲ KATEGORIJOS ---
    if ($action === 'create_recipe_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            $slug = $generateSlug($name, $_POST['slug'] ?? '');
            $pdo->prepare("INSERT INTO recipe_categories (name, slug) VALUES (?, ?)")->execute([$name, $slug]);
        }
        header('Location: /admin.php?view=content'); exit;
    }
    if ($action === 'update_recipe_category') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        if ($id && $name) {
            $slug = $generateSlug($name, $_POST['slug'] ?? '');
            $pdo->prepare("UPDATE recipe_categories SET name = ?, slug = ? WHERE id = ?")->execute([$name, $slug, $id]);
        }
        header('Location: /admin.php?view=content'); exit;
    }
    if ($action === 'delete_recipe_category') {
        $id = (int)$_POST['id'];
        if ($id) $pdo->prepare("DELETE FROM recipe_categories WHERE id = ?")->execute([$id]);
        header('Location: /admin.php?view=content'); exit;
    }

    // --- ĮRAŠŲ TRYNIMAS ---
    if ($action === 'delete_news') {
        $id = (int)$_POST['id'];
        if ($id) $pdo->prepare("DELETE FROM news WHERE id = ?")->execute([$id]);
        header('Location: /admin.php?view=content'); exit;
    }
    if ($action === 'delete_recipe') {
        $id = (int)$_POST['id'];
        if ($id) $pdo->prepare("DELETE FROM recipes WHERE id = ?")->execute([$id]);
        header('Location: /admin.php?view=content'); exit;
    }
}

// ---------------------------------------------------------
// 2. Duomenų gavimas
// ---------------------------------------------------------

$newsList = $pdo->query('SELECT id, title, created_at FROM news ORDER BY created_at DESC')->fetchAll();
$recipeList = $pdo->query('SELECT id, title, created_at FROM recipes ORDER BY created_at DESC')->fetchAll();
$categoryList = $pdo->query('SELECT * FROM news_categories ORDER BY name ASC')->fetchAll();
$recipeCategoryList = $pdo->query('SELECT * FROM recipe_categories ORDER BY name ASC')->fetchAll();

// Redagavimo būsenos
$editCategory = null;
if (isset($_GET['edit_cat'])) {
    $editId = (int)$_GET['edit_cat'];
    foreach ($categoryList as $cat) { if ($cat['id'] === $editId) { $editCategory = $cat; break; } }
}
$editRecipeCategory = null;
if (isset($_GET['edit_recipe_cat'])) {
    $editId = (int)$_GET['edit_recipe_cat'];
    foreach ($recipeCategoryList as $cat) { if ($cat['id'] === $editId) { $editRecipeCategory = $cat; break; } }
}
?>

<style>
    /* Papildomi stiliai specifiniai šiam puslapiui, derinantys prie bendro dizaino */
    .section-title {
        font-size: 16px;
        font-weight: 700;
        color: var(--text-main);
        margin: 0 0 4px 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .section-subtitle {
        font-size: 12px;
        color: var(--text-muted);
        margin-bottom: 16px;
    }
    
    .form-box {
        background: #f9fafb;
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 16px;
        margin-bottom: 20px;
    }
    
    .form-label {
        display: block;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--text-muted);
        margin-bottom: 4px;
    }
    
    .form-control {
        width: 100%;
        padding: 8px 10px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 13px;
        background: #fff;
    }
    
    .list-table td {
        padding: 10px 12px;
        border-bottom: 1px solid var(--border);
    }
    .list-table tr:last-child td { border-bottom: none; }
    
    .date-badge {
        font-size: 11px;
        color: #6b7280;
        background: #f3f4f6;
        padding: 2px 6px;
        border-radius: 4px;
        display: inline-block;
    }
    
    .action-btn-group {
        display: flex;
        gap: 6px;
        justify-content: flex-end;
    }
    
    .btn-xs {
        padding: 4px 8px;
        font-size: 11px;
    }
</style>

<div class="grid grid-2">
    <div>
        <div class="card">
            <div style="display:flex; justify-content: space-between; align-items:flex-start; margin-bottom:10px;">
                <div>
                    <h3 class="section-title">📰 Naujienos</h3>
                    <p class="section-subtitle">Paskutiniai įrašai</p>
                </div>
                <a class="btn" href="/news_create.php" style="font-size:12px;">+ Kurti naują</a>
            </div>
            
            <table class="list-table">
                <thead>
                    <tr>
                        <th>Pavadinimas</th>
                        <th style="width: 100px;">Data</th>
                        <th style="text-align:right; width:120px;">Veiksmai</th>
                    </tr>
                </thead>
                <tbody>
                  <?php foreach ($newsList as $n): ?>
                    <tr>
                      <td>
                          <div style="font-weight:600; font-size:14px;"><?php echo htmlspecialchars($n['title']); ?></div>
                      </td>
                      <td><span class="date-badge"><?php echo date('Y-m-d', strtotime($n['created_at'])); ?></span></td>
                      <td>
                        <div class="action-btn-group">
                            <a class="btn secondary btn-xs" href="/news_edit.php?id=<?php echo (int)$n['id']; ?>">Redaguoti</a>
                            <form method="POST" onsubmit="return confirm('Trinti naujieną?');" style="margin:0;">
                                <?php echo csrfField(); ?> 
                                <input type="hidden" name="action" value="delete_news">
                                <input type="hidden" name="id" value="<?php echo $n['id']; ?>">
                                <button type="submit" class="btn btn-xs" style="background:#fee2e2; color:#b91c1c; border-color:#fecaca;">&times;</button>
                            </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if(empty($newsList)): ?>
                    <tr><td colspan="3" class="muted" style="text-align:center; padding:20px;">Naujienų nėra.</td></tr>
                  <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h3 class="section-title">📂 Naujienų kategorijos</h3>
            <p class="section-subtitle">Grupuokite naujienas</p>

            <div class="form-box" style="<?php echo $editCategory ? 'background:#eff6ff; border-color:#bfdbfe;' : ''; ?>">
                <form method="POST" style="display:grid; grid-template-columns: 1fr 1fr auto; gap:10px; align-items:end;">
                    <?php echo csrfField(); ?> 
                    <?php if ($editCategory): ?>
                        <input type="hidden" name="action" value="update_category">
                        <input type="hidden" name="id" value="<?php echo $editCategory['id']; ?>">
                        
                        <div>
                            <label class="form-label">Pavadinimas</label>
                            <input type="text" name="name" required value="<?php echo htmlspecialchars($editCategory['name']); ?>" class="form-control">
                        </div>
                        <div>
                            <label class="form-label">Slug (URL)</label>
                            <input type="text" name="slug" value="<?php echo htmlspecialchars($editCategory['slug']); ?>" class="form-control">
                        </div>
                        <div style="display:flex; gap:4px;">
                            <button type="submit" class="btn btn-xs">Išsaugoti</button>
                            <a href="/admin.php?view=content" class="btn secondary btn-xs">Atšaukti</a>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="action" value="create_category">
                        <div>
                            <label class="form-label">Nauja kategorija</label>
                            <input type="text" name="name" required placeholder="pvz. Įvykiai" class="form-control">
                        </div>
                        <div>
                            <label class="form-label">Slug (neprivaloma)</label>
                            <input type="text" name="slug" placeholder="pvz. ivykiai" class="form-control">
                        </div>
                        <div><button type="submit" class="btn secondary btn-xs">Pridėti</button></div>
                    <?php endif; ?>
                </form>
            </div>

            <table class="list-table">
                <thead><tr><th>Pavadinimas</th><th>Slug</th><th style="text-align:right;">Veiksmai</th></tr></thead>
                <tbody>
                <?php foreach ($categoryList as $cat): ?>
                    <tr style="<?php echo ($editCategory && $editCategory['id'] == $cat['id']) ? 'background:#f0f9ff;' : ''; ?>">
                        <td style="font-weight:600;"><?php echo htmlspecialchars($cat['name']); ?></td>
                        <td style="color:#6b7280; font-size:12px;"><?php echo htmlspecialchars($cat['slug']); ?></td>
                        <td>
                            <div class="action-btn-group">
                                <a class="btn secondary btn-xs" href="/admin.php?view=content&edit_cat=<?php echo $cat['id']; ?>">Redaguoti</a>
                                <form method="POST" onsubmit="return confirm('Trinti kategoriją?');" style="margin:0;">
                                    <?php echo csrfField(); ?> 
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                                    <button type="submit" class="btn btn-xs" style="background:#fee2e2; color:#b91c1c; border-color:#fecaca;">&times;</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if(empty($categoryList)): ?>
                    <tr><td colspan="3" class="muted" style="text-align:center;">Kategorijų nėra.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div>
        <div class="card">
            <div style="display:flex; justify-content: space-between; align-items:flex-start; margin-bottom:10px;">
                <div>
                    <h3 class="section-title">🍳 Receptai</h3>
                    <p class="section-subtitle">Skanūs įrašai</p>
                </div>
                <a class="btn" href="/recipe_create.php" style="font-size:12px;">+ Kurti naują</a>
            </div>
            
            <table class="list-table">
                <thead>
                    <tr>
                        <th>Pavadinimas</th>
                        <th style="width: 100px;">Data</th>
                        <th style="text-align:right; width:120px;">Veiksmai</th>
                    </tr>
                </thead>
                <tbody>
                  <?php foreach ($recipeList as $r): ?>
                    <tr>
                      <td>
                          <div style="font-weight:600; font-size:14px;"><?php echo htmlspecialchars($r['title']); ?></div>
                      </td>
                      <td><span class="date-badge"><?php echo date('Y-m-d', strtotime($r['created_at'])); ?></span></td>
                      <td>
                        <div class="action-btn-group">
                            <a class="btn secondary btn-xs" href="/recipe_edit.php?id=<?php echo (int)$r['id']; ?>">Redaguoti</a>
                            <form method="POST" onsubmit="return confirm('Trinti receptą?');" style="margin:0;">
                                <?php echo csrfField(); ?> 
                                <input type="hidden" name="action" value="delete_recipe">
                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                <button type="submit" class="btn btn-xs" style="background:#fee2e2; color:#b91c1c; border-color:#fecaca;">&times;</button>
                            </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if(empty($recipeList)): ?>
                    <tr><td colspan="3" class="muted" style="text-align:center; padding:20px;">Receptų nėra.</td></tr>
                  <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h3 class="section-title">🍽️ Receptų kategorijos</h3>
            <p class="section-subtitle">Grupuokite receptus</p>

            <div class="form-box" style="<?php echo $editRecipeCategory ? 'background:#fff7ed; border-color:#ffedd5;' : ''; ?>">
                <form method="POST" style="display:grid; grid-template-columns: 1fr 1fr auto; gap:10px; align-items:end;">
                    <?php echo csrfField(); ?> 
                    <?php if ($editRecipeCategory): ?>
                        <input type="hidden" name="action" value="update_recipe_category">
                        <input type="hidden" name="id" value="<?php echo $editRecipeCategory['id']; ?>">
                        
                        <div>
                            <label class="form-label">Pavadinimas</label>
                            <input type="text" name="name" required value="<?php echo htmlspecialchars($editRecipeCategory['name']); ?>" class="form-control">
                        </div>
                        <div>
                            <label class="form-label">Slug</label>
                            <input type="text" name="slug" value="<?php echo htmlspecialchars($editRecipeCategory['slug']); ?>" class="form-control">
                        </div>
                        <div style="display:flex; gap:4px;">
                            <button type="submit" class="btn btn-xs">Išsaugoti</button>
                            <a href="/admin.php?view=content" class="btn secondary btn-xs">Atšaukti</a>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="action" value="create_recipe_category">
                        <div>
                            <label class="form-label">Nauja kategorija</label>
                            <input type="text" name="name" required placeholder="pvz. Desertai" class="form-control">
                        </div>
                        <div>
                            <label class="form-label">Slug</label>
                            <input type="text" name="slug" placeholder="neprivaloma" class="form-control">
                        </div>
                        <div><button type="submit" class="btn secondary btn-xs">Pridėti</button></div>
                    <?php endif; ?>
                </form>
            </div>

            <table class="list-table">
                <thead><tr><th>Pavadinimas</th><th>Slug</th><th style="text-align:right;">Veiksmai</th></tr></thead>
                <tbody>
                <?php foreach ($recipeCategoryList as $cat): ?>
                    <tr style="<?php echo ($editRecipeCategory && $editRecipeCategory['id'] == $cat['id']) ? 'background:#fff7ed;' : ''; ?>">
                        <td style="font-weight:600;"><?php echo htmlspecialchars($cat['name']); ?></td>
                        <td style="color:#6b7280; font-size:12px;"><?php echo htmlspecialchars($cat['slug']); ?></td>
                        <td>
                            <div class="action-btn-group">
                                <a class="btn secondary btn-xs" href="/admin.php?view=content&edit_recipe_cat=<?php echo $cat['id']; ?>">Redaguoti</a>
                                <form method="POST" onsubmit="return confirm('Trinti kategoriją?');" style="margin:0;">
                                    <?php echo csrfField(); ?> 
                                    <input type="hidden" name="action" value="delete_recipe_category">
                                    <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                                    <button type="submit" class="btn btn-xs" style="background:#fee2e2; color:#b91c1c; border-color:#fecaca;">&times;</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if(empty($recipeCategoryList)): ?>
                    <tr><td colspan="3" class="muted" style="text-align:center;">Kategorijų nėra.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
