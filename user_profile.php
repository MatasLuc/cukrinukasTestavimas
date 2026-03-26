<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

$pdo = getPdo();

$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$userId) {
    echo "<!doctype html><html lang='lt'><head><meta charset='utf-8'><title>Klaida</title></head><body style='background:#f7f7fb; font-family:sans-serif; text-align:center; padding:50px;'><h2>Nenurodytas vartotojo ID.</h2></body></html>";
    exit;
}

// Gauname profilio vartotojo duomenis
$stmt = $pdo->prepare("SELECT id, name, email, created_at, profile_photo FROM users WHERE id = ?");
$stmt->execute([$userId]);
$profileUser = $stmt->fetch();

if (!$profileUser) {
    echo "<!doctype html><html lang='lt'><head><meta charset='utf-8'><title>Klaida</title></head><body style='background:#f7f7fb; font-family:sans-serif; text-align:center; padding:50px;'><h2>Vartotojas nerastas.</h2></body></html>";
    exit;
}

$currentUserId = $_SESSION['user_id'] ?? null;
$error = '';
$success = '';

// Atsiliepimo formos apdorojimas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    if (!$currentUserId) {
        $error = "Prisijunkite, kad galėtumėte palikti atsiliepimą.";
    } elseif ($currentUserId === $profileUser['id']) {
        $error = "Negalite vertinti patys savęs.";
    } else {
        $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
        $reviewText = isset($_POST['review_text']) ? trim($_POST['review_text']) : '';

        if ($rating < 1 || $rating > 5) {
            $error = "Įvertinimas turi būti nuo 1 iki 5 žvaigždučių.";
        } elseif (mb_strlen($reviewText) < 5) {
            $error = "Atsiliepimas per trumpas. Parašykite bent kelis žodžius.";
        } else {
            // Patikriname, ar šis vartotojas jau yra palikęs atsiliepimą šiam nariui
            $checkStmt = $pdo->prepare("SELECT id FROM user_reviews WHERE reviewer_id = ? AND reviewee_id = ?");
            $checkStmt->execute([$currentUserId, $profileUser['id']]);
            $existingReview = $checkStmt->fetch();

            if ($existingReview) {
                // Atnaujiname esamą atsiliepimą
                $updateStmt = $pdo->prepare("UPDATE user_reviews SET rating = ?, review_text = ?, created_at = NOW() WHERE id = ?");
                $updateStmt->execute([$rating, $reviewText, $existingReview['id']]);
                $success = "Atsiliepimas sėkmingai atnaujintas!";
            } else {
                // Įkeliame naują atsiliepimą
                $insertStmt = $pdo->prepare("INSERT INTO user_reviews (reviewer_id, reviewee_id, rating, review_text) VALUES (?, ?, ?, ?)");
                $insertStmt->execute([$currentUserId, $profileUser['id'], $rating, $reviewText]);
                $success = "Atsiliepimas sėkmingai pridėtas!";
            }
        }
    }
}

// Ištraukiame esamą prisijungusio vartotojo atsiliepimą, kad galėtume jį atvaizduoti redagavimo formoje
$existingReviewForm = null;
if ($currentUserId && $currentUserId !== $profileUser['id']) {
    $checkFormStmt = $pdo->prepare("SELECT * FROM user_reviews WHERE reviewer_id = ? AND reviewee_id = ?");
    $checkFormStmt->execute([$currentUserId, $profileUser['id']]);
    $existingReviewForm = $checkFormStmt->fetch();
}

// Ištraukiame atsiliepimų statistiką (vidurkį ir kiekį)
$statsStmt = $pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(id) as total_reviews FROM user_reviews WHERE reviewee_id = ?");
$statsStmt->execute([$profileUser['id']]);
$stats = $statsStmt->fetch();
$avgRating = $stats['avg_rating'] ? round($stats['avg_rating'], 1) : 0;
$totalReviews = $stats['total_reviews'];

// Ištraukiame visus nario gautus atsiliepimus
$reviewsStmt = $pdo->prepare("
    SELECT r.*, u.name as reviewer_name, u.profile_photo as reviewer_photo 
    FROM user_reviews r 
    JOIN users u ON r.reviewer_id = u.id 
    WHERE r.reviewee_id = ? 
    ORDER BY r.created_at DESC
");
$reviewsStmt->execute([$profileUser['id']]);
$reviews = $reviewsStmt->fetchAll();

// Ištraukiame šio nario aktyvius turgelio skelbimus
$listings = [];
try {
    $listingsStmt = $pdo->prepare("SELECT id, title, price, image_url as image FROM community_listings WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 12");
    $listingsStmt->execute([$profileUser['id']]);
    $listings = $listingsStmt->fetchAll();
} catch (Exception $e) {
    // Ignoruojame, jei laukai nesutampa
}

// Ištraukiame šio nario forumo temas
$threads = [];
try {
    $threadsStmt = $pdo->prepare("SELECT t.id, t.title, t.created_at, c.name as category_name FROM community_threads t LEFT JOIN community_thread_categories c ON t.category_id = c.id WHERE t.user_id = ? ORDER BY t.created_at DESC LIMIT 15");
    $threadsStmt->execute([$profileUser['id']]);
    $threads = $threadsStmt->fetchAll();
} catch (Exception $e) {
    // Ignoruojame, jei lentelių nėra
}

// Ištraukiame šio nario forumo atsakymus iš community_comments lentelės
$replies = [];
try {
    $repliesStmt = $pdo->prepare("SELECT r.id, r.thread_id, r.body, r.created_at, t.title as thread_title FROM community_comments r JOIN community_threads t ON r.thread_id = t.id WHERE r.user_id = ? ORDER BY r.created_at DESC LIMIT 15");
    $repliesStmt->execute([$profileUser['id']]);
    $replies = $repliesStmt->fetchAll();
} catch (Exception $e) {
    // Ignoruojame, jei lentelės nėra
}
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($profileUser['name']) ?> profilis | Cukrinukas.lt</title>
  <?php if(function_exists('headerStyles')) echo headerStyles(); ?>
  
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
    * { box-sizing:border-box; }
    body { margin:0; background: var(--bg); color: var(--text-main); font-family:'Inter', sans-serif; }
    a { color:inherit; text-decoration:none; }
    
    /* Vieno stulpelio dizainas - siauresnis konteineris geresniam skaitomumui */
    .page { max-width: 900px; margin:0 auto; padding:32px 20px 72px; display:flex; flex-direction: column; gap:28px; }

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
    }
    .hero-info { display: flex; align-items: center; gap: 24px; }
    .hero-avatar {
        width: 90px; height: 90px; border-radius: 24px; background: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 32px; font-weight: 700; color: var(--accent);
        border: 2px solid #bfdbfe; overflow: hidden; flex-shrink: 0;
        box-shadow: 0 4px 10px rgba(37, 99, 235, 0.1);
    }
    .hero-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .hero h1 { margin:0 0 8px; font-size:28px; color:#1e3a8a; letter-spacing:-0.5px; }
    .hero p { margin:0; color:#1e40af; font-size:15px; display: flex; align-items: center; gap: 6px;}
    
    .stat-card { 
        background:#fff; border:1px solid rgba(255,255,255,0.6); 
        padding:16px 20px; border-radius:16px; 
        min-width:160px; text-align:right;
        box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.1);
    }
    .stat-card strong { display:block; font-size:20px; color:#1e3a8a; margin-bottom: 4px; }
    .stat-card span { color: #64748b; font-size:13px; font-weight: 500; }
    .stars { color: #f59e0b; letter-spacing: 1px; font-size: 16px; }

    /* Cards */
    .card { 
        background:var(--card); 
        border:1px solid var(--border); 
        border-radius:20px; 
        padding:32px; 
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        margin-bottom: 24px;
    }
    .card h2 { margin:0 0 8px; font-size:20px; color: var(--text-main); }
    .card-desc { margin:0 0 24px; color: var(--text-muted); font-size:14px; line-height: 1.5; }

    /* Tabs */
    .tabs { display: flex; gap: 8px; margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 16px; overflow-x: auto; }
    .tab-btn {
        background: #f1f5f9; border: none; padding: 10px 20px; border-radius: 999px;
        font-weight: 600; font-size: 14px; color: var(--text-muted); cursor: pointer;
        transition: all 0.2s; white-space: nowrap; display: flex; align-items: center; gap: 8px;
    }
    .tab-btn:hover { background: #e2e8f0; color: var(--text-main); }
    .tab-btn.active { background: var(--text-main); color: #fff; }
    .tab-badge { background: rgba(0,0,0,0.1); padding: 2px 8px; border-radius: 10px; font-size: 12px; }
    .tab-btn.active .tab-badge { background: rgba(255,255,255,0.2); }
    .tab-content { display: none; }
    .tab-content.active { display: block; animation: fadeIn 0.3s ease; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

    /* Review Item */
    .review-item {
        padding: 20px; border: 1px solid var(--border); border-radius: 16px; margin-bottom: 16px;
        background: #fff; transition: box-shadow 0.2s;
    }
    .review-item:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
    .review-header { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
    .review-avatar {
        width: 40px; height: 40px; border-radius: 50%; background: #e2e8f0;
        display: flex; align-items: center; justify-content: center; font-weight: 600; color: var(--accent);
        overflow: hidden; flex-shrink: 0;
    }
    .review-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .review-meta { flex: 1; }
    .review-meta strong { display: block; font-size: 15px; color: var(--text-main); margin-bottom: 2px; }
    .review-meta span { display: block; font-size: 13px; color: var(--text-muted); }
    .review-body { font-size: 14.5px; color: var(--text-muted); line-height: 1.6; }

    /* Listings Grid */
    .listings-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
    .listing-card { border: 1px solid var(--border); border-radius: 16px; overflow: hidden; background: #fff; display: flex; flex-direction: column; transition: box-shadow 0.2s;}
    .listing-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); border-color: var(--accent); }
    .listing-img { height: 180px; width: 100%; object-fit: cover; background: #f1f5f9; }
    .listing-info { padding: 16px; display: flex; flex-direction: column; flex: 1; }
    .listing-title { font-weight: 600; font-size: 15px; margin: 0 0 12px; color: var(--text-main); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .listing-price { font-weight: 700; font-size: 16px; color: var(--accent); margin-bottom: 16px; }
    .btn-outline { 
        display: block; text-align: center; padding: 10px; border-radius: 10px; 
        border: 1px solid var(--border); font-weight: 600; font-size: 14px; 
        transition: all 0.2s; margin-top: auto;
    }
    .btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; }

    /* Threads & Replies List */
    .item-row {
        display: block; padding: 16px 20px; border: 1px solid var(--border); border-radius: 16px;
        margin-bottom: 12px; background: #fff; transition: all 0.2s; text-decoration: none; color: inherit;
    }
    .item-row:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-color: var(--accent); transform: translateY(-1px); }
    .item-row-title { font-weight: 600; font-size: 16px; margin-bottom: 6px; color: var(--text-main); }
    .item-row-meta { font-size: 13px; color: var(--text-muted); display: flex; gap: 12px; align-items: center;}
    .badge-gray { background: #f1f5f9; color: #475569; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
    .item-row-body { font-size: 14px; color: var(--text-muted); margin-top: 8px; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

    /* Form Elements */
    label { display:block; margin:0 0 6px; font-weight:600; font-size:13px; color:#344054; text-transform: uppercase; letter-spacing: 0.5px;}
    .form-control { 
        width:100%; padding:12px 14px; 
        border-radius:10px; border:1px solid var(--border); 
        background:#f8fafc; font-family:inherit; font-size:15px; color: var(--text-main);
        transition: all .2s; margin-bottom: 16px;
    }
    .form-control:focus { outline:none; border-color:var(--accent); background: #fff; box-shadow: 0 0 0 4px var(--focus-ring); }
    
    button.btn-primary { 
        padding:12px 16px; border-radius:10px; border:none; 
        background: #0f172a; color:#fff; font-weight:600; font-size:15px;
        cursor:pointer; width:100%; 
        transition: all .2s;
        display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    button.btn-primary:hover { background: #1e293b; transform: translateY(-1px); }

    /* Messages */
    .notice { padding:14px 16px; border-radius:12px; margin-bottom:24px; font-size:14.5px; display:flex; gap:12px; align-items:center; line-height:1.4; font-weight: 500;}
    .error { background: #fef2f2; border: 1px solid #fee2e2; color: #991b1b; }
    .success { background: #ecfdf5; border: 1px solid #d1fae5; color: #065f46; }
    
    .empty-state { text-align: center; padding: 48px 20px; background: #fff; border: 1px dashed var(--border); border-radius: 16px; }
    .empty-state svg { color: #cbd5e1; margin-bottom: 16px; }
    .empty-state h3 { font-size: 16px; color: var(--text-muted); margin: 0; font-weight: 500; }
  </style>

  <script>
    function switchTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        document.querySelector('[data-target="' + tabId + '"]').classList.add('active');
    }
  </script>
</head>
<body>
  <?php if(function_exists('renderHeader')) renderHeader($pdo, 'Profilis'); ?>
  
  <div class="page">
    <?php if ($error): ?>
        <div class="notice error">
            <svg style="width:24px;height:24px;flex-shrink:0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="notice success">
            <svg style="width:24px;height:24px;flex-shrink:0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
            <span><?= htmlspecialchars($success) ?></span>
        </div>
    <?php endif; ?>

    <section class="hero">
      <div class="hero-info">
        <div class="hero-avatar">
            <?php if (!empty($profileUser['profile_photo'])): ?>
                <img src="<?= htmlspecialchars($profileUser['profile_photo']) ?>" alt="Avataras">
            <?php else: ?>
                <?= mb_strtoupper(mb_substr($profileUser['name'], 0, 1)) ?>
            <?php endif; ?>
        </div>
        <div>
            <div class="pill" style="margin-bottom: 8px;">👤 Nario profilis</div>
            <h1><?= htmlspecialchars($profileUser['name']) ?></h1>
            <p>
                <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M10.854 7.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 1 1 .708-.708L7.5 9.793l2.646-2.647a.5.5 0 0 1 .708 0z"/><path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4H1z"/></svg>
                Narys nuo <?= date('Y-m-d', strtotime($profileUser['created_at'])) ?>
            </p>
        </div>
      </div>
      <div class="stat-card">
        <strong class="stars">
            <?= str_repeat('★', round($avgRating)) ?><span style="color:#e2e8f0;"><?= str_repeat('★', 5 - round($avgRating)) ?></span>
        </strong>
        <span><?= $avgRating ?> / 5 (iš <?= $totalReviews ?> vertinimų)</span>
      </div>
    </section>

    <div class="tabs">
        <button class="tab-btn active" data-target="tab-reviews" onclick="switchTab('tab-reviews')">
            Atsiliepimai <span class="tab-badge"><?= count($reviews) ?></span>
        </button>
        <?php if (count($listings) > 0): ?>
        <button class="tab-btn" data-target="tab-listings" onclick="switchTab('tab-listings')">
            Skelbimai turgelyje <span class="tab-badge"><?= count($listings) ?></span>
        </button>
        <?php endif; ?>
        <?php if (count($threads) > 0): ?>
        <button class="tab-btn" data-target="tab-threads" onclick="switchTab('tab-threads')">
            Forumo temos <span class="tab-badge"><?= count($threads) ?></span>
        </button>
        <?php endif; ?>
        <?php if (count($replies) > 0): ?>
        <button class="tab-btn" data-target="tab-replies" onclick="switchTab('tab-replies')">
            Atsakymai <span class="tab-badge"><?= count($replies) ?></span>
        </button>
        <?php endif; ?>
    </div>

    <div id="tab-reviews" class="tab-content active">
        <?php if (count($reviews) > 0): ?>
            <?php foreach ($reviews as $review): ?>
                <div class="review-item">
                    <div class="review-header">
                        <div class="review-avatar">
                            <?php if (!empty($review['reviewer_photo'])): ?>
                                <img src="<?= htmlspecialchars($review['reviewer_photo']) ?>" alt="Avatar">
                            <?php else: ?>
                                <?= mb_strtoupper(mb_substr($review['reviewer_name'], 0, 1)) ?>
                            <?php endif; ?>
                        </div>
                        <div class="review-meta">
                            <strong>
                                <a href="user_profile.php?id=<?= $review['reviewer_id'] ?>">
                                    <?= htmlspecialchars($review['reviewer_name']) ?>
                                </a>
                            </strong>
                            <span><?= date('Y-m-d H:i', strtotime($review['created_at'])) ?></span>
                        </div>
                        <div class="stars">
                            <?= str_repeat('★', $review['rating']) ?><span style="color:#e2e8f0;"><?= str_repeat('★', 5 - $review['rating']) ?></span>
                        </div>
                    </div>
                    <div class="review-body">
                        <?= nl2br(htmlspecialchars($review['review_text'])) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <svg width="48" height="48" fill="currentColor" viewBox="0 0 16 16"><path d="M14 1a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1h-2.5a2 2 0 0 0-1.6.8L8 14.333 6.1 11.8a2 2 0 0 0-1.6-.8H2a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h12zM2 0a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2.5a1 1 0 0 1 .8.4l1.9 2.533a1 1 0 0 0 1.6 0l1.9-2.533a1 1 0 0 1 .8-.4H14a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H2z"/><path d="M3 3.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5zM3 6a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9A.5.5 0 0 1 3 6zm0 2.5a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5z"/></svg>
                <h3>Šis narys dar neturi atsiliepimų.</h3>
            </div>
        <?php endif; ?>

        <div style="margin-top: 32px;">
            <?php if ($currentUserId && $currentUserId !== $profileUser['id']): ?>
                <div class="card">
                    <h2 style="font-size: 18px; display: flex; align-items: center; gap: 8px;">
                        <svg width="20" height="20" fill="#f59e0b" viewBox="0 0 16 16"><path d="M3.612 15.443c-.386.198-.824-.149-.746-.592l.83-4.73L.173 6.765c-.329-.314-.158-.888.283-.95l4.898-.696L7.538.792c.197-.39.73-.39.927 0l2.184 4.327 4.898.696c.441.062.612.636.282.95l-3.522 3.356.83 4.73c.078.443-.36.79-.746.592L8 13.187l-4.389 2.256z"/></svg>
                        <?= $existingReviewForm ? 'Redaguoti atsiliepimą' : 'Palikti atsiliepimą' ?>
                    </h2>
                    
                    <form method="post" action="">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        
                        <label>Įvertinimas</label>
                        <select name="rating" class="form-control" required style="max-width: 300px;">
                            <option value="" disabled <?= !$existingReviewForm ? 'selected' : '' ?>>Pasirinkite...</option>
                            <option value="5" <?= ($existingReviewForm && $existingReviewForm['rating'] == 5) ? 'selected' : '' ?>>5 ★ - Puikiai</option>
                            <option value="4" <?= ($existingReviewForm && $existingReviewForm['rating'] == 4) ? 'selected' : '' ?>>4 ★ - Gerai</option>
                            <option value="3" <?= ($existingReviewForm && $existingReviewForm['rating'] == 3) ? 'selected' : '' ?>>3 ★ - Vidutiniškai</option>
                            <option value="2" <?= ($existingReviewForm && $existingReviewForm['rating'] == 2) ? 'selected' : '' ?>>2 ★ - Prastai</option>
                            <option value="1" <?= ($existingReviewForm && $existingReviewForm['rating'] == 1) ? 'selected' : '' ?>>1 ★ - Labai prastai</option>
                        </select>

                        <label>Komentaras</label>
                        <textarea name="review_text" class="form-control" rows="4" required placeholder="Aprašykite savo patirtį..."><?= $existingReviewForm ? htmlspecialchars($existingReviewForm['review_text']) : '' ?></textarea>
                        
                        <button type="submit" name="submit_review" class="btn-primary" style="max-width: 200px;">
                            <?= $existingReviewForm ? 'Atnaujinti' : 'Pateikti' ?>
                        </button>
                    </form>
                </div>
            <?php elseif (!$currentUserId): ?>
                <div class="card" style="text-align: center; border: 1px dashed var(--border); box-shadow: none;">
                    <svg width="48" height="48" fill="#2563eb" style="opacity: 0.5; margin-bottom: 16px;" viewBox="0 0 16 16"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/><path d="m8.93 6.588-2.29.287-.082.38.45.083c.294.07.352.176.288.469l-.738 3.468c-.194.897.105 1.319.808 1.319.545 0 1.178-.252 1.465-.598l.088-.416c-.2.176-.492.246-.686.246-.275 0-.375-.193-.304-.533L8.93 6.588zM9 4.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/></svg>
                    <h2 style="font-size: 18px; margin-bottom: 16px;">Norite palikti atsiliepimą?</h2>
                    <a href="login.php" class="btn-primary" style="display: inline-flex; max-width: 200px; margin: 0 auto; text-decoration: none;">Prisijunkite</a>
                </div>
            <?php else: ?>
                <div class="card" style="text-align: center; background: #f8fafc; border: 1px dashed var(--border); box-shadow: none;">
                    <svg width="48" height="48" fill="#10b981" style="opacity: 0.5; margin-bottom: 12px;" viewBox="0 0 16 16"><path d="M12.5 16a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm1.679-4.493-1.335 2.226a.75.75 0 0 1-1.174.144l-.774-.773a.5.5 0 0 1 .708-.708l.547.548 1.17-1.951a.5.5 0 1 1 .858.514ZM11 5a3 3 0 1 1-6 0 3 3 0 0 1 6 0ZM8 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/><path d="M8.256 14a4.474 4.474 0 0 1-.229-1.004H3c.001-.246.154-.986.832-1.664C4.484 10.68 5.711 10 8 10c.26 0 .507.009.74.025.226-.341.496-.65.804-.918C9.077 9.038 8.564 9 8 9c-5 0-6 3-6 4s1 1 1 1h5.256Z"/></svg>
                    <h2 style="font-size: 18px; margin: 0;">Tai jūsų profilis</h2>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (count($listings) > 0): ?>
    <div id="tab-listings" class="tab-content">
        <div class="listings-grid">
            <?php foreach ($listings as $listing): ?>
                <div class="listing-card">
                    <?php if (!empty($listing['image'])): ?>
                        <img src="<?= htmlspecialchars($listing['image']) ?>" class="listing-img" alt="Skelbimas">
                    <?php else: ?>
                        <div class="listing-img" style="display: flex; align-items: center; justify-content: center; color: #cbd5e1;">
                            <svg width="40" height="40" fill="currentColor" viewBox="0 0 16 16"><path d="M10.5 8.5a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0z"/><path d="M14 14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H2a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12zM2 3h12a1 1 0 0 1 1 1v8l-2.5-2.5a1.5 1.5 0 0 0-2.122 0l-1.038 1.038-3.04-3.04a1.5 1.5 0 0 0-2.122 0L1 11V4a1 1 0 0 1 1-1z"/></svg>
                        </div>
                    <?php endif; ?>
                    <div class="listing-info">
                        <div class="listing-title" title="<?= htmlspecialchars($listing['title']) ?>">
                            <?= htmlspecialchars($listing['title']) ?>
                        </div>
                        <div class="listing-price"><?= number_format($listing['price'], 2) ?> &euro;</div>
                        <a href="community_listing.php?id=<?= $listing['id'] ?>" class="btn-outline">Peržiūrėti</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (count($threads) > 0): ?>
    <div id="tab-threads" class="tab-content">
        <?php foreach ($threads as $thread): ?>
            <a href="community_thread.php?id=<?= $thread['id'] ?>" class="item-row">
                <div class="item-row-title"><?= htmlspecialchars($thread['title']) ?></div>
                <div class="item-row-meta">
                    <?php if (!empty($thread['category_name'])): ?>
                        <span class="badge-gray"><?= htmlspecialchars($thread['category_name']) ?></span>
                    <?php endif; ?>
                    <span>Pradėta: <?= date('Y-m-d H:i', strtotime($thread['created_at'])) ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (count($replies) > 0): ?>
    <div id="tab-replies" class="tab-content">
        <?php foreach ($replies as $reply): ?>
            <a href="community_thread.php?id=<?= $reply['thread_id'] ?>" class="item-row">
                <div class="item-row-meta" style="margin-bottom: 6px;">
                    Atsakyta temoje: <strong style="color: var(--text-main);"><?= htmlspecialchars($reply['thread_title']) ?></strong>
                    &bull; <?= date('Y-m-d H:i', strtotime($reply['created_at'])) ?>
                </div>
                <div class="item-row-body">
                    <?= strip_tags(htmlspecialchars($reply['body'])) ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>

  <?php if(function_exists('renderFooter')) renderFooter($pdo); ?>
</body>
</html>