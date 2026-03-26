<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/helpers.php';

$pdo = getPdo();
$q = trim($_GET['q'] ?? '');
$searchTerm = '%' . $q . '%';

$products = [];
$recipes = [];
$news = [];
$communityListings = [];
$communityThreads = [];
$users = [];

if ($q !== '') {
    // 1. Parduotuvės prekės
    $stmt = $pdo->prepare("SELECT id, title, image_url, price FROM products WHERE title LIKE ? OR description LIKE ? OR subtitle LIKE ? LIMIT 20");
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Receptai
    $stmt = $pdo->prepare("SELECT id, title, image_url, summary FROM recipes WHERE title LIKE ? OR summary LIKE ? OR body LIKE ? LIMIT 20");
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
    $recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Naujienos
    $stmt = $pdo->prepare("SELECT id, title, image_url, summary, created_at FROM news WHERE title LIKE ? OR summary LIKE ? OR body LIKE ? LIMIT 20");
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
    $news = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Bendruomenės prekės
    $stmt = $pdo->prepare("SELECT id, title, image_url, price FROM community_listings WHERE title LIKE ? OR description LIKE ? LIMIT 20");
    $stmt->execute([$searchTerm, $searchTerm]);
    $communityListings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 5. Bendruomenės diskusijos
    $stmt = $pdo->prepare("SELECT id, title, body, created_at FROM community_threads WHERE title LIKE ? OR body LIKE ? LIMIT 20");
    $stmt->execute([$searchTerm, $searchTerm]);
    $communityThreads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 6. Nariai
    $stmt = $pdo->prepare("SELECT id, name, profile_photo, city FROM users WHERE name LIKE ? OR email LIKE ? LIMIT 20");
    $stmt->execute([$searchTerm, $searchTerm]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$totalResults = count($products) + count($recipes) + count($news) + count($communityListings) + count($communityThreads) + count($users);
?>
<!doctype html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paieška: <?php echo htmlspecialchars($q); ?> - Cukrinukas.lt</title>
    <?php echo headerStyles(); ?>
    <style>
        .page-shell { display:flex; flex-direction:column; align-items:center; width:100%; overflow-x:hidden; padding-top: 40px; min-height: 60vh; }
        .section-shell { width: 100%; max-width: 1200px; margin: 0 auto 40px; padding: 0 20px; }
        .search-header { margin-bottom: 32px; border-bottom: 1px solid var(--header-border); padding-bottom: 16px; }
        .search-header h1 { margin: 0 0 8px; font-size: 28px; }
        .search-header p { color: #6b6b7a; margin: 0; }
        
        .category-title { font-size: 20px; margin: 0 0 20px; color: #0b0b0b; display: flex; align-items: center; gap: 8px; }
        .category-title span { background: #eef2ff; color: var(--accent); padding: 2px 8px; border-radius: 12px; font-size: 14px; }
        
        .store-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap:20px; margin-bottom: 40px; }
        .product-card { background:#fff; border:1px solid #e4e7ec; border-radius:16px; overflow:hidden; display:flex; flex-direction:column; transition: all .2s; text-decoration: none; color: inherit; }
        .product-card:hover { border-color:var(--accent); transform:translateY(-3px); box-shadow:0 4px 12px rgba(0,0,0,0.05); }
        .product-card img { width:100%; height:190px; object-fit:contain; padding:16px; }
        .product-card__body { padding:14px; display:flex; flex-direction:column; gap:6px; flex:1; border-top:1px solid #f9fafb; }
        .product-card__title { margin:0; font-size:15px; font-weight:600; line-height:1.4; }
        .price { font-weight:700; color:#111827; font-size:17px; margin-top: auto; }
        
        .news-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap:20px; margin-bottom: 40px; }
        .news-card { background:#fff; border:1px solid #e4e7ec; border-radius:16px; overflow:hidden; transition:transform .2s; text-decoration: none; color: inherit; }
        .news-card:hover { transform:translateY(-3px); border-color:var(--accent); }
        .news-card img { width:100%; height:160px; object-fit:cover; }
        .news-body { padding:16px; }
        .news-title { margin:0 0 6px; font-size:16px; font-weight:600; }
        .news-excerpt { font-size:13px; color:#4b5563; line-height:1.5; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; margin:0; }
        .news-date { font-size:12px; color:#6b6b7a; margin-bottom:8px; display:block; }
        
        .list-group { display: flex; flex-direction: column; gap: 12px; margin-bottom: 40px; }
        .list-item { background: #fff; border: 1px solid #e4e7ec; border-radius: 12px; padding: 16px; display: flex; gap: 16px; align-items: center; text-decoration: none; color: inherit; transition: all .2s; }
        .list-item:hover { border-color: var(--accent); background: #f8fafc; }
        .list-item img { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; background: #f0f0f5; flex-shrink: 0; }
        .list-item-content { flex: 1; min-width: 0; }
        .list-item-title { font-weight: 600; font-size: 16px; margin: 0 0 4px; }
        .list-item-desc { font-size: 14px; color: #6b6b7a; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
        
        .empty-state { text-align: center; padding: 60px 0; color: #6b6b7a; }
        .empty-state svg { opacity: 0.5; margin-bottom: 16px; }
        .empty-state h2 { margin-bottom: 8px; color: #0b0b0b; }
    </style>
</head>
<body>
    <?php renderHeader($pdo, ''); ?>
    <main class="page-shell">
        <section class="section-shell">
            <div class="search-header">
                <h1>Paieškos rezultatai</h1>
                <?php if ($q !== ''): ?>
                    <p>Ieškota frazės: <strong>„<?php echo htmlspecialchars($q); ?>“</strong>. Rasta rezultatų: <?php echo $totalResults; ?></p>
                <?php else: ?>
                    <p>Įveskite paieškos frazę viršuje esančiame laukelyje.</p>
                <?php endif; ?>
            </div>
            
            <?php if ($q !== '' && $totalResults === 0): ?>
                <div class="empty-state">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <h2>Pagal jūsų užklausą nieko nerasta</h2>
                    <p>Pabandykite ieškoti su kitais raktažodžiais arba patikrinkite, ar nėra rašybos klaidų.</p>
                </div>
            <?php endif; ?>

            <?php if (!empty($products)): ?>
                <h2 class="category-title">Prekės <span><?php echo count($products); ?></span></h2>
                <div class="store-grid">
                    <?php foreach ($products as $p): ?>
                        <a href="/produktas/<?php echo slugify($p['title']); ?>-<?php echo $p['id']; ?>" class="product-card">
                            <img src="<?php echo htmlspecialchars($p['image_url'] ?: '/uploads/default.png'); ?>" alt="<?php echo htmlspecialchars($p['title']); ?>">
                            <div class="product-card__body">
                                <h3 class="product-card__title"><?php echo htmlspecialchars($p['title']); ?></h3>
                                <span class="price"><?php echo number_format((float)$p['price'], 2); ?> €</span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($recipes)): ?>
                <h2 class="category-title">Receptai <span><?php echo count($recipes); ?></span></h2>
                <div class="news-grid">
                    <?php foreach ($recipes as $r): ?>
                        <a href="/receptas/<?php echo slugify($r['title']); ?>-<?php echo $r['id']; ?>" class="news-card">
                            <img src="<?php echo htmlspecialchars($r['image_url'] ?: '/uploads/default.png'); ?>" alt="<?php echo htmlspecialchars($r['title']); ?>">
                            <div class="news-body">
                                <h3 class="news-title"><?php echo htmlspecialchars($r['title']); ?></h3>
                                <?php if (!empty($r['summary'])): ?>
                                    <p class="news-excerpt"><?php echo htmlspecialchars($r['summary']); ?></p>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($news)): ?>
                <h2 class="category-title">Naujienos <span><?php echo count($news); ?></span></h2>
                <div class="news-grid">
                    <?php foreach ($news as $n): ?>
                        <a href="/naujiena/<?php echo slugify($n['title']); ?>-<?php echo $n['id']; ?>" class="news-card">
                            <img src="<?php echo htmlspecialchars($n['image_url'] ?: '/uploads/default.png'); ?>" alt="<?php echo htmlspecialchars($n['title']); ?>">
                            <div class="news-body">
                                <span class="news-date"><?php echo date('Y-m-d', strtotime($n['created_at'])); ?></span>
                                <h3 class="news-title"><?php echo htmlspecialchars($n['title']); ?></h3>
                                <?php if (!empty($n['summary'])): ?>
                                    <p class="news-excerpt"><?php echo htmlspecialchars($n['summary']); ?></p>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($communityListings)): ?>
                <h2 class="category-title">Bendruomenės turgelis <span><?php echo count($communityListings); ?></span></h2>
                <div class="store-grid">
                    <?php foreach ($communityListings as $cl): ?>
                        <a href="/community_listing.php?id=<?php echo $cl['id']; ?>" class="product-card">
                            <img src="<?php echo htmlspecialchars($cl['image_url'] ?: '/uploads/default.png'); ?>" alt="<?php echo htmlspecialchars($cl['title']); ?>">
                            <div class="product-card__body">
                                <h3 class="product-card__title"><?php echo htmlspecialchars($cl['title']); ?></h3>
                                <span class="price"><?php echo number_format((float)$cl['price'], 2); ?> €</span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($communityThreads)): ?>
                <h2 class="category-title">Diskusijos <span><?php echo count($communityThreads); ?></span></h2>
                <div class="list-group">
                    <?php foreach ($communityThreads as $t): ?>
                        <a href="/community_thread.php?id=<?php echo $t['id']; ?>" class="list-item">
                            <div class="list-item-content">
                                <h3 class="list-item-title"><?php echo htmlspecialchars($t['title']); ?></h3>
                                <p class="list-item-desc"><?php echo htmlspecialchars(strip_tags($t['body'])); ?></p>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($users)): ?>
                <h2 class="category-title">Nariai <span><?php echo count($users); ?></span></h2>
                <div class="list-group">
                    <?php foreach ($users as $u): ?>
                        <a href="/user_profile.php?id=<?php echo $u['id']; ?>" class="list-item">
                            <img src="<?php echo htmlspecialchars($u['profile_photo'] ?: '/uploads/default_avatar.png'); ?>" alt="<?php echo htmlspecialchars($u['name']); ?>">
                            <div class="list-item-content">
                                <h3 class="list-item-title"><?php echo htmlspecialchars($u['name']); ?></h3>
                                <?php if (!empty($u['city'])): ?>
                                    <p class="list-item-desc">Miestas: <?php echo htmlspecialchars($u['city']); ?></p>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
        </section>
    </main>
    <?php renderFooter($pdo); ?>
</body>
</html>