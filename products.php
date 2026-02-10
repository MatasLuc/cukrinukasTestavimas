<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require_once __DIR__ . '/helpers.php'; // Būtina slugify funkcijai

$pdo = getPdo();
ensureUsersTable($pdo);
ensureCategoriesTable($pdo);
ensureProductsTable($pdo);
ensureCartTables($pdo);
ensureSavedContentTables($pdo);
ensureAdminAccount($pdo);
tryAutoLogin($pdo);

// Užtikriname, kad egzistuoja ryšių lentelė
$pdo->exec("CREATE TABLE IF NOT EXISTS product_category_relations (
    product_id INT NOT NULL,
    category_id INT NOT NULL,
    PRIMARY KEY (product_id, category_id)
)");

$globalDiscount = getGlobalDiscount($pdo);
$categoryDiscounts = getCategoryDiscounts($pdo);
$freeShippingIds = getFreeShippingProductIds($pdo);

$selectedSlug = $_GET['category'] ?? null;
$searchQuery = $_GET['query'] ?? null;

// --- 1. KATEGORIJŲ MEDIS ---
$allCats = $pdo->query('SELECT id, name, slug, parent_id FROM categories ORDER BY name ASC')->fetchAll();

$catsByParent = [];
$catsById = [];

foreach ($allCats as $c) {
    $c['id'] = (int)$c['id'];
    $parentId = !empty($c['parent_id']) ? (int)$c['parent_id'] : 0;
    
    $catsById[$c['id']] = $c;
    $catsByParent[$parentId][] = $c;
}

$rootCats = $catsByParent[0] ?? [];

// --- 2. FILTRAVIMO LOGIKA ---
$params = [];
$whereClauses = [];

if ($selectedSlug) {
    $stmtCat = $pdo->prepare("SELECT id FROM categories WHERE slug = ?");
    $stmtCat->execute([$selectedSlug]);
    $catId = (int)$stmtCat->fetchColumn();

    if ($catId) {
        $targetIds = [$catId];
        if (isset($catsByParent[$catId])) {
            foreach ($catsByParent[$catId] as $child) {
                $targetIds[] = $child['id'];
            }
        }
        
        $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
        
        $whereClauses[] = "(
            p.category_id IN ($placeholders) 
            OR 
            p.id IN (SELECT product_id FROM product_category_relations WHERE category_id IN ($placeholders))
        )";
        
        foreach ($targetIds as $tid) $params[] = $tid;
        foreach ($targetIds as $tid) $params[] = $tid; // Pakartojame, nes naudojame parametrą du kartus
    } else {
        $whereClauses[] = '1=0';
    }
}

if ($searchQuery) {
    $whereClauses[] = 'p.title LIKE ?';
    $params[] = '%' . $searchQuery . '%';
}

$where = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$stmt = $pdo->prepare(
    'SELECT p.*, c.name AS category_name, c.slug AS category_slug,
        (SELECT path FROM product_images WHERE product_id = p.id AND is_primary = 1 ORDER BY id DESC LIMIT 1) AS primary_image
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     ' . $where . '
     ORDER BY p.created_at DESC'
);
$stmt->execute($params);
$products = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'])) {
    validateCsrfToken();
    $pid = (int) $_POST['product_id'];
    if (($_POST['action'] ?? '') === 'wishlist') {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login.php'); exit;
        }
        saveItemForUser($pdo, (int)$_SESSION['user_id'], 'product', $pid);
        header('Location: /saved.php'); exit;
    }
    $qty = max(1, (int) ($_POST['quantity'] ?? 1));
    $_SESSION['cart'][$pid] = ($_SESSION['cart'][$pid] ?? 0) + $qty;
    if (!empty($_SESSION['user_id'])) saveCartItem($pdo, (int)$_SESSION['user_id'], $pid, $qty);
    header('Location: /cart.php'); exit;
}

$isAdmin = !empty($_SESSION['is_admin']);
$totalProductsCount = count($products);
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Parduotuvė | Cukrinukas</title>
  <?php echo headerStyles(); ?>
  <style>
    :root {
      --bg: #f7f7fb;
      --card: #ffffff;
      --border: #e4e7ec;
      --text-main: #0f172a;
      --text-muted: #475467;
      --accent: #2563eb;
      --accent-hover: #1d4ed8;
      --focus-ring: rgba(37, 99, 235, 0.2);
    }
    * { box-sizing: border-box; }
    body { margin:0; background: var(--bg); color: var(--text-main); font-family: 'Inter', sans-serif; }
    a { color:inherit; text-decoration:none; transition: color .2s; }
    
    /* Layout struktūra */
    .page { max-width: 1200px; margin:0 auto; padding:32px 20px 72px; display:flex; flex-direction:column; gap:28px; }

    /* Hero Section */
    .hero { 
        background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        border:1px solid #dbeafe; 
        border-radius:24px; 
        padding:32px; 
        display:flex; 
        align-items:center; 
        justify-content:space-between; 
        gap:24px; 
        flex-wrap:wrap; 
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }
    .hero h1 { margin:0 0 8px; font-size:28px; color:#1e3a8a; letter-spacing:-0.5px; }
    .hero p { margin:0; color:#1e40af; line-height:1.5; max-width:520px; font-size:15px; }
    
    .pill { 
        display:inline-flex; align-items:center; gap:8px; 
        padding:6px 12px; border-radius:999px; 
        background:#fff; border:1px solid #bfdbfe; 
        font-weight:600; font-size:13px; color:#1e40af; 
        margin-bottom: 12px;
    }

    .stat-card { 
        background:#fff; border:1px solid rgba(255,255,255,0.6); 
        padding:16px 20px; border-radius:16px; 
        min-width:160px; text-align:right;
        box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.1);
    }
    .stat-card strong { display:block; font-size:20px; color:#1e3a8a; margin-bottom: 4px; }
    .stat-card span { color: #64748b; font-size:13px; font-weight: 500; }

    /* Main Grid Layout */
    .layout { display:grid; grid-template-columns: 1fr 300px; gap:24px; align-items:start; }
    @media(max-width: 900px){ .layout { grid-template-columns:1fr; } }

    /* Section Headers */
    .section-header { display:flex; align-items:center; justify-content:space-between; margin-bottom: 16px; }
    .section-header h2 { margin:0; font-size:20px; color: var(--text-main); font-weight: 700; }
    .section-header span { font-size: 13px; color: var(--text-muted); font-weight: 500; background: #e2e8f0; padding: 2px 8px; border-radius: 12px; }

    /* Product Grid */
    .grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap:20px; }
    
    /* Card Styling */
    .card { 
        background:var(--card); 
        border:1px solid var(--border); 
        border-radius:20px; 
        overflow: hidden;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        transition: transform .2s, box-shadow .2s;
        display: flex; flex-direction: column;
        height: 100%;
        position: relative;
    }
    .card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border-color: #cbd5e1; }

    .card img { width: 100%; height: 220px; object-fit: cover; border-bottom: 1px solid var(--border); }
    
    .card-body { padding: 18px; display: flex; flex-direction: column; gap: 10px; flex-grow: 1; }
    
    .card-category { font-size: 11px; color: var(--accent); font-weight: 700; text-transform: uppercase; }
    .card-title { margin: 0; font-size: 17px; line-height: 1.4; color: var(--text-main); font-weight: 700; }
    .card-desc { 
        margin: 0; color: var(--text-muted); font-size: 14px; line-height: 1.5; 
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        flex-grow: 1;
    }

    /* Price & Action Row */
    .price-row { display:flex; justify-content: space-between; align-items:center; margin-top:auto; gap: 8px; padding-top: 12px; border-top: 1px solid transparent; }
    .price { font-size:18px; font-weight:700; color:#111827; }
    .old-price { font-size:13px; text-decoration:line-through; color:#999; font-weight:normal; margin-right:4px; }

    /* Ribbons */
    .ribbon { 
        position: absolute; top: 12px; left: 12px; 
        background: #fff; color: var(--accent); border: 1px solid var(--accent);
        padding: 4px 10px; border-radius: 8px; font-size: 12px; font-weight: 700; 
        box-shadow: 0 2px 4px rgba(0,0,0,0.08); z-index: 5;
    }
    .gift-badge {
        position: absolute; top: 12px; right: 12px;
        background: #fff; color: var(--accent); border: 1px solid var(--accent);
        padding: 4px 10px; border-radius: 8px; font-size: 12px; font-weight: 700;
        box-shadow: 0 2px 4px rgba(0,0,0,0.08); z-index: 5;
    }

    /* Sidebar Styles */
    .sidebar-card { padding: 24px; margin-bottom: 24px; }
    .sidebar-card h3 { margin:0 0 16px; font-size:16px; color: var(--text-main); font-weight: 700; }
    
    /* Search Input */
    .search-group { display:flex; gap:8px; }
    .form-control { 
        flex:1; padding:10px 12px; border-radius:10px; border:1px solid var(--border); 
        background:#fff; font-family:inherit; font-size:14px; color: var(--text-main);
    }
    .btn-search { padding: 0 12px; border-radius: 10px; background: var(--text-main); color: #fff; border:none; cursor: pointer; }

    /* Sidebar Menu */
    .sidebar-menu { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:6px; }
    .sidebar-menu a { 
        display:flex; align-items:center; justify-content: space-between;
        padding:8px 12px; border-radius:8px; 
        color: var(--text-muted); font-size:14px; font-weight:500;
        transition: all .2s;
    }
    .sidebar-menu a:hover { background: #f8fafc; color: var(--text-main); }
    .sidebar-menu a.active { background: #eff6ff; color: var(--accent); font-weight: 600; }
    
    .sidebar-submenu { margin-left: 12px; padding-left: 12px; border-left: 2px solid #e2e8f0; display:flex; flex-direction:column; gap:4px; margin-top: 4px; }
    .sidebar-submenu a { font-size: 13px; padding: 6px 10px; }

    /* Buttons */
    .btn-text { font-size: 14px; font-weight: 600; color: var(--accent); }
    .btn-text:hover { color: var(--accent-hover); text-decoration: underline; }
    
    .action-btn {
        width: 38px; height: 38px;
        border-radius: 10px;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        transition: all .2s;
        background: #f8fafc;
        border: 1px solid var(--border);
        color: #475467;
        font-size: 18px;
        padding: 0;
    }
    .action-btn:hover {
        border-color: var(--accent);
        color: var(--accent);
        background: #fff;
    }

    .empty-state {
        grid-column: 1 / -1;
        text-align: center;
        padding: 64px 20px;
        background: #fff;
        border-radius: 20px;
        border: 1px dashed var(--border);
    }
    
    @media (max-width: 600px) {
        .hero { padding: 24px; }
        .layout { grid-template-columns: 1fr; }
        .grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'products'); ?>

  <div class="page">
    <section class="hero">
      <div>
        <div class="pill">🛍️ Mūsų produktai</div>
        <h1>Parduotuvė</h1>
        <p>Atraskite mūsų geriausius pasiūlymus, saldėsius ir dovanų rinkinius.</p>
      </div>
      <div class="stat-card">
        <strong><?php echo $totalProductsCount; ?></strong>
        <span>Prekių asortimente</span>
      </div>
    </section>

    <div class="layout">
      <div>
        <div class="section-header">
           <h2><?php echo $selectedSlug ? 'Filtruotos prekės' : 'Visos prekės'; ?></h2>
           <span><?php echo count($products); ?></span>
        </div>

        <?php if (empty($products)): ?>
            <div class="empty-state">
                <div style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;">🔍</div>
                <h3 style="margin: 0 0 8px; font-size: 18px;">Prekių nerasta</h3>
                <p style="color: var(--text-muted); margin: 0 0 24px; font-size: 15px;">Pabandykite pakeisti paieškos frazę arba kategoriją.</p>
                <a class="btn-text" href="/products.php">Valyti filtrus</a>
            </div>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($products as $product): 
                    $priceDisplay = buildPriceDisplay($product, $globalDiscount, $categoryDiscounts);
                    $isGift = in_array((int)$product['id'], $freeShippingIds, true);
                    $cardImage = $product['primary_image'] ?: $product['image_url'];
                    $productUrl = '/produktas/' . slugify($product['title']) . '-' . (int)$product['id'];
                ?>
                <article class="card">
                    <?php if (!empty($product['ribbon_text'])): ?>
                        <div class="ribbon"><?php echo htmlspecialchars($product['ribbon_text']); ?></div>
                    <?php endif; ?>
                    <?php if ($isGift): ?>
                        <div class="gift-badge">🎁 Nemokamai</div>
                    <?php endif; ?>
                    
                    <a href="<?php echo htmlspecialchars($productUrl); ?>">
                        <img src="<?php echo htmlspecialchars($cardImage); ?>" alt="<?php echo htmlspecialchars($product['title']); ?>" loading="lazy">
                    </a>
                    
                    <div class="card-body">
                        <div class="card-category">
                            <?php echo htmlspecialchars($product['category_name'] ?? ''); ?>
                        </div>
                        <h3 class="card-title">
                            <a href="<?php echo htmlspecialchars($productUrl); ?>"><?php echo htmlspecialchars($product['title']); ?></a>
                        </h3>
                        <div class="card-desc">
                            <?php echo htmlspecialchars(mb_substr(strip_tags($product['description']), 0, 80)); ?>...
                        </div>
                        
                        <div class="price-row">
                            <div class="price">
                                <?php if ($priceDisplay['has_discount']): ?>
                                    <span class="old-price"><?php echo number_format($priceDisplay['original'], 2); ?> €</span>
                                <?php endif; ?>
                                <?php echo number_format($priceDisplay['current'], 2); ?> €
                            </div>
                            
                            <form method="post" style="display:flex; gap:8px; margin:0;">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                                
                                <button class="action-btn" type="submit" aria-label="Į krepšelį" title="Į krepšelį">
                                   <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                                </button>
                                
                                <button class="action-btn" name="action" value="wishlist" type="submit" aria-label="Į norų sąrašą" title="Į norų sąrašą">
                                   <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
                                </button>
                            </form>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
      </div>

      <aside>
          <div class="card sidebar-card">
              <h3>Paieška</h3>
              <form method="get" class="search-group">
                <?php if ($selectedSlug): ?>
                    <input type="hidden" name="category" value="<?php echo htmlspecialchars($selectedSlug); ?>">
                <?php endif; ?>
                <input type="text" name="query" placeholder="Ieškoti..." class="form-control" value="<?php echo htmlspecialchars($searchQuery ?? ''); ?>">
                <button type="submit" class="btn-search">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                </button>
              </form>
              <?php if ($searchQuery || $selectedSlug): ?>
                  <div style="margin-top:10px;">
                      <a href="/products.php" style="font-size:13px; color:var(--text-muted); text-decoration:underline;">Valyti visus filtrus</a>
                  </div>
              <?php endif; ?>
          </div>

          <div class="card sidebar-card">
              <h3>Kategorijos</h3>
              <nav class="sidebar-menu">
                  <a href="/products.php" class="<?php echo !$selectedSlug ? 'active' : ''; ?>">
                      <span>Visos prekės</span>
                  </a>
                  <?php foreach ($rootCats as $root): ?>
                      <?php 
                          $subCats = $catsByParent[$root['id']] ?? [];
                          $isActiveRoot = ($selectedSlug === $root['slug']);
                          // Patikriname, ar pasirinkta kategorija yra šios tėvinės kategorijos vaikas
                          $isChildActive = false;
                          foreach ($subCats as $sc) {
                              if ($selectedSlug === $sc['slug']) {
                                  $isChildActive = true;
                                  break;
                              }
                          }
                          $shouldExpand = $isActiveRoot || $isChildActive;
                          $linkClass = $isActiveRoot ? 'active' : '';
                          $queryPart = $searchQuery ? '&query=' . urlencode($searchQuery) : '';
                      ?>
                      <div>
                          <a href="/products.php?category=<?php echo urlencode($root['slug']) . $queryPart; ?>" class="<?php echo $linkClass; ?>">
                              <span><?php echo htmlspecialchars($root['name']); ?></span>
                          </a>
                          <?php if ($subCats && $shouldExpand): ?>
                            <div class="sidebar-submenu">
                                <?php foreach ($subCats as $sub): ?>
                                    <a href="/products.php?category=<?php echo urlencode($sub['slug']) . $queryPart; ?>" 
                                       class="<?php echo ($selectedSlug === $sub['slug']) ? 'active' : ''; ?>">
                                        <?php echo htmlspecialchars($sub['name']); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                          <?php endif; ?>
                      </div>
                  <?php endforeach; ?>
              </nav>
          </div>

          <?php if ($isAdmin): ?>
          <div class="card sidebar-card" style="border: 1px dashed var(--accent);">
              <h3 style="color:var(--accent);">Administravimas</h3>
              <nav class="sidebar-menu">
                  <a href="/admin/products.php">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                      Valdyti produktus
                  </a>
                  <a href="/admin/categories.php">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                      Valdyti kategorijas
                  </a>
              </nav>
          </div>
          <?php endif; ?>

      </aside>
    </div>
  </div>

  <?php renderFooter($pdo); ?>
</body>
</html>
