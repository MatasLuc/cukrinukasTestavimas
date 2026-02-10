<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require_once __DIR__ . '/helpers.php';

$pdo = getPdo();
ensureProductsTable($pdo);
ensureCategoriesTable($pdo);
ensureCartTables($pdo);
ensureSavedContentTables($pdo);
tryAutoLogin($pdo);

if (function_exists('ensureProductRelations')) {
    ensureProductRelations($pdo);
}
ensureAdminAccount($pdo);
$freeShippingIds = getFreeShippingProductIds($pdo);

$id = (int) ($_GET['id'] ?? 0);
$error = ''; 

// Pagrindinė produkto užklausa
$stmt = $pdo->prepare('SELECT p.*, c.name AS category_name, c.slug AS category_slug FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ? LIMIT 1');
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    http_response_code(404);
    echo 'Prekė nerasta';
    exit;
}

// Nuotraukos
$imagesStmt = $pdo->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id ASC');
$imagesStmt->execute([$id]);
$images = $imagesStmt->fetchAll();

// Atributai
$attributesStmt = $pdo->prepare('SELECT label, value FROM product_attributes WHERE product_id = ?');
$attributesStmt->execute([$id]);
$attributes = $attributesStmt->fetchAll();

// Variacijos
$variationsStmt = $pdo->prepare('
    SELECT pv.*, pi.path as variation_image
    FROM product_variations pv
    LEFT JOIN product_images pi ON pv.image_id = pi.id
    WHERE pv.product_id = ? 
    ORDER BY pv.group_name ASC, pv.id ASC
');
$variationsStmt->execute([$id]);
$variations = $variationsStmt->fetchAll();

// Variacijų grupavimas ir bendros būsenos nustatymas
$groupedVariations = [];
$variationMap = [];
$hasAnyStock = false; // Ar bent viena variacija prieinama?

if (!empty($variations)) {
    foreach ($variations as $var) {
        $group = $var['group_name'] ?: 'Pasirinkimas';
        $groupedVariations[$group][] = $var;
        $variationMap[(int)$var['id']] = $var;
        
        // Logika: Jei NESEKA likučio (track_stock=0) ARBA likutis > 0, vadinasi prekė prieinama.
        if ((int)$var['track_stock'] === 0 || (int)$var['quantity'] > 0) {
            $hasAnyStock = true;
        }
    }
} else {
    // Jei nėra variacijų, žiūrime pagrindinį produkto likutį
    // Paprastoms prekėms dažniausiai tiesiog žiūrimas quantity > 0
    // Jei norite, kad ir paprastos prekės būtų "neribotos", reikėtų papildomo stulpelio products lentelėje,
    // bet pagal setup.php products lentelė neturi track_stock, tad kliaujamės quantity.
    $hasAnyStock = ($product['quantity'] > 0);
}

// Susijusios prekės
$relStmt = $pdo->prepare('SELECT pr.related_product_id, p.title, p.image_url, p.sale_price, p.price, p.subtitle FROM product_related pr JOIN products p ON p.id = pr.related_product_id WHERE pr.product_id = ? LIMIT 4');
$relStmt->execute([$id]);
$related = $relStmt->fetchAll();

$isFreeShippingGift = in_array($id, $freeShippingIds, true);

// POST logika (Įdėjimas į krepšelį)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    
    // Norų sąrašas
    if (($_POST['action'] ?? '') === 'wishlist') {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login.php');
            exit;
        }
        saveItemForUser($pdo, (int)$_SESSION['user_id'], 'product', $id);
        header('Location: /saved.php');
        exit;
    }

    $qty = max(1, (int) ($_POST['quantity'] ?? 1));
    $postedVariations = $_POST['variations'] ?? [];
    $cartVariations = [];
    $canAddToCart = true;
    
    // Jei senas formatas
    if (isset($_POST['variation_id']) && !empty($_POST['variation_id'])) {
        $postedVariations['default'] = $_POST['variation_id'];
    }

    // 1. Variacijų TIKRINIMAS (Backend Validation)
    if (!empty($groupedVariations)) {
        // Tikriname ar visos privalomos grupės pasirinktos
        foreach ($groupedVariations as $grpName => $vars) {
            if (empty($postedVariations[$grpName])) {
                $error = "Pasirinkite: " . htmlspecialchars($grpName);
                $canAddToCart = false;
                break;
            }
        }

        if ($canAddToCart) {
            foreach ($postedVariations as $group => $varId) {
                $varId = (int)$varId;
                if ($varId && isset($variationMap[$varId])) {
                    $sel = $variationMap[$varId];
                    
                    // LIKUČIO TIKRINIMAS
                    // Jei track_stock == 1, privalome tikrinti kiekį.
                    // Jei track_stock == 0, leidžiame pirkti bet kiek.
                    if ((int)$sel['track_stock'] === 1 && (int)$sel['quantity'] < $qty) {
                        $error = "Atsiprašome, pasirinkimo '" . htmlspecialchars($sel['name']) . "' šiuo metu neturime pakankamai.";
                        $canAddToCart = false;
                        break;
                    }

                    $cartVariations[] = [
                        'id' => $varId,
                        'group' => $sel['group_name'],
                        'name' => $sel['name'],
                        'delta' => (float)$sel['price_delta'],
                    ];
                }
            }
        }
    } else {
        // Paprasta prekė (be variacijų)
        if ((int)$product['quantity'] < $qty) {
            $error = "Atsiprašome, prekė išparduota arba neturime pageidaujamo kiekio.";
            $canAddToCart = false;
        }
    }

    if ($canAddToCart) {
        usort($cartVariations, function($a, $b) {
            return $a['id'] <=> $b['id'];
        });

        $variationSignature = !empty($cartVariations) ? md5(json_encode($cartVariations)) : 'default';
        $cartKey = $id . '_' . $variationSignature;

        $_SESSION['cart'][$cartKey] = ($_SESSION['cart'][$cartKey] ?? 0) + $qty;
        
        if (!empty($cartVariations)) {
            $_SESSION['cart_variations'][$cartKey] = $cartVariations; 
        }

        header('Location: /cart.php');
        exit;
    }
}

// Kainos
$categoryDiscounts = getCategoryDiscounts($pdo);
$globalDiscount = getGlobalDiscount($pdo);
$productCategoryDiscount = null;
if (!empty($product['category_id'])) {
    $productCategoryDiscount = $categoryDiscounts[(int)$product['category_id']] ?? null;
}
$priceDisplay = buildPriceDisplay($product, $globalDiscount, $categoryDiscounts);

// SEO
$meta = [
    'title' => $product['title'] . ' | Cukrinukas',
    'description' => mb_substr(strip_tags($product['description']), 0, 160),
    'image' => 'https://cukrinukas.lt' . $product['image_url']
];
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php echo headerStyles(); ?>
  <style>
    :root {
      --bg: #f8fafc;
      --card-bg: #ffffff;
      --border: #e2e8f0;
      --text-main: #0f172a;
      --text-muted: #64748b;
      --accent: #2563eb;
      --accent-hover: #1d4ed8;
      --accent-light: #eff6ff;
      --success: #059669;
      --danger: #ef4444;
    }
    
    * { box-sizing: border-box; }
    body { margin:0; background: var(--bg); color: var(--text-main); font-family: 'Inter', sans-serif; }
    a { color:inherit; text-decoration:none; transition: color 0.2s; }
    
    .page-container { max-width: 1200px; margin: 0 auto; padding: 0 20px 60px; }

    /* Hero */
    .hero {
        background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        border: 1px solid #bfdbfe;
        border-radius: 20px;
        padding: 32px;
        margin-top: 24px;
        margin-bottom: 32px;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .breadcrumbs { display:flex; align-items:center; gap:8px; font-weight:500; font-size: 13px; color: #3b82f6; flex-wrap: wrap; }
    .breadcrumbs a:hover { text-decoration: underline; }
    .breadcrumbs span { color: #93c5fd; }
    
    .hero h1 { margin: 0; font-size: 32px; color: #1e3a8a; letter-spacing: -0.02em; line-height: 1.2; }

    /* Layout */
    .product-grid { display: grid; grid-template-columns: 1fr 400px; gap: 32px; align-items: start; }
    .left-col { display: flex; flex-direction: column; gap: 24px; }
    
    /* Gallery */
    .gallery-section { display: flex; flex-direction: column; gap: 16px; }
    .main-image-wrap { 
        position: relative; border-radius: 16px; overflow: hidden; 
        background: #fff; border: 1px solid var(--border);
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
    }
    .main-image-wrap img { width: 100%; height: auto; display: block; object-fit: contain; max-height: 600px; }
    .ribbon { 
        position: absolute; top: 16px; left: 16px; 
        background: var(--accent); color: #fff; 
        padding: 6px 12px; border-radius: 8px; 
        font-weight: 700; font-size: 13px; 
        box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3);
    }
    .thumbs { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 4px; }
    .thumb { 
        width: 70px; height: 70px; flex-shrink: 0; border-radius: 8px; 
        border: 2px solid transparent; cursor: pointer; object-fit: cover; 
        background: #fff; transition: all 0.2s;
    }
    .thumb:hover { transform: translateY(-2px); }
    .thumb.active { border-color: var(--accent); }

    /* Cards */
    .content-card {
        background: var(--card-bg); border: 1px solid var(--border);
        border-radius: 16px; padding: 24px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .content-card h3 { margin: 0 0 16px 0; font-size: 18px; color: var(--text-main); border-bottom: 1px solid var(--border); padding-bottom: 12px; }
    .description { color: var(--text-muted); line-height: 1.7; font-size: 15px; }
    .description img { max-width: 100%; height: auto; border-radius: 8px; }

    .specs-list { display: flex; flex-direction: column; }
    .spec-item { 
        padding: 12px 0; 
        border-bottom: 1px solid var(--border); 
        font-size: 14px; 
        line-height: 1.6;
        color: var(--text-muted);
    }
    .spec-item:last-child { border-bottom: none; }
    .spec-value { text-align: left; width: 100%; color: var(--text-muted); }

    /* Buy Box */
    .buy-box {
        background: var(--card-bg); border: 1px solid var(--border);
        border-radius: 16px; padding: 24px;
        position: sticky; top: 24px;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
        display: flex; flex-direction: column; gap: 20px;
    }
    
    .price-area { display: flex; align-items: baseline; gap: 12px; margin-bottom: 8px; }
    .price-current { font-size: 36px; font-weight: 800; color: var(--text-main); letter-spacing: -0.02em; }
    .price-old { font-size: 18px; color: #94a3b8; text-decoration: line-through; }
    
    /* Variations */
    .var-group { margin-bottom: 16px; }
    .var-label { font-size: 13px; font-weight: 700; color: var(--text-main); margin-bottom: 8px; display: block; text-transform: uppercase; letter-spacing: 0.03em; }
    .var-options { display: flex; flex-wrap: wrap; gap: 8px; }
    
    .var-chip {
        border: 1px solid var(--border);
        background: #fff;
        padding: 8px 14px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        display: flex; align-items: center; gap: 6px;
        position: relative;
    }
    .var-chip:hover { border-color: #cbd5e1; background: #f8fafc; }
    .var-chip.active {
        border-color: var(--accent);
        background: var(--accent-light);
        color: var(--accent);
        box-shadow: 0 0 0 1px var(--accent);
    }
    
    /* Stilius išparduotoms variacijoms */
    .var-chip.out-of-stock {
        opacity: 0.6;
        background: #f1f5f9;
        border-style: dashed;
        cursor: not-allowed;
        color: #94a3b8;
    }
    .var-chip.out-of-stock:hover {
        border-color: var(--border);
        background: #f1f5f9;
    }
    .var-price { font-size: 11px; opacity: 0.8; font-weight: 400; }

    /* Actions */
    .action-row { display: grid; grid-template-columns: 80px 1fr; gap: 12px; margin-top: 8px; }
    .qty-input { width: 100%; height: 48px; text-align: center; font-size: 18px; font-weight: 600; border: 1px solid var(--border); border-radius: 10px; background: #f8fafc; }
    .btn-add { width: 100%; height: 48px; border: none; border-radius: 10px; background: var(--accent); color: #fff; font-size: 16px; font-weight: 600; cursor: pointer; transition: background 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; }
    .btn-add:hover { background: var(--accent-hover); }
    .btn-add:disabled { background: #cbd5e1; cursor: not-allowed; }

    .error-msg { background: #fef2f2; color: #991b1b; padding: 12px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; border: 1px solid #fecaca; }

    .info-list { display: flex; flex-direction: column; gap: 10px; font-size: 13px; color: var(--text-muted); margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); }
    .info-item { display: flex; align-items: center; gap: 8px; }

    /* Related */
    .related-section { margin-top: 60px; }
    .related-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px; margin-top: 20px; }
    .rel-card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 12px; transition: transform 0.2s; }
    .rel-card:hover { transform: translateY(-4px); box-shadow: 0 10px 20px -5px rgba(0,0,0,0.1); }
    .rel-img { width: 100%; aspect-ratio: 1; object-fit: contain; border-radius: 8px; margin-bottom: 10px; }
    .rel-title { font-weight: 600; font-size: 14px; margin-bottom: 4px; color: var(--text-main); }
    .rel-price { font-weight: 700; color: var(--text-main); }

    @media (max-width: 900px) {
        .product-grid { display: flex; flex-direction: column; gap: 24px; }
        .left-col { display: contents; }
        .content-card { width: 100%; }
        .buy-box { width: 100%; }
    }
  </style>
</head>
<body>
  <?php renderHeader($pdo, 'product', $meta); ?>
  
  <div class="page-container">
    
    <div class="hero">
        <div class="breadcrumbs">
            <a href="/">Pradžia</a> <span>/</span>
            <a href="/products.php">Parduotuvė</a>
            <?php if (!empty($product['category_name'])): ?>
                <span>/</span> <a href="/products.php?category=<?php echo urlencode($product['category_slug'] ?? ''); ?>">
                    <?php echo htmlspecialchars($product['category_name']); ?>
                </a>
            <?php endif; ?>
        </div>

        <h1><?php echo htmlspecialchars($product['title']); ?></h1>
        <?php if (!empty($product['subtitle'])): ?>
            <p style="margin:0; color: #1e40af; font-size: 16px;"><?php echo htmlspecialchars($product['subtitle']); ?></p>
        <?php endif; ?>
    </div>

    <div class="product-grid">
        
        <div class="left-col">
            <div class="gallery-section">
                <?php $mainImage = $images[0]['path'] ?? $product['image_url']; ?>
                <div class="main-image-wrap">
                    <?php if (!empty($product['ribbon_text'])): ?>
                        <div class="ribbon"><?php echo htmlspecialchars($product['ribbon_text']); ?></div>
                    <?php endif; ?>
                    <img src="<?php echo htmlspecialchars($mainImage); ?>" alt="<?php echo htmlspecialchars($product['title']); ?>" id="mainImg">
                </div>
                
                <?php if (count($images) > 0): ?>
                    <div class="thumbs">
                        <?php foreach ($images as $img): ?>
                            <img src="<?php echo htmlspecialchars($img['path']); ?>" class="thumb <?php echo ($img['path'] === $mainImage) ? 'active' : ''; ?>" onclick="changeImage(this)">
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($product['description'])): ?>
                <div class="content-card content-desc">
                    <h3>Aprašymas</h3>
                    <div class="description">
                        <?php echo $product['description']; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($attributes): ?>
                <div class="content-card content-specs">
                    <h3>Techninė specifikacija</h3>
                    <div class="specs-list">
                        <?php foreach ($attributes as $attr): ?>
                            <div class="spec-item">
                                <div class="spec-value"><?php echo $attr['value']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <form method="post" class="buy-box" id="productForm">
            <?php echo csrfField(); ?>
            
            <?php if($error): ?>
                <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (!empty($_SESSION['is_admin'])): ?>
                <a href="/admin.php?view=products&edit=<?php echo $product['id']; ?>" style="font-size:12px; text-decoration:underline; color:red; text-align:right;">[Redaguoti prekę]</a>
            <?php endif; ?>

            <div>
                <div class="price-area">
                    <span id="price-old" class="price-old" style="display: <?php echo $priceDisplay['has_discount'] ? 'block' : 'none'; ?>;">
                        <?php echo number_format($priceDisplay['original'], 2); ?> €
                    </span>
                    <span id="price-current" class="price-current"><?php echo number_format($priceDisplay['current'], 2); ?> €</span>
                </div>
                
                <div id="stock-status" style="font-size:13px; font-weight:600;">
                    <?php if ($hasAnyStock): ?>
                        <span style="color:var(--success)">● Turime sandėlyje</span>
                    <?php else: ?>
                        <span style="color:var(--danger)">● Išparduota</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($groupedVariations): ?>
                <div id="variations-container">
                    <?php foreach ($groupedVariations as $groupName => $vars): ?>
                        <div class="var-group">
                            <label class="var-label"><?php echo htmlspecialchars($groupName); ?></label>
                            <input type="hidden" name="variations[<?php echo htmlspecialchars($groupName); ?>]" id="input-<?php echo md5($groupName); ?>" value="">
                            
                            <div class="var-options">
                                <?php foreach ($vars as $var): 
                                    $trackStock = (int)$var['track_stock'];
                                    $varQty = (int)$var['quantity'];
                                    
                                    // Jei sekam likutį IR kiekis <= 0 -> išparduota.
                                    // Jei track_stock = 0 -> laikome, kad yra.
                                    $isVarOutOfStock = ($trackStock === 1 && $varQty <= 0);
                                ?>
                                    <div class="var-chip <?php echo $isVarOutOfStock ? 'out-of-stock' : ''; ?>" 
                                         data-group="<?php echo md5($groupName); ?>" 
                                         data-id="<?php echo (int)$var['id']; ?>"
                                         data-delta="<?php echo (float)$var['price_delta']; ?>"
                                         data-track-stock="<?php echo $trackStock; ?>"
                                         data-quantity="<?php echo $varQty; ?>"
                                         data-image="<?php echo htmlspecialchars($var['variation_image'] ?? ''); ?>"
                                         onclick="selectVariation(this)">
                                        <?php echo htmlspecialchars($var['name']); ?>
                                        <?php if ((float)$var['price_delta'] != 0): ?>
                                            <span class="var-price">(<?php echo $var['price_delta'] > 0 ? '+' : ''; ?><?php echo number_format($var['price_delta'], 2); ?> €)</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($isFreeShippingGift): ?>
                <div style="background:#ecfdf5; padding:12px; border-radius:8px; border:1px solid #6ee7b7; color:#064e3b; font-size:13px; line-height:1.4;">
                    <strong>Nemokamas pristatymas! 🚚</strong><br>
                    Įsigiję šią prekę, gausite nemokamą pristatymą visam krepšeliui.
                </div>
            <?php endif; ?>

            <div class="action-row">
                <input type="number" id="qtyInput" name="quantity" value="1" min="1" class="qty-input">
                
                <button type="submit" id="addToCartBtn" class="btn-add" <?php echo ($hasAnyStock) ? '' : 'disabled'; ?>>
                    <?php echo ($hasAnyStock) ? 'Į krepšelį' : 'Išparduota'; ?>
                    <?php if($hasAnyStock): ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                    <?php endif; ?>
                </button>
            </div>
            
            <button type="submit" name="action" value="wishlist" style="background:none; border:none; color:var(--text-muted); font-size:13px; cursor:pointer; text-decoration:underline; margin-top:8px;">
                Pridėti į norų sąrašą
            </button>

            <div class="info-list">
                <div class="info-item"><span>🔄</span> 14 dienų grąžinimo garantija</div>
                <div class="info-item"><span>🛡️</span> 24 mėn. kokybės garantija</div>
                <div class="info-item"><span>🚀</span> Pristatymas per 1-3 d.d.</div>
            </div>
        </form>
    </div>

    <?php if ($related): ?>
        <div class="related-section">
            <h3 style="font-size:24px; color:var(--text-main);">Taip pat gali patikti</h3>
            <div class="related-grid">
                <?php foreach ($related as $rel): 
                    $relDisplay = buildPriceDisplay($rel, $globalDiscount, $categoryDiscounts);
                    $relUrl = '/produktas/' . slugify($rel['title']) . '-' . (int)$rel['related_product_id'];
                ?>
                    <a href="<?php echo htmlspecialchars($relUrl); ?>" class="rel-card">
                        <img src="<?php echo htmlspecialchars($rel['image_url']); ?>" class="rel-img">
                        <div class="rel-title"><?php echo htmlspecialchars($rel['title']); ?></div>
                        <div class="rel-price">
                            <?php if($relDisplay['has_discount']): ?>
                                <span style="font-weight:400; color:#94a3b8; text-decoration:line-through; font-size:13px;"><?php echo number_format($relDisplay['original'], 2); ?> €</span>
                            <?php endif; ?>
                            <?php echo number_format($relDisplay['current'], 2); ?> €
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

  </div>

  <?php renderFooter($pdo); ?>

  <script>
    function changeImage(thumb) {
        document.getElementById('mainImg').src = thumb.src;
        document.querySelectorAll('.thumb').forEach(t => t.classList.remove('active'));
        thumb.classList.add('active');
    }

    const baseOriginal = parseFloat('<?php echo (float)($product['price'] ?? 0); ?>');
    const baseSale = <?php echo $product['sale_price'] !== null ? 'parseFloat(' . json_encode((float)$product['sale_price']) . ')' : 'null'; ?>;
    
    // Čia tik pradinė reikšmė (jei nėra variacijų)
    const initialHasStock = <?php echo json_encode($hasAnyStock); ?>;
    
    const globalDiscount = {
        type: '<?php echo $globalDiscount['type'] ?? 'none'; ?>',
        value: parseFloat('<?php echo (float)($globalDiscount['value'] ?? 0); ?>')
    };
    const categoryDiscount = {
        type: '<?php echo $productCategoryDiscount['type'] ?? 'none'; ?>',
        value: parseFloat('<?php echo (float)($productCategoryDiscount['value'] ?? 0); ?>')
    };

    function applyDiscounts(amount) {
        let final = amount;
        if (globalDiscount.type === 'percent') final -= final * (globalDiscount.value / 100);
        else if (globalDiscount.type === 'amount') final -= globalDiscount.value;
        if (categoryDiscount.type === 'percent') final -= final * (categoryDiscount.value / 100);
        else if (categoryDiscount.type === 'amount') final -= categoryDiscount.value;
        return Math.max(0, final);
    }

    const selectedDeltas = {}; 
    const originalMainImage = document.getElementById('mainImg') ? document.getElementById('mainImg').src : '';

    function updatePrice() {
        let totalDelta = 0;
        Object.values(selectedDeltas).forEach(d => totalDelta += d);

        const originalBase = baseOriginal + totalDelta;
        const saleBase = (baseSale !== null ? baseSale : baseOriginal) + totalDelta;
        
        const finalPrice = applyDiscounts(saleBase);
        const hasDiscount = (baseSale !== null) || (finalPrice < originalBase);

        document.getElementById('price-current').textContent = finalPrice.toFixed(2) + ' €';
        const oldEl = document.getElementById('price-old');
        if (hasDiscount) {
            oldEl.style.display = 'block';
            oldEl.textContent = originalBase.toFixed(2) + ' €';
        } else {
            oldEl.style.display = 'none';
        }
    }

    function selectVariation(el) {
        const groupHash = el.dataset.group;
        const varId = el.dataset.id;
        const delta = parseFloat(el.dataset.delta || 0);
        const imageSrc = el.dataset.image;
        
        // Nauji duomenys
        const trackStock = parseInt(el.dataset.trackStock || 0); // 0 arba 1
        const stockQty = parseInt(el.dataset.quantity || 0);

        document.querySelectorAll(`.var-chip[data-group="${groupHash}"]`).forEach(c => c.classList.remove('active'));
        el.classList.add('active');

        document.getElementById('input-' + groupHash).value = varId;
        selectedDeltas[groupHash] = delta;
        
        if (imageSrc && imageSrc !== '') {
            document.getElementById('mainImg').src = imageSrc;
        }

        updatePrice();
        
        // Atnaujinam mygtuką ir statusą
        updateStockUI(trackStock, stockQty);
    }

    function updateStockUI(trackStock, qty) {
        const statusDiv = document.getElementById('stock-status');
        const btn = document.getElementById('addToCartBtn');
        const qtyInput = document.getElementById('qtyInput');

        // Logika:
        // Jei trackStock === 0, tai yra neribota ("Turime sandėlyje")
        // Jei trackStock === 1, tikriname ar qty > 0
        
        const isUnlimited = (trackStock === 0);
        const inStock = isUnlimited || (qty > 0);

        if (inStock) {
            statusDiv.innerHTML = `<span style="color:var(--success)">● Turime sandėlyje</span>`;
            btn.disabled = false;
            btn.textContent = 'Į krepšelį';
            btn.style.cursor = 'pointer';
            
            // Jei neribota, leidžiam daug, jei ribota - max = likutis
            if (isUnlimited) {
                qtyInput.removeAttribute('max');
            } else {
                qtyInput.max = qty;
                if (parseInt(qtyInput.value) > qty) {
                    qtyInput.value = qty;
                }
            }
        } else {
            statusDiv.innerHTML = `<span style="color:var(--danger)">● Išparduota</span>`;
            btn.disabled = true;
            btn.textContent = 'Išparduota';
            btn.style.cursor = 'not-allowed';
            qtyInput.max = 0; // Kad negalėtų didinti
        }
    }
  </script>
</body>
</html>
