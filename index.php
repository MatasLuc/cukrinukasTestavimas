<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require_once __DIR__ . '/helpers.php'; // Būtina slugify funkcijai

$headerShadowIntensity = 70;
$GLOBALS['headerShadowIntensity'] = $headerShadowIntensity;

$pdo = getPdo();

// DB struktūros ir duomenų užtikrinimas
ensureUsersTable($pdo);
ensureNewsTable($pdo);
ensureCategoriesTable($pdo);
ensureProductsTable($pdo);
ensureOrdersTables($pdo);
ensureRecipesTable($pdo);
ensureAdminAccount($pdo);
ensureSiteContentTable($pdo);
ensureFooterLinks($pdo);
ensureSavedContentTables($pdo);
seedStoreExamples($pdo);
seedNewsExamples($pdo);
seedRecipeExamples($pdo);
tryAutoLogin($pdo);

$siteContent = getSiteContent($pdo);
$globalDiscount = getGlobalDiscount($pdo);
$categoryDiscounts = getCategoryDiscounts($pdo);

// Krepšelio logika
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

// Hero settings
$heroShadowIntensity = max(0, min(100, (int)($siteContent['hero_shadow_intensity'] ?? 70)));
$heroOverlayStrong = round(0.75 * ($heroShadowIntensity / 100), 3);
$heroOverlaySoft = round(0.17 * ($heroShadowIntensity / 100), 3);
$heroMedia = [
    'type' => $siteContent['hero_media_type'] ?? 'image',
    'color' => $siteContent['hero_media_color'] ?? '#2563eb',
    'src' => '',
    'poster' => $siteContent['hero_media_poster'] ?? '',
    'alt' => $siteContent['hero_media_alt'] ?? 'Cukrinukas fonas',
];

if ($heroMedia['type'] === 'video') {
    $heroMedia['src'] = $siteContent['hero_media_video'] ?? '';
} else {
    $heroMedia['src'] = $siteContent['hero_media_image'] ?? 'https://images.pexels.com/photos/6942003/pexels-photo-6942003.jpeg';
    $heroMedia['type'] = $heroMedia['type'] ?: 'image';
}

if ($heroMedia['type'] === 'video' && !$heroMedia['src']) $heroMedia['type'] = 'color';
if ($heroMedia['type'] === 'image' && !$heroMedia['src']) $heroMedia['src'] = 'https://images.pexels.com/photos/6942003/pexels-photo-6942003.jpeg';

// Content Text
$heroTitle = $siteContent['hero_title'] ?? 'Pagalba kasdienei diabeto priežiūrai';
$heroBody = $siteContent['hero_body'] ?? 'Gliukometrai, sensoriai, maži GI užkandžiai ir bendruomenės patarimai – viskas vienoje vietoje.';
$heroCtaLabel = $siteContent['hero_cta_label'] ?? 'Peržiūrėti pasiūlymus →';
$heroCtaUrl = $siteContent['hero_cta_url'] ?? '/products.php';

// Mock Data
$testimonials = [];
for ($i = 1; $i <= 3; $i++) {
    $testimonials[] = [
        'name' => $siteContent['testimonial_' . $i . '_name'] ?? '',
        'role' => $siteContent['testimonial_' . $i . '_role'] ?? '',
        'text' => $siteContent['testimonial_' . $i . '_text'] ?? '',
    ];
}

$promoCards = [];
$promoUrls = [1 => '/products.php', 2 => '/community_market.php', 3 => '/recipes.php'];

for ($i = 1; $i <= 3; $i++) {
    $promoCards[] = [
        'icon' => $siteContent['promo_' . $i . '_icon'] ?? ($i === 1 ? '🚀' : ($i === 2 ? '🛡️' : '💬')),
        'title' => $siteContent['promo_' . $i . '_title'] ?? '',
        'body' => $siteContent['promo_' . $i . '_body'] ?? '',
        'url' => $promoUrls[$i] ?? '#'
    ];
}

$storyband = [
    'title' => $siteContent['storyband_title'] ?? 'Kasdieniai sprendimai diabetui',
    'body' => $siteContent['storyband_body'] ?? 'Sudėjome priemones ir žinias, kurios palengvina cukrinio diabeto priežiūrą: nuo matavimų iki receptų ir užkandžių.',
    'cta_label' => $siteContent['storyband_cta_label'] ?? 'Rinktis rinkinį',
    'cta_url' => $siteContent['storyband_cta_url'] ?? '/products.php',
    'card_title' => $siteContent['storyband_card_title'] ?? '„Cukrinukas“ rinkiniai',
    'card_body' => $siteContent['storyband_card_body'] ?? 'Starteriai su gliukometrais, užkandžiais ir atsargomis 30 dienų.',
];

$storyRow = [
    'title' => $siteContent['storyrow_title'] ?? 'Stebėjimas, užkandžiai ir ramybė',
    'body' => $siteContent['storyrow_body'] ?? 'Greitai pasiekiami sensorių pleistrai, cukraus kiekį subalansuojantys batonėliai ir starterių rinkiniai.',
    'pills' => [
        $siteContent['storyrow_pill_1'] ?? 'Gliukozės matavimai',
        $siteContent['storyrow_pill_2'] ?? 'Subalansuotos užkandžių dėžutės',
        $siteContent['storyrow_pill_3'] ?? 'Kelionėms paruošti rinkiniai',
    ],
    'bubble_meta' => $siteContent['storyrow_bubble_meta'] ?? 'Rekomendacija',
    'bubble_title' => $siteContent['storyrow_bubble_title'] ?? '„Cukrinukas“ specialistai',
    'bubble_body' => $siteContent['storyrow_bubble_body'] ?? 'Suderiname atsargas pagal jūsų dienos režimą.',
    'floating_meta' => $siteContent['storyrow_floating_meta'] ?? 'Greitas pristatymas',
    'floating_title' => $siteContent['storyrow_floating_title'] ?? '1-2 d.d.',
    'floating_body' => $siteContent['storyrow_floating_body'] ?? 'Visoje Lietuvoje nuo 2.50 €',
];

$supportBand = [
    'title' => $siteContent['support_title'] ?? 'Pagalba jums ir šeimai',
    'body' => $siteContent['support_body'] ?? 'Nuo pirmo sensoriaus iki subalansuotos vakarienės – čia rasite trumpus gidus, vaizdo pamokas ir dietologės patarimus.',
    'chips' => [
        $siteContent['support_chip_1'] ?? 'Vaizdo gidai',
        $siteContent['support_chip_2'] ?? 'Dietologės Q&A',
        $siteContent['support_chip_3'] ?? 'Tėvų kampelis',
    ],
    'card_meta' => $siteContent['support_card_meta'] ?? 'Gyva konsultacija',
    'card_title' => $siteContent['support_card_title'] ?? '5 d. per savaitę',
    'card_body' => $siteContent['support_card_body'] ?? 'Trumpi pokalbiai su cukrinio diabeto slaugytoja per „Messenger“.',
    'card_cta_label' => $siteContent['support_card_cta_label'] ?? 'Rezervuoti laiką',
    'card_cta_url' => $siteContent['support_card_cta_url'] ?? '/contact.php',
];

// DB Data
$featuredNews = $pdo->query('SELECT id, title, image_url, body, summary, created_at FROM news WHERE is_featured = 1 ORDER BY created_at DESC LIMIT 4')->fetchAll();
$featuredIds = getFeaturedProductIds($pdo);
$featuredProducts = [];
if ($featuredIds) {
    $placeholders = implode(',', array_fill(0, count($featuredIds), '?'));
    $stmt = $pdo->prepare('SELECT p.*, c.name AS category_name,
        (SELECT path FROM product_images WHERE product_id = p.id AND is_primary = 1 ORDER BY id DESC LIMIT 1) AS primary_image
        FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id IN (' . $placeholders . ')');
    $stmt->execute($featuredIds);
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $row) { $map[$row['id']] = $row; }
    foreach ($featuredIds as $fid) { if (!empty($map[$fid])) { $featuredProducts[] = $map[$fid]; } }
}
$categories = $pdo->query('SELECT id, name, slug FROM categories ORDER BY name ASC')->fetchAll();
$freeShippingOffers = getFreeShippingProducts($pdo);

// Styles variables
$heroClass = $heroMedia['type'] === 'color' ? 'hero hero--color' : 'hero hero--media';
$heroSectionStyle = ($heroMedia['type'] === 'color'
    ? 'background:' . htmlspecialchars($heroMedia['color']) . ';'
    : 'background:#2563eb;') . ' --hero-overlay-strong:' . $heroOverlayStrong . '; --hero-overlay-soft:' . $heroOverlaySoft . ';';
$heroMediaStyle = 'background:' . htmlspecialchars($heroMedia['color']) . ';';
if ($heroMedia['type'] === 'image') {
    $heroMediaStyle = 'background-image:url(' . htmlspecialchars($heroMedia['src']) . '); background-size:cover; background-position:center;';
} elseif ($heroMedia['type'] === 'video') {
    $heroMediaStyle = 'background:#000;';
}

$faviconSvg = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Ctext x='50%25' y='50%25' dy='.35em' text-anchor='middle' font-family='Arial, sans-serif' font-weight='900' font-size='60' fill='black'%3EC%3C/text%3E%3C/svg%3E";
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cukrinukas.lt – diabeto priemonės ir naujienos</title>
  <?php echo headerStyles($headerShadowIntensity ?? null); ?>
  <meta name="description" content="Cukrinukas.lt rasite gliukometrus, sensoriai, juosteles, mažo GI užkandžius ir patarimus gyvenimui su diabetu.">
  <link rel="icon" type="image/svg+xml" href="<?php echo $faviconSvg; ?>">
  
  <style>
    :root {
      --bg: #f7f7fb;
      --card: #ffffff;
      --border: #e4e7ec;
      --text: #1f2937;
      --muted: #52606d;
      --accent: #2563eb;
      --accent-hover: #1d4ed8;
      --accent-light: #eff6ff;
      --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
      --shadow-md: 0 4px 12px rgba(0,0,0,0.05);
    }
    * { box-sizing: border-box; }
    body { margin:0; background: var(--bg); color: var(--text); font-family: 'Inter', system-ui, sans-serif; }
    a { text-decoration:none; color:inherit; transition: color .2s; }
    img { max-width:100%; display:block; }

    /* PAGRINDINIS KONTEINERIS VISIEMS */
    .page-shell { display:flex; flex-direction:column; align-items:center; width:100%; overflow-x:hidden; }
    .section-shell { 
        width: 100%; max-width: 1200px; margin: 0 auto 40px; padding: 0 20px; 
    }

    /* BENDRI ELEMENTAI */
    .btn { 
        display:inline-flex; align-items:center; justify-content:center; gap:8px;
        padding: 10px 20px; border-radius:12px; font-weight:600; font-size:15px;
        background:#0b0b0b; color:#fff; border:1px solid #0b0b0b; cursor:pointer;
        transition: all .2s;
    }
    .btn:hover { opacity:0.9; transform:translateY(-1px); }
    .btn.secondary { background:#fff; color:#0b0b0b; border-color:var(--border); }
    
    /* PILLS - PAKEISTAS DIZAINAS (Kaip action-btn) */
    .pill {
        display:inline-flex; align-items:center; padding:6px 14px; 
        border-radius:999px; font-size:13px; font-weight:600;
        background:#fff; 
        color:#1f2937; /* Tamsus tekstas */
        border:1px solid var(--border);
        transition: all .2s;
        text-decoration: none;
    }
    .pill:hover { 
        border-color:var(--accent); 
        color:var(--accent); 
        background:#f0f9ff; /* Šviesiai mėlynas */
    }

    .section-head { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:24px; flex-wrap: wrap; }
    .section-head h2 { margin:0; font-size:26px; color:#0f172a; letter-spacing:-0.01em; }

    /* HERO */
    .hero { width:100%; margin-bottom:48px; position:relative; background:var(--accent); color:#fff; overflow:hidden; }
    .hero::after { content:""; position:absolute; inset:0; background: radial-gradient(circle at 20% 20%, rgba(255,255,255,0.2), transparent 45%), linear-gradient(120deg, rgba(37,99,235,0.4), rgba(15,23,42,0.6)); z-index:1; }
    .hero-media { position:absolute; inset:0; z-index:0; }
    .media-embed { width:100%; height:100%; background:var(--accent); }
    .media-embed img { width:100%; height:100%; object-fit:cover; }
    
    .hero__content { position:relative; z-index:2; max-width:1200px; margin:0 auto; padding: 50px 20px 40px; display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:32px; align-items:center; }
    .hero__copy h1 { margin:0 0 12px; font-size:clamp(32px, 5vw, 42px); line-height:1.2; color:#fff; }
    .hero__copy p { margin:0 0 24px; font-size:18px; line-height:1.6; color:#e0f2fe; max-width:540px; }
    
    /* GLASS CARD FIX */
    .glass-card { background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.2); border-radius:16px; padding:20px; backdrop-filter:blur(12px); box-shadow:0 10px 30px rgba(0,0,0,0.1); color:#fff; }
    .glass-card h3 { margin:0 0 8px; font-size:18px; color:#fff; }
    .glass-card p { margin:0 0 12px; font-size:14px; color:#e0f2fe; line-height:1.5; }
    .glass-card a { font-weight:700; text-decoration:none; color:#fff; } 

    /* PROMO CARDS - NUORODOS */
    .promo-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap:20px; }
    a.promo-card { 
        text-decoration:none; color:inherit;
        background:#fff; border-radius:16px; padding:20px; 
        border:1px solid var(--border); box-shadow:var(--shadow-sm); 
        display:flex; gap:16px; align-items:flex-start; transition: transform .2s; 
    }
    a.promo-card:hover { transform:translateY(-2px); border-color:var(--accent); }
    .promo-icon { width:42px; height:42px; border-radius:10px; background:var(--accent-light); color:var(--accent); display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
    .promo-card h3 { margin:0 0 4px; font-size:16px; }
    .promo-card p { margin:0; color:var(--muted); font-size:14px; line-height:1.5; }

    /* STORYBAND - BALTAS FONAS */
    .storyband-box { 
        background: #fff;
        border:1px solid var(--border); border-radius:20px; padding:32px;
        display:grid; grid-template-columns: 1fr 300px; gap:40px; align-items:center;
    }
    /* METRICS PAKEISTAS Į MYGTUKĄ */
    .metrics { margin-top:20px; }
    .btn-recipes {
        display: inline-flex; align-items: center; justify-content: center; width: 100%;
        padding: 14px; background: #fff; border: 1px solid var(--border);
        border-radius: 12px; font-weight: 700; color: #1f2937;
        text-decoration: none; transition: all .2s;
        box-shadow: var(--shadow-sm);
    }
    .btn-recipes:hover {
        background: var(--accent-light); color: var(--accent); border-color: var(--accent);
    }
    
    .story-card-inner { background:#fff; border-radius:14px; padding:20px; box-shadow:var(--shadow-md); border:1px solid #e0e7ff; }

    /* STORE GRID - 3 EILĖJE */
    .store-grid { display:grid; grid-template-columns: repeat(3, 1fr); gap:20px; }
    .product-card { 
        background:#fff; border:1px solid var(--border); border-radius:16px; 
        overflow:hidden; display:flex; flex-direction:column; transition: all .2s; 
    }
    .product-card:hover { border-color:var(--accent); transform:translateY(-3px); box-shadow:var(--shadow-md); }
    .product-card img { width:100%; height:190px; object-fit:contain; padding:16px; background:#fff; }
    .product-card__body { padding:14px; display:flex; flex-direction:column; gap:6px; flex:1; border-top:1px solid #f9fafb; }
    .badge { font-size:11px; font-weight:700; color:var(--accent); text-transform:uppercase; letter-spacing:0.5px; }
    .product-card__title { margin:0; font-size:15px; line-height:1.4; font-weight:600; }
    .product-card__price-row { margin-top:auto; display:flex; align-items:center; justify-content:space-between; padding-top:8px; }
    .price { font-weight:700; color:#111827; font-size:17px; }
    .price-old { font-size:12px; text-decoration:line-through; color:#9ca3af; margin-right:4px; }
    
    .action-btn { width:34px; height:34px; border-radius:8px; display:flex; align-items:center; justify-content:center; border:1px solid var(--border); background:#fff; color:#374151; cursor:pointer; transition:all .2s; }
    .action-btn:hover { border-color:var(--accent); color:var(--accent); background:var(--accent-light); }

    /* FREE SHIPPING */
    .free-shipping-box { 
        background: linear-gradient(135deg, #eff6ff, #dbeafe);
        border:1px solid #bae6fd; border-radius:20px; padding:24px;
    }
    .fs-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid #e0f2fe; padding-bottom:16px; }
    /* Pakeista spalva į juodą */
    .fs-title { display:flex; align-items:center; gap:10px; font-size:18px; font-weight:700; color:#0f172a; }
    .fs-icon { font-size:24px; color: #0f172a; }
    .fs-subtitle { font-size:14px; color:#0c4a6e; }
    
    .fs-grid { display:grid; grid-template-columns: repeat(3, 1fr); gap:16px; }
    .fs-card { 
        background:#fff; border:1px solid #e0f2fe; border-radius:12px; padding:12px; 
        display:flex; align-items:center; gap:12px; transition: all .2s; 
        text-decoration: none; color: inherit;
    }
    .fs-card:hover { border-color:#7dd3fc; transform:translateX(2px); }
    .fs-card img { width:50px; height:50px; object-fit:contain; border-radius:6px; border:1px solid #f1f5f9; }
    .fs-card h4 { margin:0 0 2px; font-size:13px; font-weight:600; line-height:1.3; }
    .fs-price { font-size:14px; font-weight:700; color:#0284c7; }

    /* HIGHLIGHT & SUPPORT BAND (IDENTIŠKI) */
    .support-box, .highlight-box { 
        background:#fff; border:1px solid var(--border); border-radius:20px; padding:32px;
        display:grid; grid-template-columns: 1.2fr 1fr; gap:40px; box-shadow:var(--shadow-sm);
    }
    .support-content h2, .highlight-content h2 { margin:0 0 12px; font-size:24px; color:#0f172a; }
    .support-content p, .highlight-content p { color:#475467; line-height:1.6; margin-bottom:20px; }
    
    .support-card, .highlight-card { 
        background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px; padding:20px; 
    }
    
    .support-card .btn, .highlight-card .btn { 
        width:100%; 
        background: #fff; 
        color: #1f2937; 
        border: 1px solid var(--border);
        font-weight: 600;
        border-radius: 999px;
    }
    .support-card .btn:hover, .highlight-card .btn:hover { 
        border-color: var(--accent); color: var(--accent); background: #f0f9ff; 
    }
    
    .chips { display:flex; gap:8px; flex-wrap:wrap; }

    /* TESTIMONIALS */
    .testimonials-box { 
        background: linear-gradient(135deg, #f8fafc, #f1f5f9); 
        border:1px solid var(--border); border-radius:20px; padding:32px; 
    }
    .testimonial-grid { display:grid; grid-template-columns: 1fr 1fr 1fr; gap:24px; }
    .testimonial { background:#fff; border-radius:14px; padding:20px; border:1px solid #e2e8f0; box-shadow:var(--shadow-sm); height:100%; display:flex; flex-direction:column; }
    .t-name { font-weight:700; margin-bottom:2px; font-size:15px; }
    .t-role { font-size:12px; color:var(--muted); margin-bottom:10px; }
    .t-text { font-size:14px; line-height:1.6; color:#475467; flex:1; }

    /* NEWS GRID */
    .news-grid { display:grid; grid-template-columns: repeat(4, 1fr); gap:20px; }
    .news-card { background:#fff; border:1px solid var(--border); border-radius:16px; overflow:hidden; transition:transform .2s; }
    .news-card:hover { transform:translateY(-3px); border-color:var(--accent); }
    .news-card img { width:100%; height:160px; object-fit:cover; }
    .news-body { padding:16px; }
    .news-title { margin:0 0 6px; font-size:16px; font-weight:600; line-height:1.4; }
    .news-date { font-size:12px; color:var(--muted); margin-bottom:8px; display:block; }
    .news-excerpt { font-size:13px; color:#4b5563; line-height:1.5; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }

    /* MEDIA QUERIES */
    @media (max-width: 900px) {
        .storyband-box, .highlight-box, .support-box { grid-template-columns: 1fr; gap:24px; }
        .testimonial-grid, .news-grid, .store-grid { grid-template-columns: 1fr 1fr; }
        .fs-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 768px) {
        /* Mobile hiding rules */
        .glass-card.support-mini,
        .story-card-inner {
            display: none !important;
        }
        .highlight-section .pill,
        .support-band .pill {
            display: none !important;
        }
    }
    @media (max-width: 600px) {
        .testimonial-grid, .news-grid, .store-grid { grid-template-columns: 1fr; }
        .hero__content { padding: 40px 20px; }
        .promo-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'home'); ?>

  <main class="page-shell">
    
    <section class="<?php echo $heroClass; ?>" style="<?php echo $heroSectionStyle; ?>">
      <div class="hero-media" style="<?php echo $heroMediaStyle; ?>">
        <?php if ($heroMedia['type'] === 'video' || $heroMedia['type'] === 'image'): ?>
          <div class="media-embed">
            <?php if ($heroMedia['type'] === 'video'): ?>
              <video src="<?php echo htmlspecialchars($heroMedia['src']); ?>" poster="<?php echo htmlspecialchars($heroMedia['poster']); ?>" autoplay muted loop playsinline controls></video>
            <?php else: ?>
              <img src="<?php echo htmlspecialchars($heroMedia['src']); ?>" alt="<?php echo htmlspecialchars($heroMedia['alt']); ?>">
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="hero__content">
        <div class="hero__copy">
          <h1><?php echo htmlspecialchars($heroTitle); ?></h1>
          <p><?php echo htmlspecialchars($heroBody); ?></p>
          <div class="hero__actions">
            <a class="btn" style="background:#fff; color:#1d4ed8; border-color:#fff;" href="<?php echo htmlspecialchars($heroCtaUrl); ?>"><?php echo htmlspecialchars($heroCtaLabel); ?></a>
          </div>
        </div>
        <div class="glass-card support-mini">
            <h3 style="margin:0 0 8px; font-size:18px;"><?php echo htmlspecialchars($supportBand['card_title']); ?></h3>
            <p style="font-size:14px; margin-bottom:12px; line-height:1.5;"><?php echo htmlspecialchars($supportBand['card_body']); ?></p>
            <a href="<?php echo htmlspecialchars($supportBand['card_cta_url']); ?>"><?php echo htmlspecialchars($supportBand['card_cta_label']); ?> →</a>
        </div>
      </div>
    </section>

    <section class="section-shell promo-section">
      <div class="section-head">
        <h2>SVETAINĖS AKCENTAI</h2>
      </div>
      <div class="promo-grid">
        <?php foreach ($promoCards as $card): ?>
          <a href="<?php echo htmlspecialchars($card['url']); ?>" class="promo-card">
            <div class="promo-icon"><?php echo htmlspecialchars($card['icon']); ?></div>
            <div>
              <h3><?php echo htmlspecialchars($card['title']); ?></h3>
              <p><?php echo htmlspecialchars($card['body']); ?></p>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="section-shell storyband">
      <div class="section-head">
        <h2><?php echo htmlspecialchars($storyband['title']); ?></h2>
        <a class="pill" href="<?php echo htmlspecialchars($storyband['cta_url']); ?>"><?php echo htmlspecialchars($storyband['cta_label']); ?> →</a>
      </div>
      <div class="storyband-box">
        <div>
          <p style="margin:0 0 16px; color:#475467; line-height:1.7; font-size:16px;"><?php echo htmlspecialchars($storyband['body']); ?></p>
          <div class="metrics">
            <a href="/recipes.php" class="btn-recipes">
                Peržiūrėk visus mūsų receptus
            </a>
          </div>
        </div>
        <div class="story-card-inner">
          <h3 style="margin:0 0 8px; color:#0f172a; font-size:18px;"><?php echo htmlspecialchars($storyband['card_title']); ?></h3>
          <p style="margin:0; color:#4b5563; font-size:14px; line-height:1.5;"><?php echo htmlspecialchars($storyband['card_body']); ?></p>
        </div>
      </div>
    </section>

    <section class="section-shell store-section" id="parduotuve">
      <div class="section-head">
        <h2>REKOMENDUOJAMOS PREKĖS</h2>
        <a class="pill" href="/products.php">Visos prekės →</a>
      </div>

      <div class="store-grid">
        <?php foreach ($featuredProducts as $product): ?>
          <?php 
            $priceDisplay = buildPriceDisplay($product, $globalDiscount, $categoryDiscounts); 
            // SEO URL
            $productUrl = '/produktas/' . slugify($product['title']) . '-' . (int)$product['id'];
          ?>
          <article class="product-card">
            <div style="position:relative;">
                <?php if (!empty($product['ribbon_text'])): ?>
                    <div style="position:absolute; top:12px; left:12px; background:var(--accent); color:#fff; padding:4px 8px; border-radius:6px; font-size:11px; font-weight:700; z-index:2;"><?php echo htmlspecialchars($product['ribbon_text']); ?></div>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars($productUrl); ?>">
                  <img src="<?php echo htmlspecialchars($product['primary_image'] ?: $product['image_url']); ?>" alt="<?php echo htmlspecialchars($product['title']); ?>">
                </a>
            </div>
            
            <div class="product-card__body">
              <div class="badge"><?php echo htmlspecialchars($product['category_name'] ?? ''); ?></div>
              <h3 class="product-card__title"><a href="<?php echo htmlspecialchars($productUrl); ?>"><?php echo htmlspecialchars($product['title']); ?></a></h3>
              
              <div class="product-card__price-row">
                <div>
                  <?php if ($priceDisplay['has_discount']): ?>
                    <span class="price-old"><?php echo number_format($priceDisplay['original'], 2); ?> €</span>
                  <?php endif; ?>
                  <span class="price"><?php echo number_format($priceDisplay['current'], 2); ?> €</span>
                </div>
                
                <form method="post" style="display:flex; gap:6px;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                    <button class="action-btn" type="submit" name="quantity" value="1" title="Į krepšelį">
                       <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                    </button>
                    <button class="action-btn" name="action" value="wishlist" type="submit" title="Į norų sąrašą">♥</button>
                </form>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="section-shell highlight-section">
      <div class="highlight-box">
        <div class="highlight-content">
            <h2><?php echo htmlspecialchars($storyRow['title']); ?></h2>
            <p><?php echo htmlspecialchars($storyRow['body']); ?></p>
            <div class="chips">
              <?php foreach ($storyRow['pills'] as $pill): ?>
                <span class="pill"><?php echo htmlspecialchars($pill); ?></span>
              <?php endforeach; ?>
            </div>
        </div>
        <div class="highlight-card">
            <p class="muted" style="margin:0 0 4px; font-size:12px; font-weight:700; color:var(--accent); text-transform:uppercase;"><?php echo htmlspecialchars($storyRow['bubble_meta']); ?></p>
            <strong style="display:block; margin:0 0 10px; color:#0f172a; font-size:18px;"><?php echo htmlspecialchars($storyRow['bubble_title']); ?></strong>
            <p style="margin:0 0 16px; font-size:14px; color:#4b5563; line-height:1.5;"><?php echo htmlspecialchars($storyRow['bubble_body']); ?></p>
            <a class="btn" href="/products.php">Peržiūrėti prekes</a>
        </div>
      </div>
    </section>

    <?php if ($freeShippingOffers): ?>
      <section class="section-shell free-shipping">
        <div class="free-shipping-box">
            <div class="fs-header">
                <div>
                    <div class="fs-title"><span class="fs-icon">🚚</span> Nemokamas pristatymas</div>
                    <div class="fs-subtitle">Perkant bent vieną iš šių prekių, pristatymas visam krepšeliui – 0 €.</div>
                </div>
            </div>
            <div class="fs-grid">
              <?php foreach ($freeShippingOffers as $offer): ?>
                <?php 
                    $priceDisplay = buildPriceDisplay($offer, $globalDiscount, $categoryDiscounts); 
                    // SEO URL
                    $offerUrl = '/produktas/' . slugify($offer['title']) . '-' . (int)$offer['product_id'];
                ?>
                <a href="<?php echo htmlspecialchars($offerUrl); ?>" class="fs-card">
                    <img src="<?php echo htmlspecialchars($offer['primary_image'] ?: $offer['image_url']); ?>" alt="">
                    <div>
                        <h4><?php echo htmlspecialchars($offer['title']); ?></h4>
                        <div class="fs-price"><?php echo number_format($priceDisplay['current'], 2); ?> €</div>
                    </div>
                </a>
              <?php endforeach; ?>
            </div>
        </div>
      </section>
    <?php endif; ?>

    <section class="section-shell testimonials">
      <div class="section-head">
        <h2>ATSILIEPIMAI</h2>
      </div>
      <div class="testimonials-box">
        <div class="testimonial-grid">
            <?php foreach ($testimonials as $t): ?>
            <div class="testimonial">
                <div class="t-name"><?php echo htmlspecialchars($t['name']); ?></div>
                <div class="t-role"><?php echo htmlspecialchars($t['role']); ?></div>
                <div class="t-text">"<?php echo htmlspecialchars($t['text']); ?>"</div>
            </div>
            <?php endforeach; ?>
        </div>
      </div>
    </section>

    <section class="section-shell news-block" id="naujienos">
      <div class="section-head">
        <h2>NAUJIENOS</h2>
        <a class="pill" href="/news.php">Visos naujienos →</a>
      </div>
      <div class="news-grid">
        <?php foreach ($featuredNews as $news): ?>
          <?php
            // SEO URL Naujienoms
            $newsUrl = '/naujiena/' . slugify($news['title']) . '-' . (int)$news['id'];
          ?>
          <a href="<?php echo htmlspecialchars($newsUrl); ?>" class="news-card">
            <img src="<?php echo htmlspecialchars($news['image_url']); ?>" alt="<?php echo htmlspecialchars($news['title']); ?>">
            <div class="news-body">
              <span class="news-date"><?php echo date('Y-m-d', strtotime($news['created_at'])); ?></span>
              <h3 class="news-title"><?php echo htmlspecialchars($news['title']); ?></h3>
              <?php 
                $excerpt = trim($news['summary'] ?? '');
                if (!$excerpt) $excerpt = strip_tags($news['body']);
                if (mb_strlen($excerpt) > 100) $excerpt = mb_substr($excerpt, 0, 100) . '...';
              ?>
              <p class="news-excerpt"><?php echo htmlspecialchars($excerpt); ?></p>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="section-shell support-band">
      <div class="support-box">
        <div class="support-content">
            <h2><?php echo htmlspecialchars($supportBand['title']); ?></h2>
            <p><?php echo htmlspecialchars($supportBand['body']); ?></p>
            <div class="chips">
              <?php foreach ($supportBand['chips'] as $chip): ?>
                <span class="pill"><?php echo htmlspecialchars($chip); ?></span>
              <?php endforeach; ?>
            </div>
        </div>
        <div class="support-card">
            <strong style="display:block; margin:0 0 10px; color:#0f172a; font-size:18px;"><?php echo htmlspecialchars($supportBand['card_title']); ?></strong>
            <p style="margin:0 0 16px; font-size:14px; color:#4b5563; line-height:1.5;"><?php echo htmlspecialchars($supportBand['card_body']); ?></p>
            <a class="btn" href="<?php echo htmlspecialchars($supportBand['card_cta_url']); ?>"><?php echo htmlspecialchars($supportBand['card_cta_label']); ?></a>
        </div>
      </div>
    </section>

  </main>

  <?php renderFooter($pdo); ?>
</body>
</html>
