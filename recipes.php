<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require_once __DIR__ . '/helpers.php'; // Būtina slugify funkcijai

$pdo = getPdo();
ensureRecipesTable($pdo);
ensureSavedContentTables($pdo);
ensureAdminAccount($pdo);
tryAutoLogin($pdo);
$siteContent = getSiteContent($pdo);

// Išsaugoti receptą
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recipe_id'])) {
    validateCsrfToken();
    if (empty($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
    saveItemForUser($pdo, (int)$_SESSION['user_id'], 'recipe', (int)$_POST['recipe_id']);
    header('Location: /saved.php');
    exit;
}

// 1. Gauname kategorijas
$activeCategories = $pdo->query("
    SELECT c.id, c.name, COUNT(r.recipe_id) as count 
    FROM recipe_categories c 
    JOIN recipe_category_relations r ON r.category_id = c.id 
    GROUP BY c.id 
    HAVING count > 0 
    ORDER BY c.name ASC
")->fetchAll();

// 2. Filtravimas
$selectedCatId = isset($_GET['cat']) ? (int)$_GET['cat'] : null;

$sql = 'SELECT r.id, r.title, r.image_url, r.summary, r.body, r.created_at, r.visibility 
        FROM recipes r ';
$params = [];

if ($selectedCatId) {
    $sql .= 'JOIN recipe_category_relations rel ON r.id = rel.recipe_id WHERE rel.category_id = ? ';
    $params[] = $selectedCatId;
}

$sql .= 'ORDER BY r.created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$recipes = $stmt->fetchAll();

$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = !empty($_SESSION['is_admin']);

// Statistika hero sekcijai
$totalRecipesCount = count($recipes);
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Receptai | Cukrinukas</title>
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
    
    /* Layout struktūra pagal news.php/orders.php */
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

    /* Recipes Grid */
    .recipe-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap:20px; }
    
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
    }
    .card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border-color: #cbd5e1; }

    .card img { width: 100%; height: 200px; object-fit: cover; border-bottom: 1px solid var(--border); }
    
    .card-body { padding: 20px; display: flex; flex-direction: column; gap: 12px; flex-grow: 1; }
    
    .card-meta { font-size: 12px; color: var(--text-muted); display:flex; gap:10px; align-items:center; }
    .card-title { margin: 0; font-size: 18px; line-height: 1.4; color: var(--text-main); font-weight: 700; }
    .card-excerpt { 
        margin: 0; color: var(--text-muted); font-size: 14px; line-height: 1.5; 
        display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;
        flex-grow: 1;
    }

    .card-footer { 
        padding-top: 16px; margin-top: auto; border-top: 1px solid var(--border); 
        display: flex; justify-content: space-between; align-items: center; 
    }

    /* Sidebar Styles */
    .sidebar-card { padding: 24px; margin-bottom: 24px; }
    .sidebar-card h3 { margin:0 0 16px; font-size:16px; color: var(--text-main); font-weight: 700; }
    .sidebar-menu { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:8px; }
    .sidebar-menu a { 
        display:flex; align-items:center; justify-content: space-between;
        padding:10px 12px; border-radius:10px; 
        color: var(--text-muted); font-size:14px; font-weight:500;
        transition: all .2s;
    }
    .sidebar-menu a:hover { background: #f8fafc; color: var(--text-main); }
    .sidebar-menu a.active { background: #eff6ff; color: var(--accent); font-weight: 600; }
    .count-badge { background: #e2e8f0; color: #475467; font-size: 11px; padding: 2px 8px; border-radius: 99px; }
    .active .count-badge { background: #bfdbfe; color: #1e40af; }

    /* Buttons */
    .btn-text { font-size: 14px; font-weight: 600; color: var(--accent); }
    .btn-text:hover { color: var(--accent-hover); text-decoration: underline; }
    
    .action-btn {
        width: 36px; height: 36px;
        border-radius: 10px;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        transition: all .2s;
        background: #f8fafc;
        border: 1px solid var(--border);
        color: #94a3b8;
        font-size: 18px;
        line-height: 1;
    }
    .action-btn:hover {
        border-color: #fca5a5;
        color: #ef4444;
        background: #fef2f2;
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
        .recipe-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'recipes'); ?>

  <div class="page">
    <section class="hero">
      <div>
        <div class="pill"><?php echo htmlspecialchars($siteContent['recipes_hero_pill'] ?? '🥗 Sveika mityba'); ?></div>
        <h1><?php echo htmlspecialchars($siteContent['recipes_hero_title'] ?? 'Receptai, kurie įkvepia'); ?></h1>
        <p><?php echo htmlspecialchars($siteContent['recipes_hero_body'] ?? 'Subalansuoti patiekalai diabeto kontrolei ir gerai savijautai.'); ?></p>
      </div>
      <div class="stat-card">
        <strong><?php echo $totalRecipesCount; ?></strong>
        <span>Receptų kolekcija</span>
      </div>
    </section>

    <div class="layout">
      <div>
        <div class="section-header">
           <h2><?php echo $selectedCatId ? 'Kategorijos receptai' : 'Visi receptai'; ?></h2>
           <span><?php echo count($recipes); ?></span>
        </div>

        <?php if (empty($recipes)): ?>
            <div class="empty-state">
                <div style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;">🥗</div>
                <h3 style="margin: 0 0 8px; font-size: 18px;">Receptų nerasta</h3>
                <p style="color: var(--text-muted); margin: 0 0 24px; font-size: 15px;">Šioje kategorijoje receptų kol kas nėra.</p>
                <a class="btn-text" href="/recipes.php">Grįžti į visus receptus</a>
            </div>
        <?php else: ?>
            <div class="recipe-grid">
                <?php foreach ($recipes as $r): 
                    $recipeUrl = '/receptas/' . slugify($r['title']) . '-' . (int)$r['id'];
                ?>
                <article class="card">
                    <a href="<?php echo htmlspecialchars($recipeUrl); ?>">
                        <img src="<?php echo htmlspecialchars($r['image_url']); ?>" alt="<?php echo htmlspecialchars($r['title']); ?>">
                    </a>
                    <div class="card-body">
                        <div class="card-meta">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            <?php echo date('Y-m-d', strtotime($r['created_at'])); ?>
                        </div>
                        <h3 class="card-title">
                            <a href="<?php echo htmlspecialchars($recipeUrl); ?>"><?php echo htmlspecialchars($r['title']); ?></a>
                        </h3>
                        <div class="card-excerpt">
                            <?php 
                                $excerpt = trim($r['summary'] ?? '');
                                if (!$excerpt) $excerpt = strip_tags($r['body']);
                                if (mb_strlen($excerpt) > 120) $excerpt = mb_substr($excerpt, 0, 120) . '...';
                                echo htmlspecialchars($excerpt);
                            ?>
                        </div>
                        <div class="card-footer">
                            <a class="btn-text" href="<?php echo htmlspecialchars($recipeUrl);?>">Gaminti →</a>
                            
                            <?php if ($isLoggedIn): ?>
                                <form method="post" style="margin:0;">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="recipe_id" value="<?php echo (int)$r['id']; ?>">
                                    <button class="action-btn" type="submit" title="Išsaugoti">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
                                    </button>
                                </form>
                            <?php else: ?>
                                <a class="action-btn" href="/login.php" title="Prisijunkite">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
      </div>

      <aside>
          <div class="card sidebar-card">
              <h3>Receptų kategorijos</h3>
              <nav class="sidebar-menu">
                  <a href="/recipes.php" class="<?php echo $selectedCatId === null ? 'active' : ''; ?>">
                      <span>Visi receptai</span>
                  </a>
                  <?php foreach ($activeCategories as $cat): ?>
                      <a href="/recipes.php?cat=<?php echo $cat['id']; ?>" class="<?php echo $selectedCatId === $cat['id'] ? 'active' : ''; ?>">
                          <span><?php echo htmlspecialchars($cat['name']); ?></span>
                          <span class="count-badge"><?php echo (int)$cat['count']; ?></span>
                      </a>
                  <?php endforeach; ?>
              </nav>
          </div>

          <?php if ($isAdmin): ?>
          <div class="card sidebar-card" style="border: 1px dashed var(--accent);">
              <h3 style="color:var(--accent);">Administravimas</h3>
              <nav class="sidebar-menu">
                  <a href="/recipe_create.php">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                      Pridėti naują receptą
                  </a>
                  <a href="/admin.php?view=content">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                      Valdyti turinį
                  </a>
              </nav>
          </div>
          <?php endif; ?>

          <div class="card sidebar-card" style="background: #f8fafc; border: 1px solid var(--border);">
              <h3>Turite savo receptą?</h3>
              <p style="font-size:13px; color:var(--text-muted); line-height:1.5; margin-bottom:12px;">
                  Pasidalinkite savo mėgstamiausiais receptais su bendruomene!
              </p>
              <a href="/contact.php" style="font-size:13px; font-weight:600; color:var(--accent);">Susisiekti su mumis →</a>
          </div>
      </aside>
    </div>
  </div>

  <?php renderFooter($pdo); ?>
</body>
</html>
