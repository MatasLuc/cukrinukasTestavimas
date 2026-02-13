<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

$pdo = getPdo();
ensureUsersTable($pdo);
ensureCommunityTables($pdo);
tryAutoLogin($pdo);
$user = currentUser();

// --- LOGIKA ---

// 1. Kategorijos
$stmtCats = $pdo->query("SELECT * FROM community_listing_categories ORDER BY name ASC");
$dbCategories = $stmtCats->fetchAll();
$validCategoryNames = array_column($dbCategories, 'name');

// 2. Filtrai
$catFilter = $_GET['cat'] ?? null;
if ($catFilter && !in_array($catFilter, $validCategoryNames)) {
    $catFilter = null;
}

// Naujas filtras: listing_type (sell, buy)
$typeFilter = $_GET['type'] ?? null; // 'sell' arba 'buy'
if ($typeFilter && !in_array($typeFilter, ['sell', 'buy'])) {
    $typeFilter = null;
}

// 3. SQL konstravimas
$whereParts = ["m.status = 'active'"];
$params = [];

if ($catFilter) {
    $whereParts[] = "c.name = ?";
    $params[] = $catFilter;
}

if ($typeFilter) {
    $whereParts[] = "m.listing_type = ?";
    $params[] = $typeFilter;
}

$whereSql = "WHERE " . implode(' AND ', $whereParts);

// 4. Puslapiavimas
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

// Kiekis
$countSql = "SELECT COUNT(*) 
             FROM community_listings m 
             LEFT JOIN community_listing_categories c ON m.category_id = c.id 
             $whereSql";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalItems = $countStmt->fetchColumn();
$totalPages = ceil($totalItems / $perPage);

// Duomenys
$sql = "
    SELECT m.*, u.name as username, c.name as cat_name
    FROM community_listings m
    LEFT JOIN users u ON m.user_id = u.id
    LEFT JOIN community_listing_categories c ON m.category_id = c.id
    $whereSql
    ORDER BY m.created_at DESC
    LIMIT $perPage OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

echo headerStyles();
?>
<style>
    :root {
      --bg: #f7f7fb;
      --card: #ffffff;
      --border: #e4e7ec;
      --text-main: #0f172a;
      --text-muted: #475467;
      --accent: #2563eb;
      --accent-hover: #1d4ed8;
      --badge-sell-bg: #dbeafe; --badge-sell-text: #1e40af;
      --badge-buy-bg: #fef3c7; --badge-buy-text: #92400e;
    }
    * { box-sizing: border-box; }
    body { margin:0; background: var(--bg); color: var(--text-main); font-family: 'Inter', sans-serif; }
    a { color:inherit; text-decoration:none; transition: color .2s; }
    
    .page { max-width: 1200px; margin:0 auto; padding:32px 20px 72px; display:flex; flex-direction:column; gap:32px; }

    /* Hero Section */
    .hero { 
        background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        border:1px solid #dbeafe; 
        border-radius:24px; 
        padding:40px; 
        display:flex; 
        align-items:center; 
        justify-content:space-between; 
        gap:32px; 
        flex-wrap:wrap; 
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }
    .hero-content { max-width: 600px; flex: 1; }
    .hero h1 { margin:0 0 12px; font-size:32px; color:#1e3a8a; letter-spacing:-0.5px; }
    .hero p { margin:0; color:#1e40af; line-height:1.6; font-size:16px; }
    
    .pill { 
        display:inline-flex; align-items:center; gap:8px; 
        padding:6px 12px; border-radius:999px; 
        background:#fff; border:1px solid #bfdbfe; 
        font-weight:600; font-size:13px; color:#1e40af; 
        margin-bottom: 16px;
    }

    /* Hero Action Card */
    .hero-card {
        background: #fff;
        border: 1px solid rgba(255,255,255,0.8);
        padding: 24px;
        border-radius: 20px;
        width: 100%;
        max-width: 300px;
        box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.15);
        text-align: center;
        flex-shrink: 0;
    }
    .hero-card h3 { margin: 0 0 8px; font-size: 18px; color: var(--text-main); }
    .hero-card p { margin: 0 0 16px; font-size: 13px; color: var(--text-muted); line-height: 1.4; }

    /* Type Toggles (Parduoda / Ieško) */
    .type-tabs {
        display: flex; gap: 4px; background: #fff; padding: 4px; border-radius: 12px;
        border: 1px solid var(--border); width: fit-content; margin-bottom: 16px;
    }
    .type-tab {
        padding: 8px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer;
        color: var(--text-muted);
    }
    .type-tab:hover { background: #f8fafc; color: var(--text-main); }
    .type-tab.active { background: #0f172a; color: #fff; }

    /* Filter Bar */
    .filter-bar {
        display: flex; gap: 10px; overflow-x: auto; padding-bottom: 4px; align-items: center;
    }
    .filter-chip {
        padding: 8px 16px; border-radius: 99px; background: #fff; border: 1px solid var(--border);
        color: var(--text-muted); font-size: 14px; font-weight: 500; white-space: nowrap;
    }
    .filter-chip:hover { border-color: var(--accent); color: var(--accent); }
    .filter-chip.active { background: var(--accent); color: #fff; border-color: var(--accent); }

    /* Market Grid */
    .market-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 24px;
    }

    .item-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 20px;
        overflow: hidden;
        display: flex; flex-direction: column;
        transition: transform .2s, box-shadow .2s;
        height: 100%;
        position: relative;
    }
    .item-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 20px -5px rgba(0,0,0,0.1);
        border-color: #cbd5e1;
    }

    /* Type Badge (Buy/Sell) overlay */
    .type-badge {
        position: absolute; top: 12px; left: 12px;
        padding: 6px 10px; border-radius: 8px; font-size: 12px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.5px; z-index: 2;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .type-badge.sell { background: #fff; color: var(--accent); }
    .type-badge.buy { background: #fff7ed; color: #b45309; border: 1px solid #fed7aa; }

    .item-image {
        height: 200px; width: 100%;
        background: #f1f5f9;
        display: flex; align-items: center; justify-content: center;
        font-size: 48px; color: #cbd5e1;
        overflow: hidden;
        border-bottom: 1px solid var(--border);
        position: relative;
    }
    .item-image img { width: 100%; height: 100%; object-fit: cover; }
    
    .item-body { padding: 20px; flex: 1; display: flex; flex-direction: column; gap: 8px; }
    
    .cat-badge {
        font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-muted);
        letter-spacing: 0.5px; margin-bottom: 2px;
    }

    .item-title { font-size: 17px; font-weight: 700; margin: 0; color: var(--text-main); line-height: 1.3; }
    
    .item-desc {
        font-size: 14px; 
        color: var(--text-muted); 
        line-height: 1.5;
        margin-top: 4px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .item-price { font-size: 18px; font-weight: 700; color: var(--accent); margin-top: auto; padding-top: 12px; }
    .item-price.buy-price { color: #b45309; }
    
    .item-meta { font-size: 13px; color: var(--text-muted); display: flex; justify-content: space-between; align-items: center; margin-top: 8px; border-top: 1px solid #f1f5f9; padding-top: 12px; }

    /* Buttons */
    .btn { 
        padding:10px 20px; border-radius:10px; border:none;
        background: #0f172a;
        color:#fff; font-weight:600; font-size:14px;
        cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center;
        transition: all .2s; width: 100%;
    }
    .btn:hover { background: #1e293b; transform: translateY(-1px); }
    .btn-outline { 
        padding:8px 12px; border-radius:8px; border: 1px solid var(--border);
        background: #fff; color: var(--text-main); text-decoration: none; display: inline-block;
    }

    .empty-state {
        grid-column: 1 / -1;
        text-align: center; padding: 64px 20px;
        background: #fff; border-radius: 20px; border: 1px dashed var(--border);
    }

    @media (max-width: 700px) {
        .hero { padding: 24px; flex-direction: column; align-items: stretch; }
        .hero-card { max-width: 100%; }
    }
</style>

<?php renderHeader($pdo, 'community'); ?>

<div class="page">
    <section class="hero">
        <div class="hero-content">
            <div class="pill">🛍️ Turgelis</div>
            <h1>Bendruomenės turgus</h1>
            <p>Vieta pirkti, parduoti ar mainytis priemonėmis ir paslaugomis.</p>
        </div>
        <div class="hero-card">
            <?php if ($user['id']): ?>
                <h3>Nori įdėti skelbimą?</h3>
                <p>Galite parduoti daiktus arba įkelti paieškos skelbimą.</p>
                <a href="/community_listing_new.php" class="btn">Įdėti skelbimą</a>
                <div style="margin-top:12px;">
                    <a href="/account.php" style="font-size:13px; color:var(--text-muted); text-decoration:underline;">Mano skelbimai</a>
                </div>
            <?php else: ?>
                <h3>Prisijunkite</h3>
                <p>Norėdami dėti skelbimus ar matyti kontaktus, turite prisijungti.</p>
                <a href="/login.php" class="btn">Prisijunkite</a>
            <?php endif; ?>
        </div>
    </section>

    <div>
        <div class="type-tabs">
            <a href="?<?php echo http_build_query(array_merge($_GET, ['type' => null, 'page' => 1])); ?>" 
               class="type-tab <?php echo !$typeFilter ? 'active' : ''; ?>">Visi</a>
            
            <a href="?<?php echo http_build_query(array_merge($_GET, ['type' => 'sell', 'page' => 1])); ?>" 
               class="type-tab <?php echo $typeFilter === 'sell' ? 'active' : ''; ?>">Parduoda</a>
            
            <a href="?<?php echo http_build_query(array_merge($_GET, ['type' => 'buy', 'page' => 1])); ?>" 
               class="type-tab <?php echo $typeFilter === 'buy' ? 'active' : ''; ?>">Ieško</a>
        </div>

        <div class="filter-bar">
            <a href="?<?php echo http_build_query(array_merge($_GET, ['cat' => null, 'page' => 1])); ?>" 
               class="filter-chip <?php echo !$catFilter ? 'active' : ''; ?>">Visos kategorijos</a>
            <?php foreach ($dbCategories as $cat): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['cat' => $cat['name'], 'page' => 1])); ?>" 
                   class="filter-chip <?php echo $catFilter === $cat['name'] ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($cat['name']); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (empty($items)): ?>
        <div class="empty-state">
            <div style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;">🔍</div>
            <h3 style="margin: 0 0 8px; font-size: 18px;">Skelbimų nerasta</h3>
            <p style="color: var(--text-muted); margin: 0 0 24px; font-size: 15px;">Pagal pasirinktus filtrus nieko neradome.</p>
            <?php if ($user['id']): ?>
                <a class="btn" href="/community_listing_new.php" style="width:auto;">Įdėti skelbimą</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="market-grid">
            <?php foreach ($items as $item): 
                $catName = !empty($item['cat_name']) ? $item['cat_name'] : 'Kita';
                $listingType = $item['listing_type'] ?? 'sell';
                
                // Aprašymo trumpinimas
                $desc = strip_tags($item['description']);
                if (mb_strlen($desc) > 90) {
                    $desc = mb_substr($desc, 0, 90) . '...';
                }

                $itemUrl = '/community_listing.php?id=' . $item['id'];
            ?>
            <article class="item-card">
                <?php if ($listingType === 'buy'): ?>
                    <div class="type-badge buy">Ieškau</div>
                <?php else: ?>
                    <div class="type-badge sell">Parduodu</div>
                <?php endif; ?>

                <a href="<?php echo $itemUrl; ?>" class="item-image">
                    <?php if (!empty($item['image_url'])): ?>
                        <img src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                    <?php else: ?>
                        <span style="opacity:0.3;"><?php echo $listingType === 'buy' ? '🕵️' : '📷'; ?></span>
                    <?php endif; ?>
                </a>
                <div class="item-body">
                    <span class="cat-badge">
                        #<?php echo htmlspecialchars($catName); ?>
                    </span>
                    <a href="<?php echo $itemUrl; ?>" class="item-title">
                        <?php echo htmlspecialchars($item['title']); ?>
                    </a>
                    
                    <div class="item-desc">
                        <?php echo htmlspecialchars($desc); ?>
                    </div>
                    
                    <div class="item-price <?php echo $listingType === 'buy' ? 'buy-price' : ''; ?>">
                        <?php 
                        if ($listingType === 'buy') {
                            echo ($item['price'] > 0) ? 'Biudžetas: ' . number_format($item['price'], 2) . ' €' : 'Kaina sutartinė';
                        } else {
                            echo ($item['price'] > 0) ? number_format($item['price'], 2) . ' €' : 'Nemokamai / Sutartinė';
                        }
                        ?>
                    </div>
                    
                    <div class="item-meta">
                        <span>👤 <?php echo htmlspecialchars($item['username'] ?: 'Narys'); ?></span>
                        <span><?php echo date('m-d', strtotime($item['created_at'])); ?></span>
                    </div>
                    <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] != $item['user_id']): ?>
                        <form action="cart.php" method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="add_community">
                            <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="fas fa-shopping-cart"></i> Į krepšelį
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <div style="display:flex; gap:8px; justify-content:center; margin-top:32px;">
                <?php for ($i=1; $i<=$totalPages; $i++): 
                    $qs = $_GET;
                    $qs['page'] = $i;
                ?>
                    <a href="?<?php echo http_build_query($qs); ?>" 
                       class="btn-outline" 
                       style="<?php echo $i===$page ? 'background:var(--accent); color:#fff; border-color:var(--accent);' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php renderFooter($pdo); ?>
