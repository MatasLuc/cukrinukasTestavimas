<?php
// community_discussions.php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

$pdo = getPdo();
ensureUsersTable($pdo);
ensureCommunityTables($pdo);
tryAutoLogin($pdo);
$user = currentUser();

// --- LOGIKA ---

// 1. Ištraukiame kategorijas iš DB diskusijoms
$stmtCats = $pdo->query("SELECT * FROM community_thread_categories ORDER BY name ASC");
$dbCategories = $stmtCats->fetchAll();
$validCategoryNames = array_column($dbCategories, 'name');

$catFilter = $_GET['cat'] ?? null;
if ($catFilter && !in_array($catFilter, $validCategoryNames)) {
    $catFilter = null;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$where = 'WHERE 1=1';
$params = [];
if ($catFilter) {
    $where .= ' AND c.name = ?';
    $params[] = $catFilter;
}

$countSql = "SELECT COUNT(*) FROM community_threads t 
             LEFT JOIN community_thread_categories c ON t.category_id = c.id 
             $where";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalThreads = $countStmt->fetchColumn();
$totalPages = ceil($totalThreads / $perPage);

// Pagrindinė užklausa (naudojame u.name kaip username)
$sql = "
    SELECT t.*, u.name as username, c.name as category_name,
           (SELECT COUNT(*) FROM community_comments p WHERE p.thread_id = t.id) as reply_count,
           (SELECT created_at FROM community_comments p WHERE p.thread_id = t.id ORDER BY created_at DESC LIMIT 1) as last_reply_at
    FROM community_threads t
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN community_thread_categories c ON t.category_id = c.id
    $where
    ORDER BY COALESCE(last_reply_at, t.created_at) DESC
    LIMIT $perPage OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$threads = $stmt->fetchAll();

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
    }
    * { box-sizing: border-box; }
    body { margin:0; background: var(--bg); color: var(--text-main); font-family: 'Inter', sans-serif; }
    a { color:inherit; text-decoration:none; transition: color .2s; }
    
    .page { max-width: 1200px; margin:0 auto; padding:32px 20px 72px; display:flex; flex-direction:column; gap:32px; }

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

    .filter-bar { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 4px; align-items: center; }
    .filter-chip { padding: 8px 16px; border-radius: 99px; background: #fff; border: 1px solid var(--border); color: var(--text-muted); font-size: 14px; font-weight: 500; white-space: nowrap; }
    .filter-chip:hover { border-color: var(--accent); color: var(--accent); }
    .filter-chip.active { background: var(--accent); color: #fff; border-color: var(--accent); }

    .thread-list { display: flex; flex-direction: column; gap: 16px; }
    .thread-card {
        background: var(--card); border: 1px solid var(--border); border-radius: 16px;
        padding: 20px 24px; display: flex; align-items: center; gap: 20px;
        transition: transform .2s, box-shadow .2s, border-color .2s;
    }
    .thread-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-color: #cbd5e1; }
    .thread-icon { width: 48px; height: 48px; border-radius: 12px; background: #f1f5f9; color: #64748b; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
    
    .thread-info { flex: 1; min-width: 0; }
    .thread-meta { font-size: 12px; color: var(--text-muted); margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
    .thread-category { color: var(--accent); font-weight: 600; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
    
    .thread-title { margin: 0 0 6px; font-size: 18px; font-weight: 700; color: var(--text-main); line-height: 1.4; display: block; text-decoration: none; }
    .thread-title:hover { color: var(--accent); }
    
    .thread-stats { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; min-width: 100px; text-align: right; font-size: 13px; color: var(--text-muted); }
    .stat-count { font-weight: 600; color: var(--text-main); }

    .btn, .btn-outline { padding:10px 20px; border-radius:10px; font-weight:600; font-size:14px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; transition: all .2s; width: 100%; }
    .btn { border:none; background: #0f172a; color:#fff; }
    .btn:hover { background: #1e293b; color:#fff; transform: translateY(-1px); }
    .btn-outline { background: #fff; color: var(--text-main); border: 1px solid var(--border); }
    
    .empty-state { text-align: center; padding: 64px 20px; background: #fff; border-radius: 20px; border: 1px dashed var(--border); }

    @media (max-width: 700px) {
        .hero { flex-direction: column; padding: 24px; align-items: stretch; }
        .hero-card { max-width: 100%; }
        .thread-card { flex-direction: column; align-items: flex-start; gap: 12px; }
        .thread-stats { width: 100%; flex-direction: row; align-items: center; justify-content: space-between; border-top: 1px solid var(--border); padding-top: 12px; margin-top: 4px; }
    }
</style>

<?php renderHeader($pdo, 'community'); ?>

<div class="page">
    <section class="hero">
        <div class="hero-content">
            <div class="pill">💬 Forumas</div>
            <h1>Bendruomenės diskusijos</h1>
            <p>Vieta klausimams, patarimams ir bendravimui. Pasirinkite temą arba sukurkite naują.</p>
        </div>
        
        <div class="hero-card">
            <?php if ($user['id']): ?>
                <h3>Turite klausimų?</h3>
                <p>Pradėkite naują diskusiją ir gaukite atsakymus iš bendruomenės.</p>
                <a href="/community_thread_new.php" class="btn">Kurti naują temą</a>
            <?php else: ?>
                <h3>Prisijunkite</h3>
                <p>Norėdami dalyvauti diskusijose, turite prisijungti.</p>
                <a href="/login.php" class="btn">Prisijunkite</a>
            <?php endif; ?>
        </div>
    </section>

    <div>
        <div class="filter-bar">
            <a href="/community_discussions.php" class="filter-chip <?php echo !$catFilter ? 'active' : ''; ?>">Visos temos</a>
            <?php foreach ($dbCategories as $cat): ?>
                <a href="?cat=<?php echo urlencode($cat['name']); ?>" class="filter-chip <?php echo $catFilter === $cat['name'] ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($cat['name']); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (empty($threads)): ?>
        <div class="empty-state">
            <div style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;">📭</div>
            <h3 style="margin: 0 0 8px; font-size: 18px;">Diskusijų nerasta</h3>
            <p style="color: var(--text-muted); margin: 0 0 24px; font-size: 15px;">Šiuo metu šioje kategorijoje temų kol kas nėra.</p>
            <?php if ($user['id']): ?>
                <a class="btn" href="/community_thread_new.php" style="width:auto;">Kurti temą</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="thread-list">
            <?php foreach ($threads as $t): 
                $replyCount = (int)$t['reply_count'];
                $lastActivity = $t['last_reply_at'] ? $t['last_reply_at'] : $t['created_at'];
            ?>
            <div class="thread-card">
                <div class="thread-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                </div>
                
                <div class="thread-info">
                    <div class="thread-meta">
                        <span class="thread-category"><?php echo htmlspecialchars($t['category_name'] ?: 'Bendra'); ?></span>
                        <span>• <?php echo htmlspecialchars($t['username'] ?: 'Nežinomas'); ?></span>
                        <span>• <?php echo date('Y-m-d', strtotime($t['created_at'])); ?></span>
                    </div>
                    <a href="/community_thread.php?id=<?php echo $t['id']; ?>" class="thread-title">
                        <?php echo htmlspecialchars($t['title']); ?>
                    </a>
                </div>
                
                <div class="thread-stats">
                    <div>Atsakymų: <span class="stat-count"><?php echo $replyCount; ?></span></div>
                    <div style="font-size:12px; color:var(--text-muted);">
                        Paskutinis: <?php echo date('m-d H:i', strtotime($lastActivity)); ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <div style="display:flex; gap:8px; justify-content:center; margin-top:16px;">
                <?php for ($i=1; $i<=$totalPages; $i++): ?>
                    <a href="?page=<?php echo $i; ?><?php echo $catFilter ? '&cat='.urlencode($catFilter) : ''; ?>" 
                       class="btn-outline" 
                       style="width:40px; padding:0; height:40px; <?php echo $i===$page ? 'background:var(--accent); color:#fff; border-color:var(--accent);' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php renderFooter($pdo); ?>
