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
      --text: #1f2937;
      --text-dark: #0f172a;
      --muted: #64748b;
      --accent: #2563eb;
      --accent-hover: #1d4ed8;
      --accent-light: rgba(37, 99, 235, 0.1);
      /* Naujos modernios proporcijos erdvėms */
      --gap-lg: 40px;
      --gap-md: 24px;
      --gap-sm: 16px;
    }
    * { box-sizing: border-box; }
    
    body { 
        margin: 0; 
        color: var(--text); 
        font-family: 'Inter', system-ui, sans-serif; 
        /* Premium Mesh Gradient Fonas */
        background-color: #f7f9fc;
        background-image: 
            radial-gradient(at 0% 0%, #e0f2fe 0px, transparent 50%),
            radial-gradient(at 100% 0%, #f3e8ff 0px, transparent 50%),
            radial-gradient(at 100% 100%, #e0f2fe 0px, transparent 50%),
            radial-gradient(at 0% 100%, #f3e8ff 0px, transparent 50%);
        background-attachment: fixed;
    }
    
    a { text-decoration: none; color: inherit; transition: color .3s ease; }
    img { max-width: 100%; display: block; }

    /* PAGRINDINIS KONTEINERIS VISIEMS */
    .page-shell { 
        display: flex; flex-direction: column; align-items: center; 
        width: 100%; overflow-x: hidden; padding-top: 40px;
    }
    
    /* Padidinta erdvė tarp sekcijų */
    .section-shell { 
        width: 100%; max-width: 1240px; margin: 0 auto 80px; padding: 0 24px; 
    }

    /* BENDRI ELEMENTAI & GLASSMORPHISM KLASĖS */
    .glass-panel {
        background: rgba(255, 255, 255, 0.45);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border: 1px solid rgba(255, 255, 255, 0.6);
        box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.05);
        border-radius: 20px;
    }
    
    /* Vidinės, šiek tiek baltesnės stiklo kortelės akcentams */
    .glass-panel-inner {
        background: rgba(255, 255, 255, 0.65);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        border: 1px solid rgba(255, 255, 255, 0.8);
        border-radius: 16px;
    }

    .btn, .glass-btn { 
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        padding: 14px 28px; border-radius: 16px; font-weight: 700; font-size: 16px;
        transition: all .3s ease; cursor: pointer; text-align: center;
    }
    
    .glass-btn {
        background: rgba(255, 255, 255, 0.7);
        backdrop-filter: blur(10px);
        color: var(--accent);
        border: 1px solid rgba(255, 255, 255, 0.9);
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    }
    .glass-btn:hover {
        background: #fff;
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(37, 99, 235, 0.15);
    }
    
    .btn-dark {
        background: var(--text-dark); color: #fff; border: 1px solid var(--text-dark);
    }
    .btn-dark:hover {
        background: #000; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.1);
    }

    .pill {
        display: inline-flex; align-items: center; padding: 8px 18px; 
        border-radius: 999px; font-size: 14px; font-weight: 600;
        background: rgba(255, 255, 255, 0.6); 
        color: var(--text-dark);
        border: 1px solid rgba(255, 255, 255, 0.8);
        transition: all .3s ease;
        backdrop-filter: blur(8px);
    }
    .pill:hover { 
        background: #fff; color: var(--accent); 
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.1);
    }

    .section-head { display: flex; align-items: center; justify-content: space-between; gap: var(--gap-sm); margin-bottom: 32px; flex-wrap: wrap; }
    .section-head h2 { margin: 0; font-size: 32px; font-weight: 800; color: var(--text-dark); letter-spacing: -0.02em; }

    /* MODERNUS SPLIT-SCREEN HERO */
    .hero-split {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 60px;
        align-items: center;
        margin-bottom: 100px;
    }
    .hero-left {
        display: flex; flex-direction: column; gap: 24px;
        position: relative; z-index: 2;
    }
    .hero-left h1 {
        margin: 0; font-size: clamp(40px, 5vw, 64px); 
        font-weight: 800; line-height: 1.1; 
        color: var(--text-dark); letter-spacing: -0.03em;
    }
    .hero-left p {
        margin: 0; font-size: 18px; line-height: 1.6; color: var(--muted); max-width: 500px;
    }
    .hero-actions { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; margin-top: 10px; }
    
    .hero-right {
        position: relative;
        border-radius: 30px;
        overflow: hidden;
        box-shadow: 0 24px 48px rgba(37, 99, 235, 0.12);
        aspect-ratio: 4/5; /* Graži portretinė proporcija */
    }
    .hero-right img, .hero-right video {
        width: 100%; height: 100%; object-fit: cover;
    }

    /* PROMO CARDS */
    .promo-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: var(--gap-md); }
    a.promo-card { 
        padding: 24px; display: flex; gap: 20px; align-items: flex-start; 
        transition: transform .3s ease, box-shadow .3s ease; 
    }
    a.promo-card:hover { 
        transform: translateY(-5px); 
        box-shadow: 0 15px 35px rgba(31, 38, 135, 0.1); 
    }
    .promo-icon { 
        width: 54px; height: 54px; border-radius: 16px; 
        background: rgba(255,255,255,0.8); color: var(--accent); 
        display: flex; align-items: center; justify-content: center; 
        font-size: 24px; flex-shrink: 0;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }
    .promo-card h3 { margin: 0 0 6px; font-size: 18px; font-weight: 700; color: var(--text-dark); }
    .promo-card p { margin: 0; color: var(--muted); font-size: 15px; line-height: 1.5; }

    /* STORYBAND */
    .storyband-box { 
        padding: 40px; display: grid; grid-template-columns: 1fr 340px; gap: 60px; align-items: center;
    }
    .storyband-text { color: var(--muted); line-height: 1.7; font-size: 18px; margin: 0 0 24px; }
    .story-card-inner { padding: 32px; display: flex; flex-direction: column; justify-content: center; }
    .story-card-inner h3 { margin: 0 0 12px; color: var(--text-dark); font-size: 22px; font-weight: 800; }
    .story-card-inner p { margin: 0; color: var(--muted); font-size: 15px; line-height: 1.6; }

    /* STORE GRID & PRODUCTS */
    .store-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--gap-lg); }
    .product-card { 
        display: flex; flex-direction: column; overflow: hidden; 
        transition: transform .3s ease, box-shadow .3s ease; 
    }
    .product-card:hover { 
        transform: translateY(-5px); 
        box-shadow: 0 15px 40px rgba(31, 38, 135, 0.1); 
    }
    .product-image-wrap { position: relative; padding: 24px; }
    .product-card img { width: 100%; height: 220px; object-fit: contain; mix-blend-mode: multiply; }
    
    .product-card__body { padding: 24px; display: flex; flex-direction: column; gap: 8px; flex: 1; }
    .badge { font-size: 12px; font-weight: 800; color: var(--accent); text-transform: uppercase; letter-spacing: 0.5px; }
    .product-card__title { margin: 0; font-size: 17px; line-height: 1.4; font-weight: 700; color: var(--text-dark); }
    
    .product-card__price-row { margin-top: auto; display: flex; align-items: center; justify-content: space-between; padding-top: 16px; }
    .price { font-weight: 800; color: var(--text-dark); font-size: 20px; }
    .price-old { font-size: 14px; text-decoration: line-through; color: #9ca3af; margin-right: 6px; }
    
    /* GLASS BUBBLES - Visiškai apvalūs mygtukai */
    .glass-bubble { 
        width: 44px; height: 44px; border-radius: 50%; 
        background: rgba(255, 255, 255, 0.5); backdrop-filter: blur(8px);
        border: 1px solid rgba(255, 255, 255, 0.9);
        display: flex; align-items: center; justify-content: center; 
        color: var(--text-dark); cursor: pointer; transition: all .3s ease;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }
    .glass-bubble:hover { 
        background: rgba(255, 255, 255, 1); color: var(--accent);
        transform: translateY(-3px) scale(1.05);
        box-shadow: 0 8px 16px rgba(37, 99, 235, 0.15); 
    }

    /* HIGHLIGHT & SUPPORT BAND */
    .highlight-box, .support-box { 
        padding: 48px; display: grid; grid-template-columns: 1.2fr 1fr; gap: 60px; align-items: center;
    }
    .highlight-content h2, .support-content h2 { margin: 0 0 16px; font-size: 32px; font-weight: 800; color: var(--text-dark); }
    .highlight-content p, .support-content p { color: var(--muted); line-height: 1.7; font-size: 18px; margin-bottom: 24px; }
    
    .highlight-card, .support-card { padding: 32px; display: flex; flex-direction: column; gap: 16px; }
    .highlight-card .btn, .support-card .btn { width: 100%; }
    .chips { display: flex; gap: 12px; flex-wrap: wrap; }

    /* FREE SHIPPING */
    .fs-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 1px solid rgba(255,255,255,0.5); padding-bottom: 20px; }
    .fs-title { display: flex; align-items: center; gap: 12px; font-size: 22px; font-weight: 800; color: var(--text-dark); }
    .fs-subtitle { font-size: 15px; color: var(--muted); margin-top: 4px; }
    
    .fs-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--gap-md); }
    .fs-card { 
        padding: 16px; display: flex; align-items: center; gap: 16px; 
        transition: transform .3s ease; text-decoration: none; color: inherit;
    }
    .fs-card:hover { transform: translateX(5px); }
    .fs-card img { width: 64px; height: 64px; object-fit: contain; mix-blend-mode: multiply; }
    .fs-card h4 { margin: 0 0 4px; font-size: 15px; font-weight: 700; line-height: 1.3; color: var(--text-dark); }
    .fs-price { font-size: 16px; font-weight: 800; color: var(--accent); }

    /* TESTIMONIALS */
    .testimonial-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--gap-md); }
    .testimonial { padding: 32px; display: flex; flex-direction: column; height: 100%; transition: transform .3s ease; }
    .testimonial:hover { transform: translateY(-3px); }
    .t-name { font-weight: 800; font-size: 17px; color: var(--text-dark); margin-bottom: 4px; }
    .t-role { font-size: 13px; color: var(--accent); margin-bottom: 16px; font-weight: 600; text-transform: uppercase; }
    .t-text { font-size: 15px; line-height: 1.7; color: var(--muted); flex: 1; font-style: italic; }

    /* NEWS GRID */
    .news-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--gap-md); }
    .news-card { overflow: hidden; transition: transform .3s ease, box-shadow .3s ease; display: flex; flex-direction: column; }
    .news-card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(31, 38, 135, 0.1); }
    .news-card img { width: 100%; height: 180px; object-fit: cover; }
    .news-body { padding: 24px; display: flex; flex-direction: column; flex: 1; }
    .news-date { font-size: 13px; font-weight: 600; color: var(--accent); margin-bottom: 8px; text-transform: uppercase; }
    .news-title { margin: 0 0 10px; font-size: 18px; font-weight: 800; line-height: 1.4; color: var(--text-dark); }
    .news-excerpt { font-size: 15px; color: var(--muted); line-height: 1.6; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; margin: 0; }

    /* MEDIA QUERIES */
    @media (max-width: 1024px) {
        .hero-split { gap: 40px; }
        .store-grid { grid-template-columns: repeat(2, 1fr); }
        .news-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 900px) {
        .hero-split { grid-template-columns: 1fr; }
        .hero-right { order: -1; max-height: 400px; aspect-ratio: auto; }
        .storyband-box, .highlight-box, .support-box { grid-template-columns: 1fr; gap: 40px; padding: 32px; }
        .testimonial-grid { grid-template-columns: 1fr 1fr; }
        .fs-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 600px) {
        .store-grid, .testimonial-grid, .news-grid { grid-template-columns: 1fr; }
        .hero-left h1 { font-size: 36px; }
        .section-shell { padding: 0 16px; margin-bottom: 50px; }
    }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'home'); ?>

  <main class="page-shell">
    
    <section class="section-shell hero-split <?php echo $heroClass; ?>">
      <div class="hero-left">
        <h1><?php echo htmlspecialchars($heroTitle); ?></h1>
        <p><?php echo htmlspecialchars($heroBody); ?></p>
        <div class="hero-actions">
          <a class="glass-btn" href="<?php echo htmlspecialchars($heroCtaUrl); ?>">
            <?php echo htmlspecialchars($heroCtaLabel); ?>
          </a>
        </div>
        
        <div class="glass-panel-inner" style="padding: 20px; margin-top: 20px; max-width: 400px;">
            <h3 style="margin:0 0 8px; font-size:16px; font-weight:800; color:var(--text-dark);"><?php echo htmlspecialchars($supportBand['card_title']); ?></h3>
            <p style="font-size:14px; margin:0 0 12px; line-height:1.5; color:var(--muted);"><?php echo htmlspecialchars($supportBand['card_body']); ?></p>
            <a style="font-weight:700; color:var(--accent); text-decoration:none;" href="<?php echo htmlspecialchars($supportBand['card_cta_url']); ?>"><?php echo htmlspecialchars($supportBand['card_cta_label']); ?> →</a>
        </div>
      </div>
      
      <div class="hero-right">
        <?php if ($heroMedia['type'] === 'video'): ?>
          <video src="<?php echo htmlspecialchars($heroMedia['src']); ?>" poster="<?php echo htmlspecialchars($heroMedia['poster']); ?>" autoplay muted loop playsinline controls></video>
        <?php else: ?>
          <img src="<?php echo htmlspecialchars($heroMedia['src']); ?>" alt="<?php echo htmlspecialchars($heroMedia['alt']); ?>">
        <?php endif; ?>
      </div>
    </section>

    <section class="section-shell promo-section">
      <div class="section-head">
        <h2>SVETAINĖS AKCENTAI</h2>
      </div>
      <div class="promo-grid">
        <?php foreach ($promoCards as $card): ?>
          <a href="<?php echo htmlspecialchars($card['url']); ?>" class="promo-card glass-panel">
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
      <div class="storyband-box glass-panel">
        <div>
          <p class="storyband-text"><?php echo htmlspecialchars($storyband['body']); ?></p>
          <div class="metrics">
            <a href="/recipes.php" class="glass-btn">
                Peržiūrėk visus mūsų receptus
            </a>
          </div>
        </div>
        <div class="story-card-inner glass-panel-inner">
          <h3><?php echo htmlspecialchars($storyband['card_title']); ?></h3>
          <p><?php echo htmlspecialchars($storyband['card_body']); ?></p>
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
            $productUrl = '/produktas/' . slugify($product['title']) . '-' . (int)$product['id'];
          ?>
          <article class="product-card glass-panel">
            <div class="product-image-wrap">
                <?php if (!empty($product['ribbon_text'])): ?>
                    <div style="position:absolute; top:16px; left:16px; background:var(--accent); color:#fff; padding:6px 12px; border-radius:8px; font-size:12px; font-weight:800; z-index:2; box-shadow:0 4px 12px rgba(37,99,235,0.3);"><?php echo htmlspecialchars($product['ribbon_text']); ?></div>
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
                
                <form method="post" style="display:flex; gap:10px;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                    <button class="glass-bubble" type="submit" name="quantity" value="1" title="Į krepšelį">
                       <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                    </button>
                    <button class="glass-bubble" name="action" value="wishlist" type="submit" title="Į norų sąrašą">
                       <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
                    </button>
                </form>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="section-shell highlight-section">
      <div class="highlight-box glass-panel">
        <div class="highlight-content">
            <h2><?php echo htmlspecialchars($storyRow['title']); ?></h2>
            <p><?php echo htmlspecialchars($storyRow['body']); ?></p>
            <div class="chips">
              <?php foreach ($storyRow['pills'] as $pill): ?>
                <span class="pill"><?php echo htmlspecialchars($pill); ?></span>
              <?php endforeach; ?>
            </div>
        </div>
        <div class="highlight-card glass-panel-inner">
            <p style="margin:0; font-size:12px; font-weight:800; color:var(--accent); text-transform:uppercase; letter-spacing:0.5px;"><?php echo htmlspecialchars($storyRow['bubble_meta']); ?></p>
            <strong style="display:block; margin:4px 0 12px; color:var(--text-dark); font-size:20px; font-weight:800;"><?php echo htmlspecialchars($storyRow['bubble_title']); ?></strong>
            <p style="margin:0 0 24px; font-size:15px; color:var(--muted); line-height:1.6;"><?php echo htmlspecialchars($storyRow['bubble_body']); ?></p>
            <a class="glass-btn" href="/products.php" style="width: 100%;">Peržiūrėti prekes</a>
        </div>
      </div>
    </section>

    <?php if ($freeShippingOffers): ?>
      <section class="section-shell free-shipping">
        <div class="glass-panel" style="padding: 32px;">
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
                    $offerUrl = '/produktas/' . slugify($offer['title']) . '-' . (int)$offer['product_id'];
                ?>
                <a href="<?php echo htmlspecialchars($offerUrl); ?>" class="fs-card glass-panel-inner">
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
      <div class="testimonial-grid">
          <?php foreach ($testimonials as $t): ?>
          <div class="testimonial glass-panel">
              <div class="t-name"><?php echo htmlspecialchars($t['name']); ?></div>
              <div class="t-role"><?php echo htmlspecialchars($t['role']); ?></div>
              <div class="t-text">"<?php echo htmlspecialchars($t['text']); ?>"</div>
          </div>
          <?php endforeach; ?>
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
            $newsUrl = '/naujiena/' . slugify($news['title']) . '-' . (int)$news['id'];
          ?>
          <a href="<?php echo htmlspecialchars($newsUrl); ?>" class="news-card glass-panel">
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
      <div class="support-box glass-panel">
        <div class="support-content">
            <h2><?php echo htmlspecialchars($supportBand['title']); ?></h2>
            <p><?php echo htmlspecialchars($supportBand['body']); ?></p>
            <div class="chips">
              <?php foreach ($supportBand['chips'] as $chip): ?>
                <span class="pill"><?php echo htmlspecialchars($chip); ?></span>
              <?php endforeach; ?>
            </div>
        </div>
        <div class="support-card glass-panel-inner">
            <strong style="display:block; margin:0 0 8px; color:var(--text-dark); font-size:20px; font-weight:800;"><?php echo htmlspecialchars($supportBand['card_title']); ?></strong>
            <p style="margin:0 0 24px; font-size:15px; color:var(--muted); line-height:1.6;"><?php echo htmlspecialchars($supportBand['card_body']); ?></p>
            <a class="glass-btn" href="<?php echo htmlspecialchars($supportBand['card_cta_url']); ?>"><?php echo htmlspecialchars($supportBand['card_cta_label']); ?></a>
        </div>
      </div>
    </section>

  </main>

  <?php renderFooter($pdo); ?>
</body>
</html>