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
    
    // Likučio patikra
    $stmtStock = $pdo->prepare("SELECT quantity FROM products WHERE id = ?");
    $stmtStock->execute([$pid]);
    $stock = $stmtStock->fetchColumn();
    
    $currentInCart = $_SESSION['cart'][$pid] ?? 0;
    if ($stock !== false && (int)$stock < ($currentInCart + $qty)) {
        $_SESSION['cart_error'] = "Nepavyko įdėti į krepšelį: prekė išparduota arba neturime pageidaujamo kiekio sandėlyje.";
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    $_SESSION['cart'][$pid] = $currentInCart + $qty;
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
    'bubble_body' => $siteContent['storyrow_bubble_body'] ?? 'Suderiname atsargas pagal jūsų dienos režimą.',
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
        (SELECT path FROM product_images WHERE product_id = p.id AND is_primary = 1 ORDER BY id DESC LIMIT 1) AS primary_image,
        (SELECT COUNT(*) FROM product_variations WHERE product_id = p.id) AS has_variations
        FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id IN (' . $placeholders . ')');
    $stmt->execute($featuredIds);
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $row) { $map[$row['id']] = $row; }
    foreach ($featuredIds as $fid) { if (!empty($map[$fid])) { $featuredProducts[] = $map[$fid]; } }
}
$categories = $pdo->query('SELECT id, name, slug FROM categories ORDER BY name ASC')->fetchAll();
$freeShippingOffers = getFreeShippingProducts($pdo);

// Gauname patį naujausią receptą
$latestRecipe = $pdo->query('SELECT id, title, image_url FROM recipes ORDER BY created_at DESC LIMIT 1')->fetch();

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
        width: 100%; max-width: 1200px; margin: 0 auto 24px; padding: 0 20px;
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
    .hero { width:100%; margin-bottom:24px; position:relative; background:var(--accent); color:#fff; overflow:hidden; }
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
    .promo-section { margin-bottom: 16px; }
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

    /* NAUJOVIŠKA STORE SEKCIA */
    .modern-store-section { margin-bottom: 24px; }
    
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
        height: 220px;
        display: block;
        overflow: hidden;
        border-bottom: 1px solid var(--border);
    }
    .modern-product-image-wrapper img {
        width: 100%;
        height: 100%;
        object-fit: cover;
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
        min-height: 240px;
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

    /* FULLSCREEN BENDRUOMENĖS BLOKAS SU ŠVELNIU IŠBLUKIMU */
    .community-block {
        width: 100%;
        background: 
            linear-gradient(to bottom, var(--bg) 0%, transparent 10%, transparent 90%, var(--bg) 100%),
            radial-gradient(circle at 100% 0%, rgba(255,255,255,0.6) 0%, transparent 35%),
            radial-gradient(circle at 5% 100%, rgba(255,255,255,0.4) 0%, transparent 40%),
            linear-gradient(135deg, #eff6ff 0%, #bfdbfe 100%);
        padding: 40px 20px;
        position: relative;
        overflow: hidden;
        margin-bottom: 0;
        display: flex;
        justify-content: center;
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
        align-items: center; justify-content: center;
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

    /* SKANIAM IR PATOGIAM GYVENIMUI (Fullscreen) */
    .lifestyle-block {
        width: 100%;
        background: var(--bg);
        padding: 20px 20px 40px;
        position: relative;
        margin-bottom: 0;
        display: flex;
        justify-content: center;
        overflow: hidden;
    }
    .lifestyle-inner {
        width: 100%;
        max-width: 1200px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 60px;
        align-items: center;
    }
    .lifestyle-content h2 {
        font-size: clamp(32px, 5vw, 42px);
        font-weight: 800;
        color: #0f172a;
        line-height: 1.15;
        margin: 0 0 20px;
        letter-spacing: -0.02em;
    }
    .lifestyle-content p.lead {
        font-size: 16px;
        color: #475467;
        line-height: 1.7;
        margin: 0 0 32px;
    }
    .lifestyle-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
    }
    .lifestyle-chip {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #0f172a;
        padding: 8px 16px;
        border-radius: 99px;
        font-weight: 600;
        font-size: 13px;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s;
    }
    .lifestyle-chip:hover {
        background: var(--accent-light);
        border-color: var(--accent);
        color: var(--accent);
    }
    .lifestyle-visual {
        position: relative;
        display: flex;
        justify-content: center;
        align-items: center;
    }
    .lifestyle-image-wrapper {
        position: relative;
        border-radius: 24px;
        overflow: hidden;
        box-shadow: 0 20px 40px rgba(0,0,0,0.08);
        width: 100%;
        aspect-ratio: 4/3;
    }
    .lifestyle-image-wrapper img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.7s ease;
    }
    .lifestyle-image-wrapper:hover img {
        transform: scale(1.05);
    }
    
    .lifestyle-card {
        position: absolute;
        top: 20px;
        left: -20px;
        background: #fff;
        padding: 20px;
        border-radius: 20px;
        box-shadow: 0 15px 35px rgba(0,0,0,0.1);
        max-width: 300px;
        border: 1px solid rgba(0,0,0,0.05);
        z-index: 2;
        transition: transform 0.3s;
    }
    .lifestyle-card:hover {
        transform: translateY(-5px);
    }
    .lifestyle-card-meta {
        font-size: 12px;
        font-weight: 700;
        color: var(--accent);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 8px;
        display: block;
    }
    .lifestyle-card strong {
        display: block;
        font-size: 18px;
        color: #0f172a;
        margin-bottom: 8px;
        line-height: 1.3;
    }
    .lifestyle-card p {
        font-size: 14px;
        color: #475467;
        margin: 0 0 16px;
        line-height: 1.5;
    }
    
    .lifestyle-all-card {
        position: absolute;
        bottom: -20px;
        left: -20px;
        background: rgba(255,255,255,0.95);
        backdrop-filter: blur(10px);
        padding: 16px 24px;
        border-radius: 16px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        border: 1px solid #e2e8f0;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 16px;
        transition: transform 0.3s ease;
        z-index: 2;
    }
    .lifestyle-all-card:hover {
        transform: translateY(-5px);
        border-color: var(--accent);
    }
    .lifestyle-all-card .lac-icon {
        background: var(--accent-light);
        color: var(--accent);
        width: 44px;
        height: 44px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .lifestyle-all-card .lac-text {
        display: flex;
        flex-direction: column;
    }
    .lifestyle-all-card strong {
        color: #0f172a;
        font-size: 16px;
        line-height: 1.2;
    }
    .lifestyle-all-card span {
        color: #64748b;
        font-size: 13px;
        font-weight: 500;
    }

    /* FULLSCREEN ATNAUJINIMO BLOKAS */
    .update-block {
        width: 100%;
        background: 
            linear-gradient(to bottom, var(--bg) 0%, transparent 10%, transparent 90%, var(--bg) 100%),
            radial-gradient(circle at 0% 0%, rgba(255,255,255,0.7) 0%, transparent 40%),
            radial-gradient(circle at 100% 100%, rgba(37, 99, 235, 0.08) 0%, transparent 50%),
            linear-gradient(135deg, #e0f2fe 0%, #bfdbfe 100%);
        padding: 40px 20px;
        position: relative;
        overflow: hidden;
        margin-bottom: 0;
        display: flex;
        justify-content: center;
        align-items: center;
    }
    .update-block-inner {
        width: 100%;
        max-width: 1200px;
        position: relative;
        z-index: 2;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    .update-header {
        text-align: center;
        margin-bottom: 48px;
        max-width: 700px;
    }
    .update-tag {
        display: inline-block;
        background: #93c5fd;
        color: #1e3a8a;
        padding: 6px 16px;
        border-radius: 99px;
        font-size: 13px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 16px;
        box-shadow: 0 4px 10px rgba(37, 99, 235, 0.1);
    }
    .update-header h2 {
        font-size: clamp(28px, 5vw, 40px);
        font-weight: 800;
        color: #0f172a;
        margin: 0 0 16px;
        letter-spacing: -0.02em;
        line-height: 1.2;
    }
    .update-header p {
        font-size: 16px;
        color: #475467;
        line-height: 1.6;
        margin: 0;
    }
    .update-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 24px;
        width: 100%;
        margin-bottom: 48px;
    }
    .update-card {
        background: rgba(255, 255, 255, 0.6);
        border: 1px solid rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border-radius: 24px;
        padding: 32px 24px;
        text-align: center;
        transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        box-shadow: 0 4px 20px rgba(0,0,0,0.03);
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    .update-card:hover {
        transform: translateY(-8px);
        background: rgba(255, 255, 255, 0.95);
        box-shadow: 0 15px 35px rgba(37, 99, 235, 0.08);
        border-color: #fff;
    }
    .update-card-icon {
        width: 60px;
        height: 60px;
        background: #fff;
        color: var(--accent);
        border-radius: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 20px;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.1);
    }
    .update-card-icon svg {
        width: 28px;
        height: 28px;
        stroke-width: 1.5;
    }
    .update-card h3 {
        font-size: 18px;
        font-weight: 800;
        color: #0f172a;
        margin: 0 0 12px;
    }
    .update-card p {
        font-size: 14px;
        color: #475467;
        margin: 0;
        line-height: 1.6;
    }
    .update-actions {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 16px;
    }
    .btn-update-main {
        background: var(--accent);
        color: #fff;
        padding: 16px 44px;
        border-radius: 99px;
        font-weight: 700;
        font-size: 16px;
        text-decoration: none;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(37, 99, 235, 0.25);
    }
    .btn-update-main:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(37, 99, 235, 0.35);
        background: var(--accent-hover);
        color: #fff;
    }
    .update-reward-text {
        font-size: 14px;
        color: #475467;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        background: rgba(255, 255, 255, 0.7);
        padding: 8px 20px;
        border-radius: 99px;
        border: 1px solid rgba(255, 255, 255, 0.9);
    }
    .update-reward-text svg {
        width: 18px;
        height: 18px;
        color: #eab308;
    }
      
    /* MODERN NEWS BLOCK (COMPACT) */
    .modern-news-section { margin-bottom: 24px; }
    .modern-news-header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; margin-bottom: 24px; gap: 16px; }
    .modern-news-header h2 { font-size: clamp(22px, 4vw, 28px); font-weight: 800; color: #0f172a; margin: 0; letter-spacing: -0.02em; text-transform: uppercase; }
    .modern-news-header .pill { border-radius: 99px; font-weight: 600; padding: 8px 16px; background: var(--accent-light); color: var(--accent); border: none; font-size: 13px; }
    .modern-news-header .pill:hover { background: var(--accent); color: #fff; }

    .modern-news-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; }

    @media (min-width: 900px) {
        .modern-news-grid { grid-template-columns: repeat(4, 1fr); }
    }

    .modern-news-card {
        border-radius: 16px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        justify-content: flex-end;
        position: relative;
        box-shadow: 0 2px 10px rgba(0,0,0,0.03);
        transition: all 0.3s ease;
        text-decoration: none;
        color: #fff;
        min-height: 280px;
    }
    .modern-news-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 25px rgba(37, 99, 235, 0.2);
    }
    
    .modern-news-image { position: absolute; inset: 0; z-index: 1; }
    .modern-news-image img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s ease; }
    .modern-news-card:hover .modern-news-image img { transform: scale(1.05); }
    .modern-news-image::after { 
        content: ''; position: absolute; inset: 0; 
        background: linear-gradient(to top, rgba(15, 23, 42, 0.9) 0%, rgba(15, 23, 42, 0.4) 50%, transparent 100%); 
        transition: opacity 0.3s ease; 
    }
    .modern-news-card:hover .modern-news-image::after { background: linear-gradient(to top, rgba(15, 23, 42, 0.95) 0%, rgba(15, 23, 42, 0.5) 60%, transparent 100%); }

    .modern-news-date {
        position: absolute; top: 16px; left: 16px;
        background: rgba(255, 255, 255, 0.2); backdrop-filter: blur(10px); color: #fff;
        border: 1px solid rgba(255, 255, 255, 0.3);
        padding: 6px 12px; border-radius: 99px; font-size: 11px; font-weight: 700;
        box-shadow: 0 4px 10px rgba(0,0,0,0.1); z-index: 3;
        letter-spacing: 0.05em;
    }

    .modern-news-content {
        padding: 20px; display: flex; flex-direction: column; position: relative; z-index: 2;
    }
    
    .modern-news-title {
        margin: 0 0 8px; font-size: 16px; font-weight: 800; line-height: 1.4; color: #fff;
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        text-shadow: 0 2px 4px rgba(0,0,0,0.3);
    }
    
    .modern-news-excerpt {
        font-size: 13px; color: #cbd5e1; line-height: 1.5; margin: 0 0 16px;
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    }

    .modern-news-readmore {
        display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700;
        color: #93c5fd; text-transform: uppercase; letter-spacing: 0.05em; transition: color 0.3s;
    }
    .modern-news-readmore svg { width: 14px; height: 14px; transition: transform 0.3s ease; stroke-width: 2.5; }
    .modern-news-card:hover .modern-news-readmore { color: #fff; }
    .modern-news-card:hover .modern-news-readmore svg { transform: translateX(4px); }

    /* MODERN TESTIMONIALS */
    .modern-testimonials { margin-bottom: 40px; }
    .modern-testimonials-header { text-align: center; margin-bottom: 40px; }
    .modern-testimonials-header h2 { font-size: clamp(26px, 5vw, 36px); font-weight: 800; color: #0f172a; margin: 0; letter-spacing: -0.02em; text-transform: uppercase; }
    
    .modern-testimonial-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px; }
    
    .modern-t-card {
        background: #ffffff;
        border-radius: 24px;
        padding: 32px;
        position: relative;
        box-shadow: 0 4px 20px rgba(0,0,0,0.03);
        border: 1px solid rgba(0,0,0,0.04);
        transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        display: flex;
        flex-direction: column;
        z-index: 1;
        overflow: hidden;
    }
    .modern-t-card::before {
        content: "”";
        position: absolute;
        top: -10px;
        right: 20px;
        font-size: 140px;
        font-family: Georgia, serif;
        color: var(--accent-light);
        z-index: -1;
        opacity: 0.7;
        line-height: 1;
    }
    .modern-t-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 15px 35px rgba(37, 99, 235, 0.08);
        border-color: rgba(37, 99, 235, 0.2);
    }
    .modern-t-stars {
        display: flex;
        gap: 4px;
        color: #fbbf24;
        margin-bottom: 20px;
    }
    .modern-t-stars svg { width: 20px; height: 20px; fill: currentColor; }
    
    .modern-t-text {
        font-size: 16px;
        line-height: 1.7;
        color: #475467;
        flex-grow: 1;
        margin-bottom: 28px;
        font-style: italic;
        position: relative;
    }
    
    .modern-t-author {
        display: flex;
        align-items: center;
        gap: 16px;
        border-top: 1px solid var(--border);
        padding-top: 20px;
    }
    
    .modern-t-avatar {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--accent), #60a5fa);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 18px;
        flex-shrink: 0;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
    }
    
    .modern-t-meta { display: flex; flex-direction: column; }
    .modern-t-name { font-weight: 700; color: #0f172a; font-size: 16px; margin-bottom: 2px; }
    .modern-t-role { font-size: 13px; color: var(--muted); font-weight: 500; }

    /* MODERN FREE SHIPPING BLOCK (FULLSCREEN LIKE COMMUNITY-BLOCK) */
    .free-shipping-block {
        width: 100%;
        background: 
            linear-gradient(to bottom, var(--bg) 0%, transparent 10%, transparent 90%, var(--bg) 100%),
            radial-gradient(circle at 100% 0%, rgba(255,255,255,0.7) 0%, transparent 35%),
            radial-gradient(circle at 0% 100%, rgba(255,255,255,0.5) 0%, transparent 40%),
            linear-gradient(135deg, #eff6ff 0%, #bfdbfe 100%);
        padding: 40px 20px;
        position: relative;
        overflow: hidden;
        margin-bottom: 40px;
        display: flex;
        justify-content: center;
    }
    .fs-modern-wrapper {
        width: 100%;
        max-width: 1200px;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        position: relative;
        z-index: 2;
    }
    .fs-modern-header {
        margin-bottom: 40px;
    }
    .fs-modern-icon {
        display: inline-flex; align-items: center; justify-content: center;
        width: 80px; height: 80px; background: #fff;
        border-radius: 50%; margin-bottom: 20px;
        color: var(--accent);
        box-shadow: 0 10px 25px rgba(37, 99, 235, 0.1);
    }
    .fs-modern-title {
        font-size: clamp(28px, 5vw, 40px); font-weight: 800; margin: 0 0 12px;
        letter-spacing: -0.02em; color: #0f172a;
    }
    .fs-modern-subtitle {
        font-size: 16px; color: #475467; max-width: 540px; margin: 0 auto; line-height: 1.6;
    }
    .fs-modern-grid {
        display: flex; flex-wrap: wrap; justify-content: center; gap: 24px;
        width: 100%; max-width: 1000px; position: relative; z-index: 2;
    }
    .fs-modern-card {
        flex: 1 1 200px; max-width: 240px;
        background: #ffffff; border-radius: 20px;
        padding: 24px 20px; text-decoration: none; color: #0f172a;
        transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        display: flex; flex-direction: column; align-items: center; text-align: center;
        position: relative;
        box-shadow: 0 10px 30px rgba(0,0,0,0.05);
        border: 1px solid rgba(255,255,255,0.8);
    }
    .fs-modern-card:hover {
        transform: translateY(-10px);
        box-shadow: 0 20px 40px rgba(37, 99, 235, 0.15);
    }
    .fs-modern-card-img-wrap {
        width: 100%; aspect-ratio: 1; margin-bottom: 16px;
        display: flex; align-items: center; justify-content: center;
        background: #f8fafc; border-radius: 12px; overflow: hidden;
        position: relative;
    }
    .fs-modern-card-img-wrap img {
        width: 80%; height: 80%; object-fit: contain;
        transition: transform 0.5s ease;
    }
    .fs-modern-card:hover .fs-modern-card-img-wrap img {
        transform: scale(1.15) rotate(2deg);
    }
    .fs-modern-card h4 {
        margin: 0 0 10px; font-size: 15px; font-weight: 700; line-height: 1.4;
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    }
    .fs-modern-price {
        font-size: 20px; font-weight: 800; color: var(--accent); margin-top: auto;
    }
    .fs-modern-badge {
        position: absolute; top: -12px; right: -12px;
        background: #10b981; color: #fff; font-size: 12px; font-weight: 800;
        padding: 6px 14px; border-radius: 99px; text-transform: uppercase;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); border: 2px solid #fff;
        z-index: 3;
    }

    /* SUPPORT BAND */
    .support-box {
        background:#fff; border:1px solid var(--border); border-radius:20px; padding:32px;
        display:grid; grid-template-columns: 1.2fr 1fr; gap:40px; box-shadow:var(--shadow-sm);
    }
    .support-content h2 { margin:0 0 12px; font-size:24px; color:#0f172a; }
    .support-content p { color:#475467; line-height:1.6; margin-bottom:20px; }
    
    .support-card {
        background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px; padding:20px;
    }
    
    .support-card .btn {
        width:100%;
        background: #fff;
        color: #1f2937;
        border: 1px solid var(--border);
        font-weight: 600;
        border-radius: 999px;
    }
    .support-card .btn:hover {
        border-color: var(--accent); color: var(--accent); background: #f0f9ff;
    }
    
    .chips { display:flex; gap:8px; flex-wrap:wrap; }

    /* MEDIA QUERIES */
    @media (max-width: 900px) {
        .community-block-inner, .support-box { grid-template-columns: 1fr; gap:32px; }
        .lifestyle-inner { grid-template-columns: 1fr; gap: 40px; }
        .lifestyle-card { top: -20px; left: 10px; right: 10px; max-width: none; }
        .lifestyle-block { padding: 0 20px 40px; }
        .lifestyle-all-card { left: 10px; right: 10px; bottom: -20px; justify-content: center; }
        .update-banner { flex-direction: column; text-align: center; }
        .update-banner-actions { width: 100%; }
    }
    @media (max-width: 768px) {
        .glass-card.support-mini { display: none !important; }
        .support-band .pill { display: none !important; }
    }
    @media (max-width: 600px) {
        .modern-news-grid { grid-template-columns: 1fr; }
        .hero__content { padding: 40px 20px; }
        .promo-grid-seamless { grid-template-columns: 1fr; }
        a.promo-card-seamless:not(:last-child)::after { display: none; }
        a.promo-card-seamless { border-bottom: 1px solid var(--border); }
        a.promo-card-seamless:last-child { border-bottom: none; }
        .community-block { padding: 40px 20px; }
    }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'home'); ?>

  <main class="page-shell">
    <?php if (!empty($_SESSION['cart_error'])): ?>
        <div style="width: 100%; max-width: 1200px; margin: 20px auto 0; padding: 15px; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 8px; text-align: center; font-weight: 500; z-index: 10; position: relative;">
            <?php echo htmlspecialchars($_SESSION['cart_error']); unset($_SESSION['cart_error']); ?>
        </div>
    <?php endif; ?>
    
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
        <h2>Rekomenduojame!</h2>
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
                
                <div style="display: flex; align-items: center; gap: 8px;">
                <?php if (!empty($product['has_variations'])): ?>
                    <a href="<?php echo htmlspecialchars($productUrl); ?>" class="modern-add-to-cart" title="Pasirinkti variantą" style="text-decoration: none;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>
                    </a>
                <?php elseif ((int)$product['quantity'] <= 0): ?>
                    <span style="color: #dc2626; font-weight: 700; font-size: 14px; padding-left: 10px;">Išparduota</span>
                <?php else: ?>
                <form method="post" style="margin:0; padding:0;">
                  <?php echo csrfField(); ?>
                  <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                  <button class="modern-add-to-cart" type="submit" name="quantity" value="1" title="Į krepšelį">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                  </button>
                </form>
                <?php endif; ?>
                </div>
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
              <h4>Bendruomenės turgelis</h4>
              <p>Parduokite nereikalingas priemones arba ieškokite geriausių pasiūlymų iš kitų bendruomenės narių rankų.</p>
            </div>
          </a>
        </div>
      </div>
    </section>

    <section class="lifestyle-block">
      <div class="lifestyle-inner">
        <div class="lifestyle-content">
            <h2><?php echo htmlspecialchars($storyRow['title']); ?></h2>
            <p class="lead"><?php echo htmlspecialchars($storyRow['body']); ?></p>
            
            <div class="lifestyle-chips">
              <?php foreach ($storyRow['pills'] as $pill): ?>
                <span class="lifestyle-chip">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>
                    <?php echo htmlspecialchars($pill); ?>
                </span>
              <?php endforeach; ?>
            </div>
        </div>
        
        <div class="lifestyle-visual">
            <?php 
            $recipe1 = $latestRecipe; // Paskutinis receptas
            ?>

            <?php if ($recipe1): ?>
                <?php $recipeUrl = '/receptas/' . slugify($recipe1['title']) . '-' . $recipe1['id']; ?>
                <a href="<?php echo htmlspecialchars($recipeUrl); ?>" class="lifestyle-image-wrapper" style="display:block; text-decoration:none;">
                    <img src="<?php echo htmlspecialchars($recipe1['image_url']); ?>" alt="<?php echo htmlspecialchars($recipe1['title']); ?>" loading="lazy">
                </a>
                
                <a href="<?php echo htmlspecialchars($recipeUrl); ?>" class="lifestyle-card" style="text-decoration:none;">
                    <span class="lifestyle-card-meta"><?php echo htmlspecialchars($storyRow['bubble_meta']); ?></span>
                    <strong><?php echo htmlspecialchars($recipe1['title']); ?></strong>
                    <p><?php echo htmlspecialchars($storyRow['bubble_body']); ?></p>
                    <span style="font-size: 14px; font-weight: 700; color: var(--accent);">Žiūrėti receptą →</span>
                </a>
            <?php else: ?>
                <div class="lifestyle-image-wrapper">
                    <img src="https://images.pexels.com/photos/1640777/pexels-photo-1640777.jpeg?auto=compress&cs=tinysrgb&w=800" alt="Skanus ir patogus gyvenimas" loading="lazy">
                </div>
                
                <div class="lifestyle-card">
                    <span class="lifestyle-card-meta"><?php echo htmlspecialchars($storyRow['bubble_meta']); ?></span>
                    <strong>Pusryčių dubenėlis su uogomis</strong>
                    <p><?php echo htmlspecialchars($storyRow['bubble_body']); ?></p>
                    <a href="/recipes.php" style="font-size: 14px; font-weight: 700; color: var(--accent); text-decoration: none;">Visi receptai →</a>
                </div>
            <?php endif; ?>
            
            <a href="/recipes.php" class="lifestyle-all-card">
                <div class="lac-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                    </svg>
                </div>
                <div class="lac-text">
                    <strong>Visi receptai</strong>
                    <span>Atrasti daugiau skonių</span>
                </div>
            </a>
        </div>
      </div>
    </section>

    <section class="update-block">
      <div class="update-block-inner">
        <div class="update-header">
          <h2>Tobulėjame kartu su jumis!</h2>
          <p>Mūsų parduotuvė šiuo metu yra aktyviai atnaujinama. Norime sukurti kuo geresnę ir patogesnę erdvę jums, todėl kviečiame prisidėti prie Cukrinuko tobulinimo.</p>
        </div>

        <div class="update-grid">
          <div class="update-card">
            <div class="update-card-icon">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
            </div>
            <h3>Pastebėjote klaidą?</h3>
            <p>Radote neveikiančią nuorodą, netikslumą ar kitokią klaidą? Praneškite mums.</p>
          </div>

          <div class="update-card">
            <div class="update-card-icon">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
              </svg>
            </div>
            <h3>Turite idėjų?</h3>
            <p>Žinote, kaip galėtume patobulinti svetainę ir padaryti ją patogesne? Laukiame jūsų pasiūlymų.</p>
          </div>

          <div class="update-card">
            <div class="update-card-icon">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
              </svg>
            </div>
            <h3>Trūksta prekių?</h3>
            <p>Nerandate reikiamų pleistrų, priemonių ar mėgstamo užkandžio? Pasakykite ko pasigendate.</p>
          </div>

          <div class="update-card">
            <div class="update-card-icon">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
            </div>
            <h3>Dalinkitės patirtimi</h3>
            <p>Žinote puikų receptą ar naudingą straipsnį? Pasidalinkite su bendruomene!</p>
          </div>
        </div>

        <div class="update-actions">
          <a href="/contact.php" class="btn-update-main">Susisiekti su mumis</a>
          <span class="update-reward-text">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M5 2a1 1 0 011 1v1h1a1 1 0 010 2H6v1a1 1 0 01-2 0V6H3a1 1 0 010-2h1V3a1 1 0 011-1zm0 10a1 1 0 011 1v1h1a1 1 0 110 2H6v1a1 1 0 11-2 0v-1H3a1 1 0 110-2h1v-1a1 1 0 011-1zM12 2a1 1 0 01.967.744L14.146 7.2 17.5 9.134a1 1 0 010 1.732l-3.354 1.935-1.18 4.455a1 1 0 01-1.933 0L9.854 12.8 6.5 10.866a1 1 0 010-1.732l3.354-1.935 1.18-4.455A1 1 0 0112 2z" clip-rule="evenodd" />
            </svg>
            Už naudingas įžvalgas ir pagalbą atsidėkosime smulkiu apdovanojimu!
          </span>
        </div>
      </div>
    </section>

    <section class="section-shell modern-news-section" id="naujienos">
      <div class="modern-news-header">
        <h2>NAUJIENOS</h2>
        <a class="pill" href="/news.php">Visos naujienos →</a>
      </div>
      <div class="modern-news-grid">
        <?php foreach ($featuredNews as $news): ?>
          <?php
            // SEO URL Naujienoms
            $newsUrl = '/naujiena/' . slugify($news['title']) . '-' . (int)$news['id'];
          ?>
          <a href="<?php echo htmlspecialchars($newsUrl); ?>" class="modern-news-card">
            <div class="modern-news-image">
              <span class="modern-news-date"><?php echo date('Y-m-d', strtotime($news['created_at'])); ?></span>
              <img src="<?php echo htmlspecialchars($news['image_url']); ?>" alt="<?php echo htmlspecialchars($news['title']); ?>" loading="lazy">
            </div>
            <div class="modern-news-content">
              <h3 class="modern-news-title"><?php echo htmlspecialchars($news['title']); ?></h3>
              <?php
                $excerpt = trim($news['summary'] ?? '');
                if (!$excerpt) $excerpt = strip_tags($news['body']);
                if (mb_strlen($excerpt) > 100) $excerpt = mb_substr($excerpt, 0, 100) . '...';
              ?>
              <p class="modern-news-excerpt"><?php echo htmlspecialchars($excerpt); ?></p>
              <div class="modern-news-readmore">
                Skaityti daugiau
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                </svg>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </section>

    <?php if ($freeShippingOffers): ?>
      <section class="free-shipping-block">
        <div class="fs-modern-wrapper">
          <div class="fs-modern-header">
            <div class="fs-modern-icon">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="width: 40px; height: 40px;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12" />
              </svg>
            </div>
            <h2 class="fs-modern-title">Nemokamas pristatymas</h2>
            <p class="fs-modern-subtitle">Pridėkite bent vieną iš šių prekių į savo krepšelį ir mes pristatysime <strong>visą užsakymą</strong> visiškai nemokamai!</p>
          </div>
          
          <div class="fs-modern-grid">
            <?php foreach ($freeShippingOffers as $offer): ?>
              <?php
                $priceDisplay = buildPriceDisplay($offer, $globalDiscount, $categoryDiscounts);
                // SEO URL
                $offerUrl = '/produktas/' . slugify($offer['title']) . '-' . (int)$offer['product_id'];
              ?>
              <a href="<?php echo htmlspecialchars($offerUrl); ?>" class="fs-modern-card">
                <div class="fs-modern-badge">NEMOKAMAI</div>
                <div class="fs-modern-card-img-wrap">
                    <img src="<?php echo htmlspecialchars($offer['primary_image'] ?: $offer['image_url']); ?>" alt="<?php echo htmlspecialchars($offer['title']); ?>">
                </div>
                <h4><?php echo htmlspecialchars($offer['title']); ?></h4>
                <div class="fs-modern-price"><?php echo number_format($priceDisplay['current'], 2); ?> €</div>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <section class="section-shell modern-testimonials">
      <div class="modern-testimonials-header">
        <h2>Atsiliepimai</h2>
      </div>
      <div class="modern-testimonial-grid">
        <?php foreach ($testimonials as $t): ?>
        <?php 
            $initial = mb_substr(trim($t['name']), 0, 1);
            if (!$initial) $initial = 'C';
        ?>
        <div class="modern-t-card">
            <div class="modern-t-stars">
                <?php for($i=0; $i<5; $i++): ?>
                <svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                <?php endfor; ?>
            </div>
            <div class="modern-t-text">"<?php echo htmlspecialchars($t['text']); ?>"</div>
            <div class="modern-t-author">
                <div class="modern-t-avatar"><?php echo htmlspecialchars(mb_strtoupper($initial)); ?></div>
                <div class="modern-t-meta">
                    <span class="modern-t-name"><?php echo htmlspecialchars($t['name']); ?></span>
                    <span class="modern-t-role"><?php echo htmlspecialchars($t['role']); ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>
  </main>

  <?php renderFooter($pdo); ?>
</body>
</html>
