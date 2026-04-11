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
    
    /* PILLS */
    .pill {
        display:inline-flex; align-items:center; padding:6px 14px;
        border-radius:999px; font-size:13px; font-weight:600;
        background:#fff;
        color:#1f2937;
        border:1px solid var(--border);
        transition: all .2s;
        text-decoration: none;
    }
    .pill:hover {
        border-color:var(--accent);
        color:var(--accent);
        background:#f0f9ff;
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
    
    .glass-card { background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.2); border-radius:16px; padding:20px; backdrop-filter:blur(12px); box-shadow:0 10px 30px rgba(0,0,0,0.1); color:#fff; }
    .glass-card h3 { margin:0 0 8px; font-size:18px; color:#fff; }
    .glass-card p { margin:0 0 12px; font-size:14px; color:#e0f2fe; line-height:1.5; }
    .glass-card a { font-weight:700; text-decoration:none; color:#fff; }

    /* SVETAINĖS AKCENTAI - SEAMLESS COMPACT */
    .promo-section { margin-bottom: 20px; }
    .promo-grid-seamless {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        width: 100%;
        position: relative;
    }
    .promo-grid-seamless::before {
        content: '';
        position: absolute;
        top: 0; left: 0; width: 100%; height: 1px;
        background: linear-gradient(90deg, transparent, var(--border), transparent);
    }
    .promo-grid-seamless::after {
        content: '';
        position: absolute;
        bottom: 0; left: 0; width: 100%; height: 1px;
        background: linear-gradient(90deg, transparent, var(--border), transparent);
    }
    a.promo-card-seamless {
        padding: 30px 10px;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        text-decoration: none;
        color: var(--text);
        transition: all 0.3s ease;
        position: relative;
    }
    @media (min-width: 601px) {
        a.promo-card-seamless:not(:last-child)::after {
            content: '';
            position: absolute;
            right: -10px; top: 20%; height: 60%; width: 1px;
            background: linear-gradient(to bottom, transparent, var(--border), transparent);
        }
    }
    a.promo-card-seamless:hover {
        transform: translateY(-3px);
    }
    .promo-icon-seamless {
        font-size: 32px;
        margin-bottom: 12px;
        transition: transform 0.3s ease;
        display: inline-block;
    }
    a.promo-card-seamless:hover .promo-icon-seamless {
        transform: scale(1.1);
    }
    .promo-content-seamless h3 {
        margin: 0 0 6px;
        font-size: 16px;
        font-weight: 700;
        color: #0f172a;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .promo-content-seamless p {
        margin: 0;
        font-size: 14px;
        line-height: 1.5;
        color: var(--muted);
        max-width: 250px;
    }

    /* ----------------------------------------------------
       NAUJOVIŠKA STORE SEKCIA (REKOMENDUOJAMOS PREKĖS)
       ---------------------------------------------------- */
    .modern-store-section { margin-bottom: 40px; }
    
    /* Pakeista antraštė: išcentruota, be papildomo teksto */
    .modern-store-header { 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        margin-bottom: 36px; 
        text-align: center;
    }
    .modern-store-header h2 { 
        font-size: clamp(26px, 5vw, 36px); 
        font-weight: 800; 
        color: #0f172a; 
        margin: 0; 
        letter-spacing: -0.02em; 
    }
    
    .modern-store-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 24px; }
    
    .modern-product-card {
        background: #ffffff;
        border-radius: 24px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.03);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        position: relative;
        border: 1px solid rgba(0,0,0,0.02);
    }
    .modern-product-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 15px 35px rgba(37, 99, 235, 0.08);
        border-color: rgba(37, 99, 235, 0.2);
    }
    .modern-product-image-wrapper {
        position: relative;
        background: #f8fafc;
        padding: 16px; /* Sumažintas padding */
        height: 140px; /* Sumažintas aukštis */
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
    }
    .modern-product-image-wrapper img {
        max-height: 100%;
        max-width: 100%;
        object-fit: contain;
        transition: transform 0.5s ease;
    }
    .modern-product-card:hover .modern-product-image-wrapper img {
        transform: scale(1.08);
    }
    .modern-product-badge {
        position: absolute;
        top: 16px;
        left: 16px;
        background: var(--accent);
        color: #fff;
        padding: 6px 12px;
        border-radius: 99px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        z-index: 2;
        box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3);
    }
    .modern-product-wishlist {
        position: absolute;
        top: 16px;
        right: 16px;
        background: #fff;
        border: none;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #94a3b8;
        cursor: pointer;
        box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        transition: all 0.2s ease;
        z-index: 2;
    }
    .modern-product-wishlist:hover {
        color: #ef4444;
        transform: scale(1.1);
    }
    .modern-product-info {
        padding: 24px;
        display: flex;
        flex-direction: column;
        flex: 1;
    }
    .modern-product-category {
        font-size: 12px;
        color: var(--accent);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 8px;
    }
    .modern-product-title {
        margin: 0 0 16px 0;
        font-size: 18px;
        font-weight: 700;
        line-height: 1.4;
        color: #0f172a;
    }
    .modern-product-title a {
        text-decoration: none;
        color: inherit;
    }
    .modern-product-title a::after {
        content: '';
        position: absolute;
        inset: 0;
    }
    .modern-product-bottom {
        margin-top: auto;
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
    }
    .modern-product-prices {
        display: flex;
        flex-direction: column;
    }
    .modern-price-old {
        font-size: 13px;
        text-decoration: line-through;
        color: #94a3b8;
        margin-bottom: 2px;
    }
    .modern-price-current {
        font-size: 22px;
        font-weight: 800;
        color: #0f172a;
    }
    .modern-add-to-cart {
        background: #0f172a;
        color: #fff;
        border: none;
        width: 44px;
        height: 44px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s ease;
        position: relative;
        z-index: 2;
    }
    .modern-add-to-cart:hover {
        background: var(--accent);
        transform: scale(1.05) rotate(-3deg);
        box-shadow: 0 8px 20px rgba(37, 99, 235, 0.3);
    }
    .modern-add-to-cart svg {
        width: 20px;
        height: 20px;
    }

    /* NAUJA: Visos prekės kortelė tinklelio gale */
    .view-all-card {
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f8fafc;
        border-radius: 24px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.03);
        border: 2px dashed #cbd5e1;
        text-decoration: none;
        color: var(--accent);
        transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        min-height: 240px; /* Sumažintas, kad derėtų prie prekių kortelių aukščio */
    }
    .view-all-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 15px 35px rgba(37, 99, 235, 0.15);
        background: var(--accent);
        color: #fff;
        border-color: var(--accent);
    }
    .view-all-content {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 16px;
        font-size: 20px;
        font-weight: 700;
    }
    .view-all-content svg {
        width: 48px;
        height: 48px;
        stroke-width: 1.5;
        transition: transform 0.3s ease;
    }
    .view-all-card:hover .view-all-content svg {
        transform: translateX(8px);
    }

    /* FULLSCREEN BENDRUOMENĖS BLOKAS */
    .community-block {
        width: 100%;
        background: linear-gradient(135deg, #eff6ff 0%, #bfdbfe 100%);
        padding: 60px 20px;
        position: relative;
        overflow: hidden;
        margin-bottom: 40px;
        display: flex;
        justify-content: center;
    }
    .community-block::before {
        content: '';
        position: absolute;
        top: -50px; right: -50px;
        width: 300px; height: 300px;
        background: rgba(255,255,255,0.6);
        border-radius: 50%;
        filter: blur(40px);
        pointer-events: none;
    }
    .community-block::after {
        content: '';
        position: absolute;
        bottom: -50px; left: 5%;
        width: 350px; height: 350px;
        background: rgba(255,255,255,0.4);
        border-radius: 50%;
        filter: blur(60px);
        pointer-events: none;
    }
    .community-block-inner {
        width: 100%;
        max-width: 1200px;
        display: grid;
        grid-template-columns: 1.1fr 1fr;
        gap: 48px;
        position: relative;
        z-index: 2;
    }
    .community-content {
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
    .community-content h2 {
        font-size: clamp(28px, 4vw, 36px);
        font-weight: 800;
        margin: 0 0 16px;
        letter-spacing: -0.02em;
        line-height: 1.2;
        color: #0f172a;
    }
    .community-content p {
        font-size: 16px;
        line-height: 1.6;
        color: #475467;
        margin: 0 0 32px;
        max-width: 450px;
    }
    .btn-community-main {
        align-self: flex-start;
        background: var(--accent);
        color: #fff;
        padding: 14px 32px;
        border-radius: 99px;
        font-weight: 700;
        font-size: 15px;
        text-decoration: none;
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
    }
    .btn-community-main:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(37, 99, 235, 0.3);
        background: var(--accent-hover);
        color: #fff;
    }
    .community-features {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }
    .c-feature-card {
        background: rgba(255, 255, 255, 0.6);
        border: 1px solid rgba(255, 255, 255, 0.8);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border-radius: 16px;
        padding: 24px;
        display: flex;
        align-items: flex-start;
        gap: 20px;
        transition: all 0.3s ease;
        text-decoration: none;
        box-shadow: 0 4px 20px rgba(37, 99, 235, 0.05);
    }
    .c-feature-card:hover {
        background: rgba(255, 255, 255, 0.9);
        transform: translateX(-6px);
        border-color: #fff;
        box-shadow: 0 8px 24px rgba(37, 99, 235, 0.1);
    }
    .c-icon-wrapper {
        background: #fff;
        width: 54px;
        height: 54px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        box-shadow: var(--shadow-sm);
    }
    .c-icon-wrapper svg {
        width: 26px; height: 26px; stroke: var(--accent);
    }
    .c-feature-text h4 {
        margin: 0 0 6px;
        font-size: 18px;
        font-weight: 700;
        color: #0f172a;
    }
    .c-feature-text p {
        margin: 0;
        font-size: 14px;
        color: #475467;
        line-height: 1.5;
    }

    /* FREE SHIPPING */
    .free-shipping-box {
        background: linear-gradient(135deg, #eff6ff, #dbeafe);
        border:1px solid #bae6fd; border-radius:20px; padding:24px;
    }
    .fs-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid #e0f2fe; padding-bottom:16px; }
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

    /* HIGHLIGHT & SUPPORT BAND */
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
        .community-block-inner, .highlight-box, .support-box { grid-template-columns: 1fr; gap:32px; }
        .testimonial-grid, .news-grid { grid-template-columns: 1fr 1fr; }
        .fs-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 768px) {
        .glass-card.support-mini {
            display: none !important;
        }
        .highlight-section .pill,
        .support-band .pill {
            display: none !important;
        }
    }
    @media (max-width: 600px) {
        .testimonial-grid, .news-grid { grid-template-columns: 1fr; }
        .hero__content { padding: 40px 20px; }
        .promo-grid-seamless { grid-template-columns: 1fr; }
        a.promo-card-seamless:not(:last-child)::after {
            display: none;
        }
        a.promo-card-seamless {
             border-bottom: 1px solid var(--border);
        }
        a.promo-card-seamless:last-child {
             border-bottom: none;
        }
        .community-block { padding: 40px 20px; }
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
      <div class="promo-grid-seamless">
        <?php foreach ($promoCards as $card): ?>
          <a href="<?php echo htmlspecialchars($card['url']); ?>" class="promo-card-seamless">
            <div class="promo-icon-seamless"><?php echo htmlspecialchars($card['icon']); ?></div>
            <div class="promo-content-seamless">
              <h3><?php echo htmlspecialchars($card['title']); ?></h3>
              <p><?php echo htmlspecialchars($card['body']); ?></p>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="section-shell modern-store-section" id="parduotuve">
      <div class="modern-store-header">
        <h2>Rekomenduojamos prekės</h2>
      </div>

      <div class="modern-store-grid">
        <?php foreach ($featuredProducts as $product): ?>
          <?php
            $priceDisplay = buildPriceDisplay($product, $globalDiscount, $categoryDiscounts);
            $productUrl = '/produktas/' . slugify($product['title']) . '-' . (int)$product['id'];
          ?>
          <article class="modern-product-card">
            <div class="modern-product-image-wrapper">
              <?php if (!empty($product['ribbon_text'])): ?>
                <div class="modern-product-badge"><?php echo htmlspecialchars($product['ribbon_text']); ?></div>
              <?php endif; ?>
              
              <form method="post" style="margin:0; padding:0;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                <button class="modern-product-wishlist" name="action" value="wishlist" type="submit" title="Į norų sąrašą">
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
                </button>
              </form>

              <a href="<?php echo htmlspecialchars($productUrl); ?>" style="display:contents;">
                <img src="<?php echo htmlspecialchars($product['primary_image'] ?: $product['image_url']); ?>" alt="<?php echo htmlspecialchars($product['title']); ?>" loading="lazy">
              </a>
            </div>
            
            <div class="modern-product-info">
              <div class="modern-product-category"><?php echo htmlspecialchars($product['category_name'] ?? 'Priedai'); ?></div>
              <h3 class="modern-product-title">
                <a href="<?php echo htmlspecialchars($productUrl); ?>"><?php echo htmlspecialchars($product['title']); ?></a>
              </h3>
              
              <div class="modern-product-bottom">
                <div class="modern-product-prices">
                  <?php if ($priceDisplay['has_discount']): ?>
                    <span class="modern-price-old"><?php echo number_format($priceDisplay['original'], 2); ?> €</span>
                  <?php endif; ?>
                  <span class="modern-price-current"><?php echo number_format($priceDisplay['current'], 2); ?> €</span>
                </div>
                
                <form method="post" style="margin:0; padding:0;">
                  <?php echo csrfField(); ?>
                  <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                  <button class="modern-add-to-cart" type="submit" name="quantity" value="1" title="Į krepšelį">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                  </button>
                </form>
              </div>
            </div>
          </article>
        <?php endforeach; ?>

        <a href="/products.php" class="view-all-card">
            <div class="view-all-content">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                </svg>
                <span>Visos prekės</span>
            </div>
        </a>
      </div>
    </section>

    <section class="community-block" id="bendruomene">
      <div class="community-block-inner">
        <div class="community-content">
          <h2>Prisijunkite prie Cukrinuko bendruomenės</h2>
          <p>Mes – ne tik parduotuvė. Kartu kuriame erdvę, kurioje dalinamės patirtimi, ieškome atsakymų ir palaikome vieni kitus. Prisijunkite prie diskusijų arba raskite bei parduokite diabeto priežiūros priemones mūsų turgelyje.</p>
          <div class="community-actions">
            <a href="/community.php" class="btn-community-main">Atrasti bendruomenę →</a>
          </div>
        </div>
        <div class="community-features">
          <a href="/community_discussions.php" class="c-feature-card">
            <div class="c-icon-wrapper">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2h-2v4l-4-4H9a1.994 1.994 0 01-1.414-.586m0 0L11 14h4a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2v4l.586-.586z" />
              </svg>
            </div>
            <div class="c-feature-text">
              <h4>Pokalbiai ir patarimai</h4>
              <p>Klauskite, diskutuokite ir dalinkitės kasdiene diabeto patirtimi su tais, kurie jus supranta geriausiai.</p>
            </div>
          </a>
          <a href="/community_market.php" class="c-feature-card">
            <div class="c-icon-wrapper">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
              </svg>
            </div>
            <div class="c-feature-text">
              <h4>Bendruomenės Turgelis</h4>
              <p>Parduokite nereikalingas priemones arba ieškokite geriausių pasiūlymų iš kitų bendruomenės narių rankų.</p>
            </div>
          </a>
        </div>
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