<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require_once __DIR__ . '/helpers.php'; // Būtina slugify funkcijai

$pdo = getPdo();
ensureNewsTable($pdo);
ensureSavedContentTables($pdo);
tryAutoLogin($pdo);

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM news WHERE id = ?');
$stmt->execute([$id]);
$news = $stmt->fetch();

if (!$news) {
    http_response_code(404);
    echo 'Naujiena nerasta';
    exit;
}

// Kategorijos
$catStmt = $pdo->prepare("
    SELECT c.name, c.id 
    FROM news_categories c 
    JOIN news_category_relations r ON r.category_id = c.id 
    WHERE r.news_id = ?
");
$catStmt->execute([$id]);
$categories = $catStmt->fetchAll();

$canViewFull = ($news['visibility'] ?? 'public') !== 'members' || !empty($_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    validateCsrfToken();
    if (empty($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
    saveItemForUser($pdo, (int)$_SESSION['user_id'], 'news', $id);
    header('Location: /saved.php');
    exit;
}

$authorName = !empty($news['author']) ? $news['author'] : 'Redakcijos naujiena';

$meta = [
    'title' => $news['title'] . ' | Naujienos',
    'description' => $news['summary'] ?: mb_substr(strip_tags($news['body']), 0, 160),
    'image' => 'https://cukrinukas.lt' . $news['image_url']
];

// SEO URL
$currentNewsUrl = 'https://cukrinukas.lt/naujiena/' . slugify($news['title']) . '-' . $id;
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php echo headerStyles(); ?>
  <style>
    :root { --color-bg: #f7f7fb; --color-primary: #0b0b0b; --pill:#f0f2ff; --border:#e4e6f0; }
    * { box-sizing: border-box; }
    a { color:inherit; text-decoration:none; }
    body { background: var(--color-bg); }
    .shell { max-width:1080px; margin:32px auto 64px; padding:0 20px; display:flex; flex-direction:column; gap:18px; }
    
    .hero { background:linear-gradient(135deg,#ffffff 0%,#eef0ff 100%); border:1px solid var(--border); border-radius:20px; box-shadow:0 16px 40px rgba(0,0,0,0.06); padding:22px; display:flex; flex-direction:column; gap:12px; }
    .crumb { display:flex; align-items:center; gap:10px; color:#6b6b7a; font-size:14px; }
    .meta { display:flex; align-items:center; gap:10px; color:#6b6b7a; font-size:14px; flex-wrap:wrap; }
    
    .badge { padding:6px 12px; border-radius:999px; background:var(--pill); border:1px solid var(--border); font-weight:600; font-size:13px; color:#2b2f4c; }
    .badge-cat { background:#f0f7ff; border-color:#dbeafe; color:#1e40af; text-decoration:none; transition:0.2s; }
    .badge-cat:hover { background:#dbeafe; }
    
    .heart-btn { width:44px; height:44px; border-radius:14px; border:1px solid var(--border); background:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:18px; cursor:pointer; box-shadow:0 10px 22px rgba(0,0,0,0.08); transition: transform .16s ease, border-color .18s ease; }
    .heart-btn:hover { border-color: rgba(124,58,237,0.55); transform: translateY(-2px); }
    
    .media { overflow:hidden; border-radius:18px; border:1px solid var(--border); background:#fff; box-shadow:0 16px 38px rgba(0,0,0,0.06); }
    .media img { width:100%; object-fit:cover; max-height:460px; display:block; }
    
    .content-card { background:#fff; border:1px solid var(--border); border-radius:18px; padding:22px; box-shadow:0 14px 30px rgba(0,0,0,0.06); line-height:1.7; color:#2b2f4c; }
    .content-card img { max-width:100%; height:auto; display:block; margin:12px auto; border-radius:14px; }
    
    .grid { display:grid; grid-template-columns: 1fr; gap:18px; }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'news', $meta); ?>
  
  <main class="shell">
    <section class="hero">
      <div class="crumb"><a href="/news.php">← Visos naujienos</a></div>
      
      <div style="display:flex; align-items:flex-start; gap:14px; justify-content:space-between;">
        <div style="display:flex; flex-direction:column; gap:8px; flex: 1; min-width: 0;">
          <h1 style="margin:0; font-size:30px; line-height:1.2; color:#0b0b0b; word-wrap: break-word;"><?php echo htmlspecialchars($news['title']); ?></h1>
          <div class="meta">
            <span class="badge">Publikuota <?php echo date('Y-m-d', strtotime($news['created_at'])); ?></span>
            <span class="badge" style="background:#e8fff5; border-color:#cfe8dc; color:#0d8a4d;"><?php echo htmlspecialchars($authorName); ?></span>
            <?php if ($categories): ?>
                <?php foreach ($categories as $cat): ?>
                    <a href="/news.php?cat=<?php echo $cat['id']; ?>" class="badge badge-cat"><?php echo htmlspecialchars($cat['name']); ?></a>
                <?php endforeach; ?>
            <?php else: ?>
                <span class="badge" style="background:#f3f4f6;">Bendra</span>
            <?php endif; ?>
          </div>
        </div>
        
        <div style="display:flex; gap:10px; align-items:center; flex-shrink: 0; margin-left: 10px;">
          <?php if (!empty($_SESSION['user_id'])): ?>
            <form method="post" style="margin:0;">
              <?php echo csrfField(); ?>
              <input type="hidden" name="action" value="save">
              <button class="heart-btn" type="submit" aria-label="Išsaugoti naujieną">♥</button>
            </form>
          <?php else: ?>
            <a class="heart-btn" href="/login.php" aria-label="Prisijunkite, kad išsaugotumėte">♥</a>
          <?php endif; ?>
        </div>
      </div>
      
      <?php if ($news['image_url']): ?>
        <div class="media"><img src="<?php echo htmlspecialchars($news['image_url']); ?>" alt="<?php echo htmlspecialchars($news['title']); ?>"></div>
      <?php endif; ?>

      <?php if (!empty($news['summary'])): ?>
        <p style="margin:0; font-size:18px; line-height:1.6; color:#4b5563;">
            <?php echo nl2br(htmlspecialchars($news['summary'])); ?>
        </p>
      <?php endif; ?>
    </section>

    <section class="grid">
      <article class="content-card">
        <?php if ($canViewFull): ?>
          <?php echo sanitizeHtml($news['body']); ?>
        <?php else: ?>
          <div style="text-align:center; padding: 40px 0;">
              <p style="font-size:1.1em; color:#4b5563; margin-bottom:20px;">Norėdami skaityti visą straipsnį, prašome prisijungti.</p>
              <a href="/login.php" style="display:inline-block; padding:10px 20px; background:#0b0b0b; color:#fff; border-radius:8px; text-decoration:none; font-weight:bold;">Prisijungti</a>
              <p style="margin-top:15px; font-size:0.9em; color:#6b6b7a;">
                  Neturite paskyros? <a href="/register.php" style="text-decoration:underline; color:#4338ca;">Registruokitės</a>
              </p>
          </div>
        <?php endif; ?>
      </article>
      </section>
  </main>

  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "NewsArticle",
    "headline": <?php echo json_encode($news['title']); ?>,
    "image": [<?php echo json_encode('https://cukrinukas.lt' . $news['image_url']); ?>],
    "datePublished": <?php echo json_encode(date('Y-m-d', strtotime($news['created_at']))); ?>,
    "author": [{
        "@type": "Person",
        "name": <?php echo json_encode($authorName); ?>
    }]
  }
  </script>

  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    "itemListElement": [{
      "@type": "ListItem",
      "position": 1,
      "name": "Naujienos",
      "item": "https://cukrinukas.lt/news.php"
    },{
      "@type": "ListItem",
      "position": 2,
      "name": <?php echo json_encode($news['title']); ?>,
      "item": <?php echo json_encode($currentNewsUrl); ?>
    }]
  }
  </script>

  <?php renderFooter($pdo); ?>
</body>
</html>
