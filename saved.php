<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require_once __DIR__ . '/helpers.php'; // Būtina slugify funkcijai

if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$pdo = getPdo();
ensureUsersTable($pdo);
ensureProductsTable($pdo);
ensureNewsTable($pdo);
ensureRecipesTable($pdo);
ensureSavedContentTables($pdo);
ensureCartTables($pdo);
ensureOrdersTables($pdo);
ensureNavigationTable($pdo);
tryAutoLogin($pdo);

$userId = (int)$_SESSION['user_id'];
$messages = [];

// 1. Handle Removal (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $removeType = $_POST['remove_type'] ?? '';
    $removeId = (int)($_POST['remove_id'] ?? 0);
    if ($removeType && $removeId) {
        removeSavedItem($pdo, $userId, $removeType, $removeId);
        $messages[] = 'Įrašas sėkmingai pašalintas.';
    }
}

// 2. Fetch All Saved Items
$saved = getSavedItems($pdo, $userId);
$totalCount = count($saved);

// 3. Handle Filtering (GET)
$filter = $_GET['filter'] ?? 'all';
$validFilters = ['product', 'recipe', 'news'];

if (in_array($filter, $validFilters)) {
    $saved = array_filter($saved, fn($i) => $i['item_type'] === $filter);
}

// 4. Extract IDs only for the filtered items (Optimization)
$productIds = array_map('intval', array_column(array_filter($saved, fn($i) => $i['item_type'] === 'product'), 'item_id'));
$newsIds = array_map('intval', array_column(array_filter($saved, fn($i) => $i['item_type'] === 'news'), 'item_id'));
$recipeIds = array_map('intval', array_column(array_filter($saved, fn($i) => $i['item_type'] === 'recipe'), 'item_id'));

// 5. Fetch Details
$products = [];
if ($productIds) {
    $in = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare("SELECT id, title, subtitle, image_url, price, sale_price FROM products WHERE id IN ($in)");
    $stmt->execute($productIds);
    foreach ($stmt->fetchAll() as $row) {
        $products[(int)$row['id']] = $row;
    }
}

$news = [];
if ($newsIds) {
    $in = implode(',', array_fill(0, count($newsIds), '?'));
    $stmt = $pdo->prepare("SELECT id, title, image_url FROM news WHERE id IN ($in)");
    $stmt->execute($newsIds);
    foreach ($stmt->fetchAll() as $row) {
        $news[(int)$row['id']] = $row;
    }
}

$recipes = [];
if ($recipeIds) {
    $in = implode(',', array_fill(0, count($recipeIds), '?'));
    $stmt = $pdo->prepare("SELECT id, title, image_url FROM recipes WHERE id IN ($in)");
    $stmt->execute($recipeIds);
    foreach ($stmt->fetchAll() as $row) {
        $recipes[(int)$row['id']] = $row;
    }
}

function priceDisplay(array $row): string {
    $base = (float)$row['price'];
    $sale = $row['sale_price'] !== null ? (float)$row['sale_price'] : null;
    if ($sale !== null && $sale >= 0) {
        return '<span style="color:#2563eb; font-weight:700;">' . number_format($sale, 2) . " €</span> <span style=\"text-decoration:line-through; color:#94a3b8; font-size:13px; margin-left:6px;\">" . number_format($base, 2) . ' €</span>';
    }
    return '<span style="color:#0f172a; font-weight:700;">' . number_format($base, 2) . ' €</span>';
}
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mano išsaugoti | Cukrinukas.lt</title>
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
      --accent-light: #eff6ff;
    }
    body { margin:0; background: var(--bg); color: var(--text-main); font-family:'Inter', sans-serif; }
    a { color:inherit; text-decoration:none; }
    * { box-sizing:border-box; }
    
    /* Pakeistas plotis į 1200px ir tarpai pagal news.php */
    .page { max-width:1200px; margin:0 auto; padding:32px 20px 72px; display:flex; flex-direction:column; gap:28px; }

    /* Hero Section */
    .hero { 
        background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        border:1px solid #dbeafe; 
        border-radius:24px; 
        padding:32px; 
        display:flex; 
        justify-content:space-between; 
        gap:24px; 
        flex-wrap:wrap; 
        align-items:center; 
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }
    .hero h1 { margin:0 0 8px; font-size:28px; color:#1e3a8a; letter-spacing:-0.5px; }
    .hero p { margin:0; color:#1e40af; line-height:1.5; max-width:520px; font-size:15px; }
    .hero .pill { 
        display:inline-flex; align-items:center; gap:8px; 
        padding:6px 12px; border-radius:999px; 
        background:#fff; border:1px solid #bfdbfe; 
        font-weight:600; font-size:13px; color:#1e40af; 
        margin-bottom: 12px;
    }

    /* Filter Bar */
    .filter-bar { display:flex; gap:8px; overflow-x:auto; padding-bottom:4px; margin-bottom: 8px; }
    .filter-btn { 
        padding: 8px 16px; 
        border-radius: 999px; 
        border: 1px solid var(--border);
        background: #fff;
        color: var(--text-muted);
        font-weight: 600;
        font-size: 14px;
        transition: all .2s;
        cursor: pointer;
        white-space: nowrap;
    }
    .filter-btn:hover { background: #f1f5f9; color: var(--text-main); }
    .filter-btn.active { 
        background: var(--accent); 
        color: #fff; 
        border-color: var(--accent); 
    }

    /* Grid */
    .grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(280px,1fr)); gap:20px; }
    
    /* Card */
    .card { 
        background:var(--card); 
        border:1px solid var(--border); 
        border-radius:20px; 
        padding:16px; 
        box-shadow: 0 2px 4px -2px rgba(0, 0, 0, 0.05); 
        display:flex; flex-direction:column; gap:12px;
        transition: transform .2s, box-shadow .2s;
    }
    .card:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05); border-color: #cbd5e1; }
    
    .card-img-container {
        position: relative;
        width: 100%;
        height: 180px;
        border-radius: 14px;
        overflow: hidden;
        border: 1px solid #f1f5f9;
    }
    .card img { width:100%; height:100%; object-fit:cover; transition: transform .3s; }
    .card:hover img { transform: scale(1.05); }
    
    .badge {
        position: absolute; top: 10px; left: 10px;
        padding: 4px 10px; border-radius: 8px;
        font-size: 11px; font-weight: 700; text-transform: uppercase;
        background: rgba(255,255,255,0.9); backdrop-filter: blur(4px);
        color: var(--text-main);
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .badge.product { color: #2563eb; }
    .badge.recipe { color: #059669; }
    .badge.news { color: #d97706; }

    .card-content { flex: 1; display: flex; flex-direction: column; gap: 6px; }
    .card-title { font-weight:700; font-size:16px; color:var(--text-main); line-height:1.4; }
    .card-meta { font-size:13px; color: var(--text-muted); }

    /* Actions */
    .actions { display:flex; gap:10px; margin-top: auto; padding-top: 12px; border-top: 1px solid #f1f5f9; }
    
    .btn { 
        padding:10px 16px; border-radius:10px; border:none; 
        background: #0f172a; color:#fff; font-weight:600; font-size:13px;
        cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center;
        transition: all .2s; flex: 1;
    }
    .btn:hover { 
        background: #1e293b; 
        color: #ffffff !important; /* FIX: Priverstinė balta spalva */
        transform: translateY(-1px); 
    }
    
    .btn-outline {
        padding:10px 16px; border-radius:10px;
        background: #fff; color: #ef4444; border: 1px solid #e5e7eb;
        font-weight:600; font-size:13px; cursor: pointer;
        display:inline-flex; align-items:center; justify-content:center;
        transition: all .2s;
    }
    .btn-outline:hover { background: #fef2f2; border-color: #fca5a5; }

    /* Alerts */
    .notice { padding:12px 16px; border-radius:12px; font-size:14px; display:flex; gap:10px; align-items:center; }
    .notice.success { background: #ecfdf5; border: 1px solid #d1fae5; color: #065f46; }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        background: #fff;
        border-radius: 20px;
        border: 1px solid var(--border);
    }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'saved'); ?>
  
  <div class="page">
    <section class="hero">
      <div>
        <div class="pill">⭐ Išsaugoti įrašai</div>
        <h1>Mano kolekcija</h1>
        <p>Viskas, kas jums patiko, vienoje vietoje. Filtruokite pagal kategorijas ir greitai raskite tai, ko ieškote.</p>
      </div>
      <div style="background:rgba(255,255,255,0.6); padding:12px 20px; border-radius:12px; border:1px solid rgba(255,255,255,0.8); text-align:center;">
         <span style="display:block; font-weight:800; font-size:20px; color:#1e3a8a;"><?php echo $totalCount; ?></span>
         <span style="font-size:12px; color:#1e40af; font-weight:600;">Iš viso įrašų</span>
      </div>
    </section>

    <?php foreach ($messages as $msg): ?>
      <div class="notice success">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
        <?php echo htmlspecialchars($msg); ?>
      </div>
    <?php endforeach; ?>

    <div class="filter-bar">
        <a href="?filter=all" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">Visi</a>
        <a href="?filter=product" class="filter-btn <?php echo $filter === 'product' ? 'active' : ''; ?>">Produktai</a>
        <a href="?filter=recipe" class="filter-btn <?php echo $filter === 'recipe' ? 'active' : ''; ?>">Receptai</a>
        <a href="?filter=news" class="filter-btn <?php echo $filter === 'news' ? 'active' : ''; ?>">Naujienos</a>
    </div>

    <?php if (!$saved): ?>
      <div class="empty-state">
        <div style="font-size: 48px; margin-bottom: 16px;">🔖</div>
        <h3 style="margin: 0 0 8px; font-size: 18px;">
            <?php echo $filter === 'all' ? 'Kol kas nieko neišsaugojote' : 'Šioje kategorijoje įrašų nėra'; ?>
        </h3>
        <p class="muted" style="margin: 0 0 24px; font-size: 15px;">Atraskite jums patinkančius dalykus ir pažymėkite juos.</p>
        <a class="btn" href="/products.php" style="display:inline-flex; width:auto; padding: 12px 24px;">Pradėti naršyti</a>
      </div>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($saved as $item): ?>
          <?php
            $type = $item['item_type'];
            $ref = null;
            $link = '#';
            $priceHtml = '';
            
            // PAKEITIMAS: SEO nuorodų generavimas
            if ($type === 'product' && isset($products[$item['item_id']])) {
                $ref = $products[$item['item_id']];
                $link = '/produktas/' . slugify($ref['title']) . '-' . (int)$ref['id'];
                $priceHtml = priceDisplay($ref);
                $badgeClass = 'product';
                $badgeLabel = 'Produktas';
            } elseif ($type === 'news' && isset($news[$item['item_id']])) {
                $ref = $news[$item['item_id']];
                $link = '/naujiena/' . slugify($ref['title']) . '-' . (int)$ref['id'];
                $badgeClass = 'news';
                $badgeLabel = 'Naujiena';
            } elseif ($type === 'recipe' && isset($recipes[$item['item_id']])) {
                $ref = $recipes[$item['item_id']];
                $link = '/receptas/' . slugify($ref['title']) . '-' . (int)$ref['id'];
                $badgeClass = 'recipe';
                $badgeLabel = 'Receptas';
            }
            if (!$ref) { continue; }
          ?>
          <div class="card">
            <div class="card-img-container">
                <div class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($badgeLabel); ?></div>
                <?php if (!empty($ref['image_url'])): ?>
                    <a href="<?php echo htmlspecialchars($link); ?>">
                      <img src="<?php echo htmlspecialchars($ref['image_url']); ?>" alt="<?php echo htmlspecialchars($ref['title']); ?>">
                    </a>
                <?php endif; ?>
            </div>
            
            <div class="card-content">
                <div class="card-title">
                    <a href="<?php echo htmlspecialchars($link); ?>"><?php echo htmlspecialchars($ref['title']); ?></a>
                </div>
                <?php if ($type === 'product'): ?>
                    <div><?php echo $priceHtml; ?></div>
                <?php else: ?>
                    <div class="card-meta">Spustelėkite peržiūrai</div>
                <?php endif; ?>
            </div>

            <div class="actions">
              <a class="btn" href="<?php echo htmlspecialchars($link); ?>">Peržiūrėti</a>
              <form method="post" style="margin:0;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="remove_type" value="<?php echo htmlspecialchars($type); ?>">
                <input type="hidden" name="remove_id" value="<?php echo (int)$item['item_id']; ?>">
                <button class="btn-outline" type="submit" title="Pašalinti iš išsaugotų">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                </button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php renderFooter($pdo); ?>
</body>
</html>
