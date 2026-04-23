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

// Automatinis atsiliepimų lentelės sukūrimas, jei jos nėra
$pdo->exec("
    CREATE TABLE IF NOT EXISTS product_reviews (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        user_id INT NOT NULL,
        rating INT NOT NULL DEFAULT 5,
        comment TEXT NOT NULL,
        admin_reply TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_prod (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

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
$hasAnyStock = false;

if (!empty($variations)) {
    foreach ($variations as $var) {
        $group = $var['group_name'] ?: 'Pasirinkimas';
        $groupedVariations[$group][] = $var;
        $variationMap[(int)$var['id']] = $var;
        
        if ((int)$var['track_stock'] === 0 || (int)$var['quantity'] > 0) {
            $hasAnyStock = true;
        }
    }
} else {
    $hasAnyStock = ($product['quantity'] > 0);
}

// Susijusios prekės
$relStmt = $pdo->prepare('SELECT pr.related_product_id, p.title, p.image_url, p.sale_price, p.price, p.subtitle FROM product_related pr JOIN products p ON p.id = pr.related_product_id WHERE pr.product_id = ? LIMIT 4');
$relStmt->execute([$id]);
$related = $relStmt->fetchAll();

$isFreeShippingGift = in_array($id, $freeShippingIds, true);

// POST logika (Krepšelis, atsiliepimai, norų sąrašas)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'wishlist') {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login.php');
            exit;
        }
        saveItemForUser($pdo, (int)$_SESSION['user_id'], 'product', $id);
        header('Location: /saved.php');
        exit;
    } elseif ($action === 'add_review') {
        if (empty($_SESSION['user_id'])) {
            $error = "Turite prisijungti, kad paliktumėte atsiliepimą.";
        } else {
            $rating = max(1, min(5, (int)($_POST['rating'] ?? 5)));
            $comment = trim($_POST['comment'] ?? '');
            if (empty($comment)) {
                $error = "Atsiliepimo tekstas negali būti tuščias.";
            } else {
                $stmtRev = $pdo->prepare("INSERT INTO product_reviews (product_id, user_id, rating, comment) VALUES (?, ?, ?, ?)");
                $stmtRev->execute([$id, $_SESSION['user_id'], $rating, $comment]);
                header("Location: " . $_SERVER['REQUEST_URI'] . "#reviews");
                exit;
            }
        }
    } elseif ($action === 'admin_reply' && !empty($_SESSION['is_admin'])) {
        $reviewId = (int)($_POST['review_id'] ?? 0);
        $reply = trim($_POST['admin_reply'] ?? '');
        $stmtRep = $pdo->prepare("UPDATE product_reviews SET admin_reply = ? WHERE id = ? AND product_id = ?");
        $stmtRep->execute([$reply !== '' ? $reply : null, $reviewId, $id]);
        header("Location: " . $_SERVER['REQUEST_URI'] . "#reviews");
        exit;
    } else {
        // Įdėjimas į krepšelį
        $qty = max(1, (int) ($_POST['quantity'] ?? 1));
        $postedVariations = $_POST['variations'] ?? [];
        $cartVariations = [];
        $canAddToCart = true;
        
        if (isset($_POST['variation_id']) && !empty($_POST['variation_id'])) {
            $postedVariations['default'] = $_POST['variation_id'];
        }

        if (!empty($groupedVariations)) {
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
            if ((int)$product['quantity'] < $qty) {
                $error = "Atsiprašome, prekė išparduota arba neturime pageidaujamo kiekio.";
                $canAddToCart = false;
            }
        }

        if ($canAddToCart && empty($error)) {
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
}

// Atsiliepimų užklausa
$revStmt = $pdo->prepare('
    SELECT r.*, (SELECT email FROM users WHERE id = r.user_id LIMIT 1) as user_email
    FROM product_reviews r 
    WHERE r.product_id = ? 
    ORDER BY r.created_at DESC
');
$revStmt->execute([$id]);
$reviews = $revStmt->fetchAll();

$avgRating = 0;
$reviewCount = count($reviews);
if ($reviewCount > 0) {
    $sum = 0;
    foreach($reviews as $rev) {
        $sum += (int)$rev['rating'];
    }
    $avgRating = round($sum / $reviewCount, 1);
}

// Kainos
$categoryDiscounts = getCategoryDiscounts($pdo);
$globalDiscount = getGlobalDiscount($pdo);
$productCategoryDiscount = null;
if (!empty($product['category_id'])) {
    $productCategoryDiscount = $categoryDiscounts[(int)$product['category_id']] ?? null;
}
$priceDisplay = buildPriceDisplay($product, $globalDiscount, $categoryDiscounts);

// SEO Optimizacija
$cleanDescription = trim(preg_replace('/\s+/', ' ', strip_tags($product['description'])));
$canonicalUrl = 'https://cukrinukas.lt/produktas/' . slugify($product['title']) . '-' . $id;

$meta = [
    'title' => $product['title'] . ' | Cukrinukas',
    'description' => mb_substr($cleanDescription, 0, 160),
    'image' => 'https://cukrinukas.lt' . $product['image_url'],
    'keywords' => $product['meta_tags'] ?? '',
    'url' => $canonicalUrl
];
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php echo headerStyles(); ?>
  
  <script type="application/ld+json">
  {
    "@context": "https://schema.org/",
    "@type": "Product",
    "name": "<?php echo htmlspecialchars($product['title']); ?>",
    "image": [
      "https://cukrinukas.lt<?php echo htmlspecialchars($product['image_url']); ?>"
    ],
    "description": "<?php echo htmlspecialchars($cleanDescription); ?>",
    "sku": "<?php echo $id; ?>",
    "brand": {
      "@type": "Brand",
      "name": "Cukrinukas"
    },
    "offers": {
      "@type": "Offer",
      "url": "<?php echo $canonicalUrl; ?>",
      "priceCurrency": "EUR",
      "price": "<?php echo number_format($priceDisplay['current'], 2, '.', ''); ?>",
      "availability": "<?php echo $hasAnyStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock'; ?>",
      "hasMerchantReturnPolicy": {
        "@type": "MerchantReturnPolicy",
        "applicableCountry": "LT",
        "returnPolicyCategory": "https://schema.org/MerchantReturnFiniteReturnWindow",
        "merchantReturnDays": 14,
        "returnMethod": "https://schema.org/ReturnByMail",
        "returnFees": "https://schema.org/ReturnShippingFees"
      },
      "shippingDetails": {
        "@type": "OfferShippingDetails",
        "shippingDestination": {
          "@type": "DefinedRegion",
          "addressCountry": "LT"
        },
        "deliveryTime": {
          "@type": "ShippingDeliveryTime",
          "handlingTime": {
            "@type": "QuantitativeValue",
            "minValue": 0,
            "maxValue": 1,
            "unitCode": "d"
          },
          "transitTime": {
            "@type": "QuantitativeValue",
            "minValue": 1,
            "maxValue": 3,
            "unitCode": "d"
          }
        }
      }
    }
    <?php if ($reviewCount > 0): ?>
    ,
    "aggregateRating": {
      "@type": "AggregateRating",
      "ratingValue": "<?php echo number_format($avgRating, 1, '.', ''); ?>",
      "reviewCount": "<?php echo $reviewCount; ?>"
    },
    "review": [
      <?php foreach($reviews as $i => $rev): 
          $revAuthor = 'Pirkėjas';
          if (!empty($rev['user_email'])) {
              $revAuthor = ucfirst(explode('@', $rev['user_email'])[0]);
          }
      ?>
      {
        "@type": "Review",
        "author": {
          "@type": "Person",
          "name": "<?php echo htmlspecialchars($revAuthor); ?>"
        },
        "datePublished": "<?php echo date('Y-m-d', strtotime($rev['created_at'])); ?>",
        "reviewRating": {
          "@type": "Rating",
          "ratingValue": "<?php echo $rev['rating']; ?>",
          "bestRating": "5"
        },
        "reviewBody": "<?php echo htmlspecialchars(strip_tags($rev['comment'])); ?>"
      }<?php echo ($i < $reviewCount - 1) ? ',' : ''; ?>
      <?php endforeach; ?>
    ]
    <?php endif; ?>
  }
  </script>

  <style>
    :root {
      --bg: #f8fafc; --card-bg: #ffffff; --border: #e2e8f0; --text-main: #0f172a;
      --text-muted: #64748b; --accent: #2563eb; --accent-hover: #1d4ed8;
      --accent-light: #eff6ff; --success: #059669; --danger: #ef4444;
    }
    * { box-sizing: border-box; }
    body { margin:0; background: var(--bg); color: var(--text-main); font-family: 'Inter', sans-serif; }
    a { color:inherit; text-decoration:none; transition: color 0.2s; }
    .page-container { max-width: 1200px; margin: 0 auto; padding: 0 20px 60px; }
    .hero { background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border: 1px solid #bfdbfe; border-radius: 20px; padding: 32px; margin-top: 24px; margin-bottom: 32px; display: flex; flex-direction: column; gap: 12px; }
    .breadcrumbs { display:flex; align-items:center; gap:8px; font-weight:500; font-size: 13px; color: #3b82f6; flex-wrap: wrap; }
    .breadcrumbs a:hover { text-decoration: underline; }
    .breadcrumbs span { color: #93c5fd; }
    .hero h1 { margin: 0; font-size: 32px; color: #1e3a8a; letter-spacing: -0.02em; line-height: 1.2; }
    .product-grid { display: grid; grid-template-columns: 1fr 400px; gap: 32px; align-items: start; }
    .left-col { display: flex; flex-direction: column; gap: 24px; }
    .gallery-section { display: flex; flex-direction: column; gap: 16px; }
    .main-image-wrap { position: relative; border-radius: 16px; overflow: hidden; background: #fff; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
    .main-image-wrap img { width: 100%; height: auto; display: block; object-fit: contain; max-height: 600px; }
    .ribbon { position: absolute; top: 16px; left: 16px; background: var(--accent); color: #fff; padding: 6px 12px; border-radius: 8px; font-weight: 700; font-size: 13px; box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3); }
    .thumbs { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 4px; }
    .thumb { width: 70px; height: 70px; flex-shrink: 0; border-radius: 8px; border: 2px solid transparent; cursor: pointer; object-fit: cover; background: #fff; transition: all 0.2s; }
    .thumb:hover { transform: translateY(-2px); }
    .thumb.active { border-color: var(--accent); }
    .content-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 16px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .content-card h3 { margin: 0 0 16px 0; font-size: 18px; color: var(--text-main); border-bottom: 1px solid var(--border); padding-bottom: 12px; }
    .description { color: var(--text-muted); line-height: 1.7; font-size: 15px; }
    .description img { max-width: 100%; height: auto; border-radius: 8px; }
    .specs-list { display: flex; flex-direction: column; }
    .spec-item { padding: 12px 0; border-bottom: 1px solid var(--border); font-size: 14px; line-height: 1.6; color: var(--text-muted); }
    .spec-item:last-child { border-bottom: none; }
    .spec-value { text-align: left; width: 100%; color: var(--text-muted); }
    .buy-box { background: var(--card-bg); border: 1px solid var(--border); border-radius: 16px; padding: 24px; position: sticky; top: 24px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05); display: flex; flex-direction: column; gap: 20px; }
    .price-area { display: flex; align-items: baseline; gap: 12px; margin-bottom: 8px; }
    .price-current { font-size: 36px; font-weight: 800; color: var(--text-main); letter-spacing: -0.02em; }
    .price-old { font-size: 18px; color: #94a3b8; text-decoration: line-through; }
    .var-group { margin-bottom: 16px; }
    .var-label { font-size: 13px; font-weight: 700; color: var(--text-main); margin-bottom: 8px; display: block; text-transform: uppercase; letter-spacing: 0.03em; }
    .var-options { display: flex; flex-wrap: wrap; gap: 8px; }
    .var-chip { border: 1px solid var(--border); background: #fff; padding: 8px 14px; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 6px; position: relative; }
    .var-chip:hover { border-color: #cbd5e1; background: #f8fafc; }
    .var-chip.active { border-color: var(--accent); background: var(--accent-light); color: var(--accent); box-shadow: 0 0 0 1px var(--accent); }
    .var-chip.out-of-stock { opacity: 0.6; background: #f1f5f9; border-style: dashed; cursor: not-allowed; color: #94a3b8; }
    .var-chip.out-of-stock:hover { border-color: var(--border); background: #f1f5f9; }
    .var-price { font-size: 11px; opacity: 0.8; font-weight: 400; }
    .action-row { display: grid; grid-template-columns: 80px 1fr; gap: 12px; margin-top: 8px; }
    .qty-input { width: 100%; height: 48px; text-align: center; font-size: 18px; font-weight: 600; border: 1px solid var(--border); border-radius: 10px; background: #f8fafc; }
    .btn-add { width: 100%; height: 48px; border: none; border-radius: 10px; background: var(--accent); color: #fff; font-size: 16px; font-weight: 600; cursor: pointer; transition: background 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; }
    .btn-add:hover { background: var(--accent-hover); }
    .btn-add:disabled { background: #cbd5e1; cursor: not-allowed; }
    .error-msg { background: #fef2f2; color: #991b1b; padding: 12px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; border: 1px solid #fecaca; }
    .info-list { display: flex; flex-direction: column; gap: 10px; font-size: 13px; color: var(--text-muted); margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); }
    .info-item { display: flex; align-items: center; gap: 8px; }
    .related-section { margin-top: 60px; }
    .related-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px; margin-top: 20px; }
    .rel-card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 12px; transition: transform 0.2s; }
    .rel-card:hover { transform: translateY(-4px); box-shadow: 0 10px 20px -5px rgba(0,0,0,0.1); }
    .rel-img { width: 100%; aspect-ratio: 1; object-fit: contain; border-radius: 8px; margin-bottom: 10px; }
    .rel-title { font-weight: 600; font-size: 14px; margin-bottom: 4px; color: var(--text-main); }
    .rel-price { font-weight: 700; color: var(--text-main); }
    
    /* Atsiliepimų stiliai */
    .reviews-section { margin-top: 40px; background: var(--card-bg); border: 1px solid var(--border); border-radius: 16px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .review-item { border-bottom: 1px solid var(--border); padding: 20px 0; }
    .review-item:last-child { border-bottom: none; }
    .review-header { display: flex; justify-content: space-between; margin-bottom: 8px; }
    .review-author { font-weight: 600; color: var(--text-main); }
    .review-date { font-size: 12px; color: var(--text-muted); }
    .stars { color: #fbbf24; font-size: 16px; letter-spacing: 2px; }
    .review-comment { color: var(--text-main); line-height: 1.5; font-size: 15px; margin-top: 10px; }
    .admin-reply { background: #f8fafc; border-left: 3px solid var(--accent); padding: 14px 16px; margin-top: 14px; border-radius: 0 8px 8px 0; font-size: 14px; color: var(--text-muted); }
    .admin-reply-header { font-weight: 600; color: var(--accent); margin-bottom: 6px; display: block; }
    .review-form { margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--border); }
    .review-form textarea { width: 100%; padding: 14px; border: 1px solid var(--border); border-radius: 8px; min-height: 100px; font-family: inherit; margin-bottom: 14px; font-size:15px; }
    .review-form select { padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 14px; font-size:15px; width: 100%; max-width:300px; }
    .reply-form { margin-top: 16px; display: flex; gap: 8px; }
    .reply-form input { flex: 1; padding: 10px; border: 1px solid var(--border); border-radius: 6px; font-size:14px; }
    .reply-form button { padding: 10px 16px; background: var(--text-main); color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size:14px; font-weight:600;}
    .login-prompt { background: var(--accent-light); color: var(--accent-hover); padding: 20px; border-radius: 8px; text-align: center; font-weight: 500; }
    .login-prompt a { text-decoration: underline; font-weight: 700; color: var(--accent); }

    @media (max-width: 900px) { .product-grid { display: flex; flex-direction: column; gap: 24px; } .left-col { display: contents; } .content-card { width: 100%; } .buy-box { width: 100%; } }
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
                        <?php foreach ($images as $index => $img): ?>
                            <img src="<?php echo htmlspecialchars($img['path']); ?>" alt="<?php echo htmlspecialchars($product['title'] . ' nuotrauka ' . ($index+1)); ?>" class="thumb <?php echo ($img['path'] === $mainImage) ? 'active' : ''; ?>" onclick="changeImage(this)">
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
                    
                    <?php if (!empty($product['meta_tags'])): ?>
                        <div style="margin-top: 15px; font-size: 13px; color: var(--text-muted);">
                            <strong>Žymės:</strong> 
                            <?php 
                                $tags = explode(',', $product['meta_tags']);
                                foreach($tags as $tag) {
                                    $tag = trim($tag);
                                    if($tag) {
                                        echo '<a href="/products.php?query=' . urlencode($tag) . '" style="color: var(--accent); text-decoration: underline; margin-right: 8px;">#' . htmlspecialchars($tag) . '</a>';
                                    }
                                }
                            ?>
                        </div>
                    <?php endif; ?>
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
                <a href="/admin/products.php?edit=<?php echo $product['id']; ?>" style="font-size:12px; text-decoration:underline; color:red; text-align:right;">[Redaguoti prekę]</a>
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
                <div class="info-item"><span>🛡️</span> Aukščiausios kokybės garantija</div>
                <div class="info-item"><span>🚀</span> Pristatymas per <?php echo htmlspecialchars($product['delivery_time'] ?? '1-3 d.d.'); ?></div>
            </div>
        </form>
    </div>

    <div class="reviews-section" id="reviews">
        <h2 style="margin-top:0; margin-bottom: 24px; font-size:24px;">Atsiliepimai (<?php echo $reviewCount; ?>) <?php if($reviewCount > 0) echo '<span style="color:#fbbf24;">⭐ ' . number_format($avgRating, 1) . '</span>'; ?></h2>
        
        <?php if ($reviewCount > 0): ?>
            <div class="reviews-list">
                <?php foreach($reviews as $rev): 
                    $revAuthor = 'Pirkėjas';
                    if (!empty($rev['user_email'])) {
                        $revAuthor = ucfirst(explode('@', $rev['user_email'])[0]);
                    }
                    $stars = str_repeat('★', (int)$rev['rating']) . str_repeat('☆', 5 - (int)$rev['rating']);
                ?>
                    <div class="review-item">
                        <div class="review-header">
                            <span class="review-author"><?php echo htmlspecialchars($revAuthor); ?></span>
                            <span class="review-date"><?php echo date('Y-m-d', strtotime($rev['created_at'])); ?></span>
                        </div>
                        <div class="stars"><?php echo $stars; ?></div>
                        <div class="review-comment"><?php echo nl2br(htmlspecialchars($rev['comment'])); ?></div>
                        
                        <?php if (!empty($rev['admin_reply'])): ?>
                            <div class="admin-reply">
                                <span class="admin-reply-header">Parduotuvės atsakymas:</span>
                                <?php echo nl2br(htmlspecialchars($rev['admin_reply'])); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($_SESSION['is_admin'])): ?>
                            <form method="post" class="reply-form">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="admin_reply">
                                <input type="hidden" name="review_id" value="<?php echo $rev['id']; ?>">
                                <input type="text" name="admin_reply" placeholder="Atsakyti į atsiliepimą..." value="<?php echo htmlspecialchars($rev['admin_reply'] ?? ''); ?>">
                                <button type="submit">Išsaugoti</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="color: var(--text-muted); font-size: 15px;">Kol kas atsiliepimų nėra. Būkite pirmas!</p>
        <?php endif; ?>

        <div class="review-form">
            <h3 style="margin-top:0;">Palikite atsiliepimą</h3>
            <?php if (!empty($_SESSION['user_id'])): ?>
                <form method="post">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="add_review">
                    <select name="rating" required>
                        <option value="5">⭐⭐⭐⭐⭐ - Puiku!</option>
                        <option value="4">⭐⭐⭐⭐ - Gerai</option>
                        <option value="3">⭐⭐⭐ - Vidutiniškai</option>
                        <option value="2">⭐⭐ - Prastai</option>
                        <option value="1">⭐ - Labai prastai</option>
                    </select>
                    <textarea name="comment" placeholder="Jūsų atsiliepimas apie prekę..." required></textarea>
                    <button type="submit" class="btn-add" style="width: auto; padding: 0 32px;">Siųsti atsiliepimą</button>
                </form>
            <?php else: ?>
                <div class="login-prompt">
                    Norėdami palikti atsiliepimą, prašome <a href="/login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>">prisijungti prie savo paskyros</a>.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($related): ?>
        <div class="related-section">
            <h2 style="font-size:24px; color:var(--text-main); margin-bottom: 20px;">Taip pat gali patikti</h2>
            <div class="related-grid">
                <?php foreach ($related as $rel): 
                    $relDisplay = buildPriceDisplay($rel, $globalDiscount, $categoryDiscounts);
                    $relUrl = '/produktas/' . slugify($rel['title']) . '-' . (int)$rel['related_product_id'];
                ?>
                    <a href="<?php echo htmlspecialchars($relUrl); ?>" class="rel-card">
                        <img src="<?php echo htmlspecialchars($rel['image_url']); ?>" class="rel-img" alt="<?php echo htmlspecialchars($rel['title']); ?>">
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
        
        const trackStock = parseInt(el.dataset.trackStock || 0); 
        const stockQty = parseInt(el.dataset.quantity || 0);

        document.querySelectorAll(`.var-chip[data-group="${groupHash}"]`).forEach(c => c.classList.remove('active'));
        el.classList.add('active');

        document.getElementById('input-' + groupHash).value = varId;
        selectedDeltas[groupHash] = delta;
        
        if (imageSrc && imageSrc !== '') {
            document.getElementById('mainImg').src = imageSrc;
        }

        updatePrice();
        updateStockUI(trackStock, stockQty);
    }

    function updateStockUI(trackStock, qty) {
        const statusDiv = document.getElementById('stock-status');
        const btn = document.getElementById('addToCartBtn');
        const qtyInput = document.getElementById('qtyInput');
        
        const isUnlimited = (trackStock === 0);
        const inStock = isUnlimited || (qty > 0);

        if (inStock) {
            statusDiv.innerHTML = `<span style="color:var(--success)">● Turime sandėlyje</span>`;
            btn.disabled = false;
            btn.textContent = 'Į krepšelį';
            btn.style.cursor = 'pointer';
            
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
            qtyInput.max = 0; 
        }
    }
  </script>
</body>
</html>
