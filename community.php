<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

$pdo = getPdo();
ensureUsersTable($pdo);
ensureCommunityTables($pdo);
ensureNavigationTable($pdo);
tryAutoLogin($pdo);
$user = currentUser();

// Gauname 4 naujausius skelbimus iš turgaus
$marketSql = "
    SELECT m.*, u.name as username, c.name as cat_name
    FROM community_listings m
    LEFT JOIN users u ON m.user_id = u.id
    LEFT JOIN community_listing_categories c ON m.category_id = c.id
    WHERE m.status IN ('active', 'sold')
    ORDER BY m.created_at DESC
    LIMIT 4
";
$marketItems = $pdo->query($marketSql)->fetchAll();

$messages = [];
$errors = [];
if (!empty($_SESSION['flash_success'])) {
    $messages[] = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $errors[] = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
?>
<!doctype html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bendruomenė - Cukrinukas.lt</title>
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
    }
    * { box-sizing: border-box; }
    body { margin:0; background: var(--bg); color: var(--text-main); font-family: 'Inter', sans-serif; }
    a { color:inherit; text-decoration:none; transition: color .2s; }
    
    .page { max-width: 1200px; margin:0 auto; padding:32px 20px 72px; display:flex; flex-direction:column; gap:40px; }

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
        max-width: 320px;
        box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.15);
        text-align: center;
        flex-shrink: 0;
    }
    .hero-card h3 { margin: 0 0 8px; font-size: 18px; color: var(--text-main); }
    .hero-card p { margin: 0 0 16px; font-size: 13px; color: var(--text-muted); line-height: 1.4; }
    
    .hero-buttons { display: flex; flex-direction: column; gap: 10px; }

    /* Cards */
    .card { 
        background:var(--card); 
        border:1px solid var(--border); 
        border-radius:20px; 
        overflow: hidden;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        transition: transform .2s, box-shadow .2s;
        height: 100%;
        display: flex; flex-direction: column;
    }
    .card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border-color: #cbd5e1; }
    
    .card-body { padding: 32px; flex-grow: 1; display: flex; flex-direction: column; gap: 16px; }

    /* Navigacijos tinklelis */
    .nav-grid { 
        display:grid; 
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); 
        gap:24px; 
    }
    
    .nav-card-icon {
        width: 56px; height: 56px; 
        background: #eff6ff; border-radius: 14px; 
        display: flex; align-items: center; justify-content: center; 
        font-size: 28px; margin-bottom: 8px;
    }

    /* Info Grid */
    .info-grid { 
        display:grid; 
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); 
        gap:24px; 
    }

    .rule-list { margin: 0; padding-left: 20px; color: var(--text-muted); line-height: 1.6; font-size: 15px; }
    .rule-list li { margin-bottom: 8px; }

    /* Buttons */
    .btn, .btn-outline { 
        padding:10px 20px; border-radius:10px; 
        font-weight:600; font-size:14px;
        cursor:pointer; text-decoration:none; 
        display:inline-flex; align-items:center; justify-content:center;
        transition: all .2s;
        width: 100%;
    }
    .btn { border:none; background: #0f172a; color:#fff; }
    .btn:hover { background: #1e293b; color:#fff; transform: translateY(-1px); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
    
    .btn-outline { background: #fff; color: var(--text-main); border: 1px solid var(--border); }
    .btn-outline:hover { border-color: var(--accent); color: var(--accent); background: #f8fafc; }

    /* Messages */
    .notice { padding:12px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; gap:10px; align-items:center; }
    .success { background: #ecfdf5; border: 1px solid #d1fae5; color: #065f46; }
    .error { background: #fef2f2; border: 1px solid #fee2e2; color: #991b1b; }

    .help-footer { text-align: center; margin-top: 10px; font-size: 14px; color: var(--text-muted); }
    .help-footer a { color: var(--accent); font-weight: 600; text-decoration: underline; }

    /* Minimalist Market Preview Grid */
    .market-preview-section { margin-top: 8px; }
    .market-preview-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .market-preview-header h2 { font-size: 22px; color: var(--text-main); margin: 0; }
    .market-preview-header a { font-size: 14px; color: var(--accent); font-weight: 600; }
    .market-preview-header a:hover { text-decoration: underline; }
    
    .market-preview-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 20px;
    }
    .preview-card {
        background: var(--card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden;
        display: flex; flex-direction: column; transition: transform .2s, box-shadow .2s;
        text-decoration: none; color: inherit; height: 100%;
    }
    .preview-card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border-color: #cbd5e1; }
    .preview-image {
        height: 160px; background: #f1f5f9; display: flex; align-items: center; justify-content: center;
        font-size: 32px; color: #cbd5e1; position: relative; border-bottom: 1px solid var(--border);
    }
    .preview-image img { width: 100%; height: 100%; object-fit: cover; }
    .preview-badge {
        position: absolute; top: 10px; left: 10px; padding: 4px 8px; border-radius: 6px;
        font-size: 11px; font-weight: 700; text-transform: uppercase; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .preview-badge.sell { color: var(--accent); }
    .preview-badge.buy { color: #b45309; background: #fff7ed; border: 1px solid #fed7aa; }
    .preview-body { padding: 16px; display: flex; flex-direction: column; gap: 6px; flex: 1; }
    .preview-title { font-size: 15px; font-weight: 600; margin: 0; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .preview-price { font-size: 16px; font-weight: 700; color: var(--accent); margin-top: auto; }
    .preview-price.buy-price { color: #b45309; }

    @media (max-width: 768px) {
        .hero { padding: 24px; flex-direction: column; align-items: stretch; }
        .hero-card { max-width: 100%; }
        .nav-grid, .info-grid { grid-template-columns: 1fr; }
    }
</style>
</head>
<body>

<?php renderHeader($pdo, 'community'); ?>

<div class="page">
  <section class="hero">
    <div class="hero-content">
      <div class="pill">👥 Bendruomenė</div>
      <h1>Pasikalbėkime ir dalinkimės</h1>
      <p>Čia susitinka žmonės diskutuoti, padėti vieni kitiems ir sąžiningai prekiauti tarpusavyje. Prisijunk prie mūsų augančios bendruomenės.</p>
    </div>
    
    <div class="hero-card">
      <?php if ($user['id']): ?>
          <h3>Sveiki, <?php echo htmlspecialchars($user['name'] ?? ''); ?>! 👋</h3>
          <p>Dalyvaukite diskusijose ar valdykite savo profilį.</p>
          <div class="hero-buttons">
              <a class="btn" href="/community_thread_new.php">Kurti naują temą</a>
              <a class="btn-outline" href="/account.php">Mano profilis</a>
          </div>
      <?php else: ?>
          <h3>Prisijunk prie mūsų</h3>
          <p>Norėdami kurti temas ar prekiauti, turite prisijungti.</p>
          <div class="hero-buttons">
              <a class="btn" href="/login.php">Prisijunkite</a>
              <a class="btn-outline" href="/register.php">Registruotis</a>
          </div>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($messages || $errors): ?>
    <div>
        <?php foreach ($messages as $msg): ?>
            <div class="notice success">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endforeach; ?>
        <?php foreach ($errors as $err): ?>
            <div class="notice error">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <?php echo htmlspecialchars($err); ?>
            </div>
        <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="nav-grid">
      <a href="/community_discussions.php" class="card" style="text-decoration:none;">
          <div class="card-body">
              <div class="nav-card-icon" style="color: var(--accent);">💬</div>
              <h2 style="margin:0 0 8px; color:var(--text-main); font-size:22px;">Diskusijos</h2>
              <p style="margin:0; color:var(--text-muted); font-size:16px; line-height:1.5;">
                  Klausimai, patarimai ir bendruomenės pulsas. Prisijunk prie pokalbių, kurk naujas temas ir bendrauk.
              </p>
              <div style="margin-top:auto; padding-top:20px; color:var(--accent); font-weight:600; font-size:15px;">
                  Eiti į diskusijas →
              </div>
          </div>
      </a>
      
      <a href="/community_market.php" class="card" style="text-decoration:none;">
          <div class="card-body">
              <div class="nav-card-icon" style="color: #16a34a; background: #f0fdf4;">🛍️</div>
              <h2 style="margin:0 0 8px; color:var(--text-main); font-size:22px;">Bendruomenės turgus</h2>
              <p style="margin:0; color:var(--text-muted); font-size:16px; line-height:1.5;">
                  Pasiūlymai ir užklausos tarp narių. Rask, ko ieškai, arba parduok tai, kas nebereikalinga.
              </p>
              <div style="margin-top:auto; padding-top:20px; color:var(--accent); font-weight:600; font-size:15px;">
                  Peržiūrėti turgų →
              </div>
          </div>
      </a>
  </div>

  <?php if (!empty($marketItems)): ?>
  <div class="market-preview-section">
      <div class="market-preview-header">
          <h2>Naujausi skelbimai turgelyje</h2>
          <a href="/community_market.php">Visi skelbimai →</a>
      </div>
      <div class="market-preview-grid">
          <?php foreach ($marketItems as $item): 
              $listingType = $item['listing_type'] ?? 'sell';
              $itemUrl = '/community_listing.php?id=' . $item['id'];
          ?>
          <a href="<?php echo $itemUrl; ?>" class="preview-card">
              <div class="preview-image">
                  <?php if ($listingType === 'buy'): ?>
                      <div class="preview-badge buy">Ieško</div>
                  <?php else: ?>
                      <div class="preview-badge sell">Parduoda</div>
                  <?php endif; ?>

                  <?php if (!empty($item['image_url'])): ?>
                      <img src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                  <?php else: ?>
                      <span style="opacity:0.3;"><?php echo $listingType === 'buy' ? '🕵️' : '📷'; ?></span>
                  <?php endif; ?>
              </div>
              <div class="preview-body">
                  <h3 class="preview-title"><?php echo htmlspecialchars($item['title']); ?></h3>
                  <div class="preview-price <?php echo $listingType === 'buy' ? 'buy-price' : ''; ?>">
                      <?php 
                      if ($listingType === 'buy') {
                          echo ($item['price'] > 0) ? 'Biudžetas: ' . number_format($item['price'], 2) . ' €' : 'Sutartinė';
                      } else {
                          echo ($item['price'] > 0) ? number_format($item['price'], 2) . ' €' : 'Nemokamai / Sutartinė';
                      }
                      ?>
                  </div>
              </div>
          </a>
          <?php endforeach; ?>
      </div>
  </div>
  <?php endif; ?>

  <div class="info-grid">
      <div class="card">
          <div class="card-body">
              <h3 style="margin-top:0; font-size:18px;">✅ Ką gali?</h3>
              <ul class="rule-list">
                  <li>Kurti temas ir dalintis patarimais ar klausimais.</li>
                  <li>Prisijungti prie diskusijų, kurti naujas temas, dalyvauti kitų narių pokalbiuose.</li>
                  <li>Siūlyti ar ieškoti prekių Bendruomenės turguje.</li>
                  <li>Siųsti užklausas kitiems nariams.</li>
              </ul>
          </div>
      </div>
      
      <div class="card">
          <div class="card-body">
              <h3 style="margin-top:0; font-size:18px;">🚫 Ko negalima?</h3>
              <ul class="rule-list">
                  <li>Reklamuoti nesusijusių paslaugų.</li>
                  <li>Naudoti neapykantos kalbos ar įžeidinėti.</li>
                  <li>Apgaudinėti kitus narius.</li>
                  <li>Dalintis asmeniniais duomenimis be leidimo.</li>
              </ul>
          </div>
      </div>

      <div class="card" style="background: linear-gradient(135deg, #f8fafc, #fff);">
          <div class="card-body">
              <h3 style="margin-top:0; font-size:18px;">❓ Pagalba ir DUK</h3>
              <p style="margin:0 0 16px; font-size:14px; color:var(--text-muted); line-height:1.6;">
                  Nežinote nuo ko pradėti ar kaip veikia bendruomenės platforma? Užsukite į pagalbos centrą, kuriame atsakome į dažniausiai užduodamus klausimus.
              </p>
              <div style="margin-top:auto;">
                  <a href="/community_help.php" class="btn-outline" style="width:100%; text-align:center;">Skaityti daugiau</a>
              </div>
          </div>
      </div>
  </div>

  <div class="help-footer">
      Kilo klausimų dėl bendruomenės taisyklių? <a href="/contact.php">Susisiekite su mumis</a>
  </div>

</div>

<?php renderFooter($pdo); ?>
</body>
</html>